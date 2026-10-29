<?php

namespace Tests\Feature;

use App\Models\QuestionAnswer;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\User;
use App\Services\QuestionContactService;
use App\Services\SurveySubmissionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class QuestionAnswerSeparationTest extends SurveyTestCase
{
    private function separatedSurvey(): Survey
    {
        $survey = $this->survey->duplicate();
        $questions = $survey->questions;
        $contact = [
            'prompt' => 'Atstāt kontaktinformāciju', 'purpose' => 'Saziņai par talku.', 'trigger_values' => ['yes'],
            'fields' => [['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true]],
        ];
        $questions[0]['contact'] = $contact;
        $questions[1]['contact'] = [...$contact, 'trigger_values' => ['a']];
        $survey->update(['questions' => $questions, 'status' => 'open']);

        return $survey;
    }

    private function assertSubmissionRejected(Survey $survey, array $payload, string $field, $invitation = null): void
    {
        try {
            app(SurveySubmissionService::class)->submit($survey, $payload, $invitation);
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_schema_has_no_main_response_family_contact_session_identity_or_timestamp(): void
    {
        $this->assertSame(['id', 'survey_id', 'question_key', 'answer'], Schema::getColumnListing('question_answers'));
        $this->assertSame(['id', 'survey_id', 'question_key', 'answer', 'details'], Schema::getColumnListing('question_contacts'));
        $this->assertSame(['id', 'survey_id', 'answers', 'respondent_details'], Schema::getColumnListing('survey_responses'));
        $this->assertSame(['id', 'invitation_id', 'role'], Schema::getColumnListing('family_participations'));
    }

    public function test_every_contact_question_answer_is_detached_without_contact_opt_in_and_other_opinions_stay_together(): void
    {
        $survey = $this->separatedSurvey();
        $payload = $this->payload(['q1' => 'yes', 'q2' => ['b', 'a'], 'q4' => 'Anonīms viedoklis'], [
            'contact_id' => (string) Str::uuid(), 'fields' => ['email' => 'ignored@example.com'],
            'question_answer_id' => (string) Str::uuid(),
        ]);
        $session = session()->all();
        $response = app(SurveySubmissionService::class)->submit($survey, $payload)->fresh();
        $this->assertSame(['q4' => ['value' => 'Anonīms viedoklis', 'other' => null]], $response->answers);
        $answers = $survey->questionAnswers()->get()->keyBy('question_key');
        $this->assertCount(2, $answers);
        $this->assertSame(['value' => 'yes', 'other' => null], $answers['q1']->answer);
        $this->assertSame(['value' => ['b', 'a'], 'other' => null], $answers['q2']->answer);
        $ids = [$response->id, $answers['q1']->id, $answers['q2']->id, $payload['contact_id'], $payload['question_answer_id']];
        $this->assertSame(count($ids), count(array_unique($ids)));
        foreach ($answers as $answer) {
            $this->assertTrue(Str::isUuid($answer->id));
            $this->assertFalse($answer->timestamps);
            $this->assertSame(['answer' => $answer->answer], $answer->load('survey')->toArray());
        }
        $this->assertSame($session, session()->all());
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('question_contacts', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_single_contact_question_only_can_produce_an_empty_main_answer_without_skipping_full_input_validation(): void
    {
        $survey = $this->separatedSurvey();
        $response = app(SurveySubmissionService::class)->submit($survey, $this->payload(['q1' => 'yes']));
        $this->assertSame([], $response->fresh()->answers);
        $this->assertDatabaseCount('question_answers', 1);
        $this->assertSubmissionRejected($survey, $this->payload([]), 'answers');
        $this->assertDatabaseCount('question_answers', 1);
        $this->assertDatabaseCount('survey_responses', 1);
    }

    public function test_nontrigger_and_other_answers_are_also_detached_but_skipped_questions_get_no_row(): void
    {
        $survey = $this->separatedSurvey();
        $response = app(SurveySubmissionService::class)->submit($survey, $this->payload(['q1' => 'no', 'q4' => 'Viedoklis']));
        $this->assertArrayNotHasKey('q1', $response->answers);
        $this->assertSame(['value' => 'no', 'other' => null], $survey->questionAnswers()->sole()->answer);
        $response = app(SurveySubmissionService::class)->submit($survey, $this->payload(['q1' => 'other'], ['other_answers' => ['q1' => 'Savs variants']]));
        $this->assertSame([], $response->answers);
        $this->assertSame(['value' => 'other', 'other' => 'Savs variants'], $survey->questionAnswers()->where('answer->value', 'other')->sole()->answer);
        $this->assertDatabaseCount('question_answers', 2);
    }

    public function test_conditional_visibility_still_prevents_hidden_contact_answers_from_being_persisted(): void
    {
        $draft = $this->separatedSurvey()->duplicate();
        $questions = $draft->questions;
        $questions[1]['condition'] = ['question' => 'q1', 'values' => ['yes']];
        $draft->update(['questions' => $questions, 'status' => 'open']);
        $response = app(SurveySubmissionService::class)->submit($draft, $this->payload(['q1' => 'no', 'q2' => ['a']]));
        $this->assertSame([], $response->answers);
        $this->assertSame('q1', $draft->questionAnswers()->sole()->question_key);
    }

    public function test_retry_returns_original_without_duplicating_detached_answers_or_contact_records(): void
    {
        $survey = $this->separatedSurvey();
        $payload = $this->payload(['q1' => 'yes', 'q2' => ['a'], 'q4' => 'Sākotnējais viedoklis']);
        $original = app(SurveySubmissionService::class)->submit($survey, $payload);
        $ids = $survey->questionAnswers()->pluck('id')->all();
        $retry = app(SurveySubmissionService::class)->submit($survey, [...$payload, 'answers' => ['q1' => 'no', 'q4' => 'Mainīts viedoklis']]);
        $this->assertSame($original->id, $retry->id);
        $this->assertSame($original->answers, $retry->answers);
        $this->assertSame($ids, $survey->questionAnswers()->pluck('id')->all());
        $this->assertSubmissionRejected($this->separatedSurvey(), $payload, 'submission_id');
        $this->assertDatabaseCount('question_answers', 2);
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('question_contacts', 0);
    }

    public function test_invalid_full_answers_or_expired_survey_do_not_partially_write_detached_answers(): void
    {
        $survey = $this->separatedSurvey();
        $this->assertSubmissionRejected($survey, $this->payload(['q1' => 'yes', 'q2' => ['invalid']]), 'answers.q2');
        $survey->update(['closes_at' => now()->subSecond()]);
        $this->assertSubmissionRejected($survey, $this->payload(['q1' => 'yes']), 'survey');
        $this->assertDatabaseCount('question_answers', 0);
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_database_transaction_rolls_back_detached_answers_if_main_response_insert_fails(): void
    {
        $survey = $this->separatedSurvey();
        $invitation = $this->invitation($survey);
        Event::listen('eloquent.creating: '.SurveyResponse::class, function () {
            throw new RuntimeException('Synthetic response insert failure');
        });
        try {
            app(SurveySubmissionService::class)->submit($survey, $this->payload(['q1' => 'yes', 'q2' => ['a']], ['role' => 'mother']), $invitation);
            $this->fail('Expected synthetic response insert failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic response insert failure', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.SurveyResponse::class);
        }
        $this->assertDatabaseCount('question_answers', 0);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_family_claims_stay_separate_and_role_conflict_rolls_back_new_detached_rows(): void
    {
        $survey = $this->separatedSurvey();
        $invitation = $this->invitation($survey);
        $payload = $this->payload(['q1' => 'yes', 'q4' => 'Ģimenes viedoklis'], ['role' => 'mother']);
        $original = app(SurveySubmissionService::class)->submit($survey, $payload, $invitation);
        app(SurveySubmissionService::class)->submit($survey, $payload, $invitation);
        $this->assertSubmissionRejected($survey, $this->payload(['q1' => 'no', 'q2' => ['a']], ['role' => 'mother']), 'role', $invitation);
        $this->assertDatabaseCount('question_answers', 1);
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 1);
        $this->assertSame(['q4' => ['value' => 'Ģimenes viedoklis', 'other' => null]], $original->answers);
        $this->assertNull($original->respondent_details);
        $this->assertNotSame($original->id, $survey->questionAnswers()->sole()->id);
    }

    public function test_contact_submission_creates_no_question_answer_and_uuid_is_unrelated_to_main_or_detached_rows(): void
    {
        $survey = $this->separatedSurvey();
        $main = app(SurveySubmissionService::class)->submit($survey, $this->payload(['q1' => 'yes', 'q4' => 'Anonīms viedoklis']));
        $answer = $survey->questionAnswers()->sole();
        $contactId = (string) Str::uuid();
        $contact = app(QuestionContactService::class)->submit($survey, 'q1', [
            'contact_id' => $contactId, 'consent' => true, 'answer' => 'yes', 'fields' => ['email' => 'contact@example.com'],
            'question_answer_id' => $answer->id, 'submission_id' => $main->id,
        ]);
        $this->assertSame(3, count(array_unique([$main->id, $answer->id, $contact->id])));
        $this->assertDatabaseCount('question_answers', 1);
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('question_contacts', 1);
        $this->assertStringNotContainsString($main->id, json_encode($contact->details, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($answer->id, json_encode($contact->details, JSON_THROW_ON_ERROR));
    }

    public function test_model_ignores_supplied_answer_uuid_and_scopes_anonymous_rows_by_school(): void
    {
        $provided = (string) Str::uuid();
        $answer = new QuestionAnswer(['survey_id' => $this->survey->id, 'question_key' => 'q1', 'answer' => ['value' => 'yes', 'other' => null]]);
        $answer->id = $provided;
        $answer->save();
        $this->assertNotSame($provided, $answer->id);
        $this->assertTrue(Str::isUuid($answer->id));
        $admin = new User;
        $admin->school_id = $this->school->id;
        $this->assertSame($answer->id, QuestionAnswer::query()->accessibleTo($admin)->sole()->id);
        $this->assertSame(0, QuestionAnswer::query()->forSchool($this->otherSurvey()->school_id)->count());
        $this->assertSame(0, QuestionAnswer::query()->accessibleTo(new User)->count());
    }
}
