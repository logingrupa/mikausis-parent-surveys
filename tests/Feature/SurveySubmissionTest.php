<?php

namespace Tests\Feature;

use App\Models\SurveyResponse;
use App\Services\SurveySubmissionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class SurveySubmissionTest extends SurveyTestCase
{
    public function test_valid_answers_are_normalized_without_identifying_metadata(): void
    {
        $payload = $this->payload(['q1' => 'yes', 'q2' => ['a', 'other'], 'q3' => '  Vairāk ziņu  ', 'q4' => 'Prasmes', 'unknown' => 'discard'], [
            'other_answers' => ['q2' => '  Mans variants  ', 'q1' => 'discard'],
            'role' => 'mother', 'ip' => '127.0.0.1', 'family_id' => 99,
        ]);
        $response = app(SurveySubmissionService::class)->submit($this->survey, $payload);

        $this->assertSame($payload['submission_id'], $response->id);
        $this->assertSame([
            'q1' => ['value' => 'yes', 'other' => null],
            'q2' => ['value' => ['a', 'other'], 'other' => 'Mans variants'],
            'q3' => ['value' => 'Vairāk ziņu', 'other' => null],
            'q4' => ['value' => 'Prasmes', 'other' => null],
        ], $response->fresh()->answers);
        $this->assertSame(['id', 'survey_id', 'answers', 'respondent_details'], array_keys($response->fresh()->getAttributes()));
        $this->assertNull($response->fresh()->respondent_details);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_forged_hidden_answers_are_discarded_before_type_validation(): void
    {
        $response = app(SurveySubmissionService::class)->submit($this->survey, $this->payload(['q1' => 'no', 'q3' => ['malicious' => ['nested']]]));
        $this->assertSame(['q1' => ['value' => 'no', 'other' => null]], $response->answers);
    }

    public static function malformedAnswers(): array
    {
        return [
            'invalid option' => [['q1' => 'invented'], [], 'answers.q1'],
            'single array' => [['q1' => ['yes']], [], 'answers.q1'],
            'multiple scalar' => [['q2' => 'a'], [], 'answers.q2'],
            'multiple nested' => [['q2' => [['a']]], [], 'answers.q2'],
            'multiple map' => [['q2' => ['key' => 'a']], [], 'answers.q2'],
            'multiple invalid' => [['q2' => ['invented']], [], 'answers.q2'],
            'multiple duplicates' => [['q2' => ['a', 'a']], [], 'answers.q2'],
            'exclusive options' => [['q2' => ['a', 'no']], [], 'answers.q2'],
            'text array' => [['q4' => ['not text']], [], 'answers.q4'],
            'long text' => [['q4' => str_repeat('ā', 2001)], [], 'answers.q4'],
            'other missing' => [['q1' => 'other'], [], 'other_answers.q1'],
            'other blank' => [['q2' => ['other']], ['q2' => '   '], 'other_answers.q2'],
            'other array' => [['q1' => 'other'], ['q1' => ['nested']], 'other_answers.q1'],
            'other long' => [['q1' => 'other'], ['q1' => str_repeat('a', 2001)], 'other_answers.q1'],
            'empty' => [[], [], 'answers'],
            'blank text' => [['q4' => '  '], [], 'answers'],
            'unicode blank text' => [['q4' => "\u{00A0}\u{200B}"], [], 'answers'],
            'hidden only' => [['q3' => 'Hidden'], [], 'answers'],
        ];
    }

    #[DataProvider('malformedAnswers')]
    public function test_invalid_submissions_leave_no_response_or_participation(array $answers, array $others, string $field): void
    {
        $invitation = $this->invitation();
        try {
            app(SurveySubmissionService::class)->submit($this->survey, $this->payload($answers, ['other_answers' => $others, 'role' => 'mother']), $invitation);
            $this->fail('Expected validation failure');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_required_visible_question_is_enforced_and_hidden_required_question_is_skipped(): void
    {
        $draft = $this->survey->duplicate();
        $questions = $draft->questions;
        $questions[2]['required'] = true;
        $draft->update(['questions' => $questions, 'status' => 'open']);
        app(SurveySubmissionService::class)->submit($draft, $this->payload(['q1' => 'no']));

        $this->expectException(ValidationException::class);
        app(SurveySubmissionService::class)->submit($draft, $this->payload(['q1' => 'yes']));
    }

    public function test_retry_returns_original_response_without_overwriting_answers(): void
    {
        $payload = $this->payload();
        $first = app(SurveySubmissionService::class)->submit($this->survey, $payload);
        $payload['answers'] = ['q1' => 'no'];
        $second = app(SurveySubmissionService::class)->submit($this->survey, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('yes', $second->answers['q1']['value']);
        $this->assertDatabaseCount('survey_responses', 1);
    }

    public function test_each_parent_role_can_submit_once_and_identical_retry_is_idempotent(): void
    {
        $invitation = $this->invitation();
        $payload = $this->payload(extra: ['role' => 'mother']);
        $service = app(SurveySubmissionService::class);
        $first = $service->submit($this->survey, $payload, $invitation);
        $this->assertSame($first->id, $service->submit($this->survey, $payload, $invitation)->id);
        $service->submit($this->survey, $this->payload(extra: ['role' => 'father']), $invitation);
        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertDatabaseCount('family_participations', 2);

        try {
            $service->submit($this->survey, $this->payload(extra: ['role' => 'mother']), $invitation);
            $this->fail('Duplicate role must be rejected');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('role', $exception->errors());
        }
        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertDatabaseCount('family_participations', 2);
    }

    public function test_invitation_from_other_survey_cannot_be_claimed(): void
    {
        $invitation = $this->invitation($this->otherSurvey());
        try {
            app(SurveySubmissionService::class)->submit($this->survey, $this->payload(extra: ['role' => 'guardian']), $invitation);
            $this->fail('Mismatched invitation must be rejected');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('invitation', $exception->errors());
        }
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_idempotency_key_cannot_be_reused_for_another_survey(): void
    {
        $payload = $this->payload();
        app(SurveySubmissionService::class)->submit($this->survey, $payload);
        $this->expectException(ValidationException::class);
        app(SurveySubmissionService::class)->submit($this->otherSurvey(), $payload);
    }

    public function test_response_insert_failure_rolls_back_the_family_role_claim(): void
    {
        $invitation = $this->invitation();
        DB::unprepared("CREATE TRIGGER reject_test_response BEFORE INSERT ON survey_responses BEGIN SELECT RAISE(ABORT, 'test insert failure'); END");
        try {
            app(SurveySubmissionService::class)->submit($this->survey, $this->payload(extra: ['role' => 'mother']), $invitation);
            $this->fail('The simulated response insert failure should propagate');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('test insert failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_test_response');
        }
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_database_unique_constraint_prevents_a_second_claim_for_the_same_role(): void
    {
        $invitation = $this->invitation();
        $invitation->participations()->create(['role' => 'guardian']);
        $this->expectException(QueryException::class);
        $invitation->participations()->create(['role' => 'guardian']);
    }

    public function test_invalid_role_and_non_uuid_submission_id_are_rejected_before_writes(): void
    {
        $invitation = $this->invitation();
        foreach ([['role' => 'child'], ['role' => ['mother']], ['role' => 'mother', 'submission_id' => 'not-a-uuid']] as $extra) {
            try {
                app(SurveySubmissionService::class)->submit($this->survey, $this->payload(extra: $extra), $invitation);
                $this->fail('Invalid metadata should be rejected');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
        }
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_closed_deadline_and_draft_surveys_reject_new_responses(): void
    {
        $service = app(SurveySubmissionService::class);
        foreach (['closed', 'expired', 'draft'] as $case) {
            $survey = $case === 'draft' ? $this->survey->duplicate() : $this->survey;
            if ($case === 'closed') {
                $survey->update(['status' => 'closed']);
            } elseif ($case === 'expired') {
                $survey->update(['status' => 'open', 'closes_at' => now()->subSecond()]);
            }
            try {
                $service->submit($survey, $this->payload());
                $this->fail('Unavailable survey accepted an answer');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('survey', $exception->errors());
            }
        }
        $this->assertSame(0, SurveyResponse::count());
    }
}
