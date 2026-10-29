<?php

namespace Tests\Feature;

use App\Filament\Support\SurveyEditorData;
use App\Filament\Support\SurveyWorkflow;
use App\Models\QuestionAnswer;
use App\Models\QuestionContact;
use App\Models\Survey;
use App\Models\User;
use App\Services\SurveySubmissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class PublishedSurveyEditTest extends SurveyTestCase
{
    private function administrator(): void
    {
        $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'is_admin' => true]));
    }

    private function assertInvalid(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected validation error: '.$field);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    private function contact(): array
    {
        return [
            'prompt' => 'Atstāt kontaktus', 'purpose' => 'Saziņai par pasākumu.', 'trigger_values' => ['yes'],
            'fields' => [['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true]],
        ];
    }

    private function contactSurvey(): Survey
    {
        $survey = $this->survey->duplicate();
        $questions = $survey->questions;
        $questions[0]['contact'] = $this->contact();
        $survey->update(['questions' => $questions, 'status' => 'open']);

        return $survey;
    }

    public function test_spelling_corrections_and_safe_option_additions_preserve_keys_and_existing_answers(): void
    {
        $this->administrator();
        $response = app(SurveySubmissionService::class)->submit($this->survey, $this->payload());
        $before = DB::table('survey_responses')->where('id', $response->id)->first();
        $publication = $this->survey->published_at;
        $state = SurveyEditorData::fromDefinition($this->survey->toArray());
        $state['builder']['title'] = 'Labots aptaujas nosaukums';
        $state['builder']['description'] = 'Labots ievads';
        $state['builder']['sections'][0]['title'] = 'Labota sadaļa';
        $state['builder']['questions'][0]['title'] = 'Labots jautājums';
        $state['builder']['questions'][0]['description'] = 'Labots paskaidrojums';
        $state['builder']['questions'][0]['required'] = true;
        $state['builder']['questions'][0]['options'][0]['label'] = 'Jā, piedalīšos';
        $state['builder']['questions'][0]['options'][] = ['value' => 'maybe', 'label' => 'Varbūt'];
        $saved = SurveyWorkflow::update($this->survey, $state);

        $this->assertSame(['q1', 'q2', 'q3', 'q4'], array_column($saved->questions, 'key'));
        $this->assertSame([], $saved->archived_questions);
        $this->assertSame('open', $saved->status);
        $this->assertTrue($publication->equalTo($saved->published_at));
        $this->assertEquals($before, DB::table('survey_responses')->where('id', $response->id)->first());
        $this->assertSame('Jā, piedalīšos', $saved->questionDefinition('q1')['options'][0]['label']);
    }

    public static function structuralEdits(): array
    {
        return [
            'answer type' => [['type' => 'multiple']],
            'removed option' => [['options' => [['value' => 'yes', 'label' => 'Jā']]]],
            'replaced option identity' => [['options' => [['value' => 'new_yes', 'label' => 'Jā'], ['value' => 'no', 'label' => 'Nē']]]],
            'other answer semantics' => [['allow_other' => false]],
            'exclusive semantics' => [['exclusive_values' => ['no']]],
        ];
    }

    #[DataProvider('structuralEdits')]
    public function test_structural_changes_retire_keys_even_without_responses_and_remap_conditions(array $changes): void
    {
        $old = $this->survey->questions;
        $questions = $old;
        $questions[0] = array_replace($questions[0], $changes);
        $this->survey->update(['questions' => $questions]);
        $saved = $this->survey->fresh();
        $key = $saved->questions[0]['key'];

        $this->assertMatchesRegularExpression('/^q_[a-f0-9]{32}$/', $key);
        $this->assertNotSame('q1', $key);
        $this->assertNotSame('q3', $saved->questions[2]['key']);
        $this->assertSame($key, $saved->questions[2]['condition']['question']);
        $this->assertSame([$old[0], $old[2]], $saved->archived_questions);
        $this->assertNull($saved->questionDefinition('q1'));
        $this->assertSame([...$old[0], 'archived' => true], $saved->questionDefinition('q1', true));
        $this->assertSame('q2', $saved->questions[1]['key']);
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_changed_conditional_values_receive_a_new_identity(): void
    {
        $old = $this->survey->questions[2];
        $questions = $this->survey->questions;
        $questions[2]['condition']['values'] = ['no'];
        $this->survey->update(['questions' => $questions]);
        $this->assertNotSame('q3', $this->survey->questions[2]['key']);
        $this->assertSame([$old], $this->survey->archived_questions);
    }

    public function test_deleted_questions_archive_full_definitions_and_archive_history_cannot_be_supplied_or_erased(): void
    {
        $old = $this->survey->questions[3];
        $questions = array_slice($this->survey->questions, 0, 3);
        $this->survey->fill(['questions' => $questions, 'archived_questions' => [['key' => 'forged']]])->save();
        $this->assertSame([$old], $this->survey->fresh()->archived_questions);
        $this->survey->archived_questions = [];
        $this->survey->title = 'Labots nosaukums';
        $this->survey->save();
        $this->assertSame([$old], $this->survey->fresh()->archived_questions);
        $this->assertSame([...$questions, [...$old, 'archived' => true]], $this->survey->reportQuestions());
        $this->assertInvalid(fn () => $this->survey->update(['questions' => [...$questions, $old]]), 'questions.3.key');
        $copy = $this->survey->fresh()->duplicate();
        $this->assertSame([], $copy->archived_questions);
    }

    public function test_stale_model_preserves_authoritative_content_history_status_and_publication(): void
    {
        $draft = $this->survey->duplicate();
        $stale = $draft->fresh();
        $draft->update(['status' => 'open']);
        $publication = $draft->published_at;
        $questions = $draft->questions;
        array_pop($questions);
        $draft->update(['questions' => $questions, 'status' => 'closed']);
        $stale->update(['title' => 'Labots no vecas lapas']);
        $saved = $stale->fresh();
        $this->assertSame('closed', $saved->status);
        $this->assertSame($questions, $saved->questions);
        $this->assertSame($this->survey->questions[3], $saved->archived_questions[0]);
        $this->assertTrue($publication->equalTo($saved->published_at));
    }

    public function test_stale_question_save_cannot_resurrect_a_retired_identity_or_erase_history(): void
    {
        $stale = $this->survey->fresh();
        $questions = $this->survey->questions;
        $questions[0]['type'] = 'multiple';
        $this->survey->update(['questions' => $questions]);
        $staleQuestions = $stale->questions;
        $staleQuestions[0]['title'] = 'Labots no vecas lapas';
        $this->assertInvalid(fn () => $stale->update(['questions' => $staleQuestions]), 'questions.0.key');
        $this->assertCount(2, $this->survey->fresh()->archived_questions);
    }

    public function test_stale_draft_and_quiet_deletion_cannot_delete_a_published_survey(): void
    {
        $draft = $this->survey->duplicate();
        $stale = $draft->fresh();
        $draft->update(['status' => 'open']);
        $this->assertInvalid(fn () => $stale->delete(), 'survey');
        $this->assertInvalid(fn () => $draft->deleteQuietly(), 'survey');
        $this->assertNotNull($draft->fresh());
    }

    public static function immutableFields(): array
    {
        return ['school' => ['school_id', 99999], 'slug' => ['slug', 'another-link'], 'publication' => ['published_at', '2026-01-01'], 'draft' => ['status', 'draft']];
    }

    #[DataProvider('immutableFields')]
    public function test_publication_identity_and_lifecycle_are_immutable(string $field, mixed $value): void
    {
        $this->assertInvalid(fn () => $this->survey->update([$field => $value]), $field);
    }

    public function test_contact_wording_changes_keep_keys_but_contact_schema_changes_archive_full_definition(): void
    {
        $survey = $this->contactSurvey();
        $questions = $survey->questions;
        $questions[0]['contact']['prompt'] = 'Labots aicinājums';
        $questions[0]['contact']['purpose'] = 'Labots paskaidrojums';
        $questions[0]['contact']['fields'][0]['label'] = 'E-pasta adrese';
        $survey->update(['questions' => $questions]);
        $this->assertSame('q1', $survey->questions[0]['key']);
        $this->assertSame([], $survey->archived_questions);
        $old = $survey->questions[0];
        $questions[0]['contact']['fields'][0]['required'] = false;
        $survey->update(['questions' => $questions]);
        $this->assertNotSame('q1', $survey->questions[0]['key']);
        $this->assertSame($old, $survey->archived_questions[0]);
    }

    public static function contactStructureEdits(): array
    {
        return ['enable' => ['enable'], 'disable' => ['disable'], 'trigger values' => ['triggers'], 'field identity' => ['key'], 'field type' => ['type']];
    }

    #[DataProvider('contactStructureEdits')]
    public function test_contact_structural_changes_always_retire_the_old_question(string $edit): void
    {
        $survey = $edit === 'enable' ? $this->survey : $this->contactSurvey();
        $questions = $survey->questions;
        if ($edit === 'enable') {
            $questions[0]['contact'] = $this->contact();
        } elseif ($edit === 'disable') {
            unset($questions[0]['contact']);
        } elseif ($edit === 'triggers') {
            $questions[0]['contact']['trigger_values'] = ['no'];
        } else {
            $questions[0]['contact']['fields'][0][$edit] = $edit === 'key' ? 'phone' : 'text';
        }
        $survey->update(['questions' => $questions]);
        $this->assertNotSame('q1', $survey->questions[0]['key']);
        $this->assertSame('q1', $survey->archived_questions[0]['key']);
    }

    public static function dataSources(): array
    {
        return ['main responses' => ['responses'], 'separate answers' => ['answers'], 'separate contacts' => ['contacts']];
    }

    #[DataProvider('dataSources')]
    public function test_any_existing_data_locks_global_identity_mode_and_schema_but_allows_field_label_fixes(string $source): void
    {
        $survey = $this->survey->duplicate();
        $fields = [['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => false]];
        $survey->update(['allow_identified_responses' => true, 'respondent_fields' => $fields, 'status' => 'open']);
        if ($source === 'responses') {
            $survey->responses()->create(['answers' => []]);
        } elseif ($source === 'answers') {
            QuestionAnswer::create(['survey_id' => $survey->id, 'question_key' => 'q1', 'answer' => ['value' => 'yes', 'other' => null]]);
        } else {
            QuestionContact::create(['id' => (string) Str::uuid(), 'survey_id' => $survey->id, 'question_key' => 'q1', 'answer' => ['value' => 'yes', 'other' => null], 'details' => ['fields' => []]]);
        }
        $this->assertInvalid(fn () => $survey->update(['allow_identified_responses' => false]), 'respondent_fields');
        $survey->refresh();
        $fields[0]['type'] = 'text';
        $this->assertInvalid(fn () => $survey->update(['respondent_fields' => $fields]), 'respondent_fields');
        $survey->refresh();
        $fields[0]['type'] = 'email';
        $fields[0]['label'] = 'Labota e-pasta adrese';
        $survey->update(['respondent_fields' => $fields]);
        $this->assertSame('Labota e-pasta adrese', $survey->fresh()->respondentFields()[0]['label']);
    }

    public function test_published_workflow_validates_complete_definition_and_preserves_noop_json_and_closed_status(): void
    {
        $this->administrator();
        $this->survey->update(['status' => 'closed']);
        $before = $this->survey->getRawOriginal('questions');
        $sections = $this->survey->getRawOriginal('sections');
        $saved = SurveyWorkflow::update($this->survey, SurveyEditorData::fromDefinition($this->survey->toArray()));
        $this->assertSame($before, $saved->getRawOriginal('questions'));
        $this->assertSame($sections, $saved->getRawOriginal('sections'));
        $this->assertSame('closed', $saved->status);
        $this->assertSame([], $saved->archived_questions);
        $this->assertInvalid(fn () => SurveyWorkflow::update($saved, ['questions' => []]), 'questions');
        $this->assertInvalid(fn () => SurveyWorkflow::update($saved, ['title' => '']), 'title');
        $this->assertSame($before, $saved->fresh()->getRawOriginal('questions'));
    }

    public function test_retired_answer_keys_reject_the_entire_request_before_independent_inserts(): void
    {
        $survey = $this->contactSurvey();
        $invitation = $this->invitation($survey);
        $questions = $survey->questions;
        array_pop($questions);
        $survey->update(['questions' => $questions]);
        foreach ([['answers' => ['q1' => 'yes', 'q4' => 'Stale']], ['answers' => ['q1' => 'yes'], 'other_answers' => ['q4' => 'Stale']]] as $input) {
            $this->assertInvalid(fn () => app(SurveySubmissionService::class)->submit($survey, $this->payload(extra: [...$input, 'role' => 'mother']), $invitation), 'survey');
        }
        $this->assertDatabaseCount('question_answers', 0);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_existing_submission_retries_succeed_after_question_retirement_and_definition_change(): void
    {
        $survey = $this->contactSurvey();
        $invitation = $this->invitation($survey);
        $payload = $this->payload(['q1' => 'yes'], ['role' => 'mother', 'form_definition' => $survey->formDefinitionToken()]);
        $response = app(SurveySubmissionService::class)->submit($survey, $payload, $invitation);
        $questions = $survey->questions;
        $questions[0]['type'] = 'multiple';
        $survey->update(['questions' => $questions, 'status' => 'closed']);
        $retry = app(SurveySubmissionService::class)->submit($survey, $payload, $invitation);
        $this->assertSame($response->id, $retry->id);
        $this->assertSame($response->id, app(SurveySubmissionService::class)->submit($survey, [...$payload, 'form_definition' => []], $invitation)->id);
        $this->assertDatabaseCount('question_answers', 1);
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 1);
    }

    public function test_public_page_token_rejects_stale_semantic_changes_even_when_keys_remain_active(): void
    {
        $page = $this->get(route('surveys.show', $this->survey->slug))->assertOk();
        $token = $this->survey->formDefinitionToken();
        $page->assertSee('name="form_definition" value="'.$token.'"', false);
        $questions = $this->survey->questions;
        $questions[0]['options'][] = ['value' => 'maybe', 'label' => 'Varbūt'];
        $this->survey->update(['questions' => $questions]);
        $this->assertSame('q1', $this->survey->questions[0]['key']);
        $payload = $this->payload(extra: ['submission_id' => $page->viewData('submissionId'), 'form_definition' => $token]);
        $this->postJson(route('surveys.submit', $this->survey->slug), $payload)->assertUnprocessable()->assertJsonValidationErrors('survey');
        $this->assertDatabaseCount('survey_responses', 0);
        $payload['form_definition'] = $this->survey->formDefinitionToken();
        $this->postJson(route('surveys.submit', $this->survey->slug), $payload)->assertOk();
        $this->assertSame(['id', 'survey_id', 'answers', 'respondent_details'], Schema::getColumnListing('survey_responses'));
        $this->assertSame(['id', 'survey_id', 'question_key', 'answer'], Schema::getColumnListing('question_answers'));
    }
}
