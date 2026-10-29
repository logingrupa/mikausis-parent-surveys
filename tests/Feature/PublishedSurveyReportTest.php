<?php

namespace Tests\Feature;

use App\Filament\Resources\Surveys\SurveyResource;
use App\Filament\Support\SurveyEditorData;
use App\Filament\Support\SurveyWorkflow;
use App\Models\Survey;
use App\Models\User;
use App\Services\QuestionContactService;
use App\Services\SurveyReportService;
use App\Services\SurveySubmissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PublishedSurveyReportTest extends SurveyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create([
            'school_id' => $this->school->id, 'name' => 'Synthetic administrator',
            'email' => 'published-report@example.test', 'password' => 'synthetic-only-password', 'is_admin' => true,
        ]));
    }

    private function published(bool $contact = false): Survey
    {
        $definition = SurveyEditorData::definition($this->survey->only([
            'title', 'description', 'sections', 'questions', 'estimated_minutes', 'closes_at',
            'allow_identified_responses', 'respondent_fields',
        ]));
        if ($contact) {
            $definition['questions'][0]['contact'] = SurveyEditorData::defaultQuestionContact($definition['questions'][0]);
        }

        return SurveyWorkflow::publish(SurveyWorkflow::create($definition));
    }

    private function edit(Survey $survey, callable $change): Survey
    {
        $builder = SurveyEditorData::fromDefinition($survey->fresh()->toArray())['builder'];
        $change($builder);

        return SurveyWorkflow::update($survey, ['builder' => $builder]);
    }

    private function collectAnswers(Survey $survey, int $count = 5): void
    {
        for ($index = 0; $index < $count; $index++) {
            app(SurveySubmissionService::class)->submit($survey, $this->payload(array_intersect_key([
                'q1' => 'yes', 'q2' => ['a'], 'q4' => 'Earlier free text',
            ], array_flip(array_column($survey->questions, 'key')))));
        }
    }

    private function contact(Survey $survey): array
    {
        $payload = [
            'contact_id' => (string) Str::uuid(), 'consent' => true, 'answer' => 'yes',
            'fields' => ['name' => 'Private contact canary', 'email' => 'private-contact@example.test'],
        ];
        $record = app(QuestionContactService::class)->submit($survey, 'q1', $payload);

        return [$payload, $record];
    }

    public function test_changed_input_type_preserves_old_typed_answers_and_new_answers_in_separate_groups(): void
    {
        $survey = $this->published();
        $this->collectAnswers($survey);
        $saved = $this->edit($survey, function (&$builder): void {
            $builder['questions'][0]['type'] = 'text';
            $builder['questions'][0]['options'] = [];
            $builder['questions'][0]['allow_other'] = false;
            $builder['questions'][2]['condition'] = null;
        });
        $newKey = $saved->questions[0]['key'];
        $this->assertNotSame('q1', $newKey);
        app(SurveySubmissionService::class)->submit($saved, $this->payload([$newKey => 'New typed answer']));
        $report = app(SurveyReportService::class)->build($saved, true);
        $old = collect($report['questions'])->firstWhere('key', 'q1');
        $new = collect($report['questions'])->firstWhere('key', $newKey);
        $this->assertTrue($old['archived']);
        $this->assertSame(5, $old['answered']);
        $this->assertSame(5, $old['options'][0]['count']);
        $this->assertNull($old['skipped']);
        $this->assertFalse($new['archived']);
        $this->assertSame(['New typed answer'], $new['texts']);
        $this->assertSame(6, $report['response_count']);
        $this->get(route('reports.html', $saved))->assertOk()
            ->assertSee('Iepriekšējā jautājuma versija')->assertSee('New typed answer');
        $csv = $this->get(route('reports.export', $saved))->assertOk()->streamedContent();
        $this->assertStringContainsString('iepriekšējā versija', $csv);
    }

    public function test_deleted_questions_and_removed_options_keep_the_original_counts_and_text(): void
    {
        $survey = $this->published();
        $this->collectAnswers($survey);
        $saved = $this->edit($survey, function (&$builder): void {
            $builder['questions'][1]['options'] = array_values(array_filter(
                $builder['questions'][1]['options'], fn ($option) => $option['value'] !== 'a',
            ));
            $builder['questions'] = array_values(array_filter($builder['questions'], fn ($question) => $question['key'] !== 'q4'));
        });
        $report = app(SurveyReportService::class)->build($saved, true);
        $oldMultiple = collect($report['questions'])->firstWhere('key', 'q2');
        $oldText = collect($report['questions'])->firstWhere('key', 'q4');
        $this->assertTrue($oldMultiple['archived']);
        $this->assertSame(5, $oldMultiple['options'][0]['count']);
        $this->assertTrue($oldText['archived']);
        $this->assertCount(5, $oldText['texts']);
        $this->assertSame(5, $oldText['answered']);
        $this->assertSame(5, $saved->responses()->count());
    }

    public function test_empty_historical_definitions_are_omitted_and_minimum_response_suppression_remains(): void
    {
        $saved = $this->edit($this->published(), function (&$builder): void {
            $builder['questions'] = array_values(array_filter($builder['questions'], fn ($question) => $question['key'] !== 'q4'));
        });
        $suppressed = app(SurveyReportService::class)->build($saved, true);
        $this->assertTrue($suppressed['suppressed']);
        $this->assertSame([], $suppressed['questions']);
        $this->get(route('reports.html', $saved))->assertForbidden();
        $this->collectAnswers($saved);
        $report = app(SurveyReportService::class)->build($saved);
        $this->assertFalse($report['suppressed']);
        $this->assertNull(collect($report['questions'])->firstWhere('key', 'q4'));
    }

    public function test_added_question_does_not_count_earlier_respondents_as_having_skipped_it(): void
    {
        $survey = $this->published();
        $this->collectAnswers($survey);
        $saved = $this->edit($survey, function (&$builder): void {
            $builder['questions'][] = [
                'key' => 'new_question', 'section' => 'all', 'type' => 'text', 'title' => 'Pievienots jautājums',
                'description' => '', 'required' => false, 'allow_other' => false,
                'options' => [], 'exclusive_values' => [], 'condition' => null,
                'introduced_after_publication' => false,
            ];
        });
        $this->assertSame([], $saved->archived_questions);
        $this->assertTrue($saved->questionDefinition('new_question')['introduced_after_publication']);
        app(SurveySubmissionService::class)->submit($saved, $this->payload(['new_question' => 'Jaunā atbilde']));
        $questions = collect(app(SurveyReportService::class)->build($saved)['questions']);
        $this->assertSame(1, $questions->firstWhere('key', 'new_question')['answered']);
        $this->assertNull($questions->firstWhere('key', 'new_question')['skipped']);
        $this->assertSame(1, $questions->firstWhere('key', 'q1')['skipped']);
        $again = $this->edit($saved, function (&$builder): void {
            unset($builder['questions'][4]['introduced_after_publication']);
            $builder['questions'][4]['title'] = 'Labots jaunais jautājums';
        });
        $this->assertTrue($again->questionDefinition('new_question')['introduced_after_publication']);
        $copy = SurveyWorkflow::duplicate($again);
        $this->assertArrayNotHasKey('introduced_after_publication', $copy->questionDefinition('new_question'));
        $this->get(route('reports.html', $again))->assertOk()->assertSee('Izlaisto atbilžu skaitu pa versijām neuzskaitām');
    }

    public function test_retired_contact_answers_stay_anonymous_and_contacts_remain_in_the_authorized_contact_page(): void
    {
        $survey = $this->published(true);
        $this->collectAnswers($survey);
        [, $contact] = $this->contact($survey);
        $saved = $this->edit($survey, function (&$builder): void {
            unset($builder['questions'][0]['contact']);
        });
        $report = app(SurveyReportService::class)->build($saved, true);
        $old = collect($report['questions'])->firstWhere('key', 'q1');
        $this->assertTrue($old['archived']);
        $this->assertSame(5, $old['answered']);
        $this->assertSame([null, null, null, null, null], array_column($old['answer_rows'], 'respondent'));
        $this->assertStringNotContainsString('Private contact canary', json_encode($report));
        $this->get(route('reports.html', $saved))->assertOk()->assertDontSee('Private contact canary');
        $this->get(SurveyResource::getUrl('contacts', ['record' => $saved]))->assertOk()
            ->assertSee('Private contact canary')->assertSee('Iepriekšējā jautājuma versija');
        $this->get(SurveyResource::getUrl('report', ['record' => $saved]))->assertOk()->assertSee('Brīvprātīgie kontakti');
        $this->assertSame($contact->getRawOriginal('details'), $contact->fresh()->getRawOriginal('details'));
    }

    public function test_exact_contact_retry_survives_wording_corrections_and_closure_without_rewriting_ciphertext(): void
    {
        $survey = $this->published(true);
        [$payload, $record] = $this->contact($survey);
        $ciphertext = $record->getRawOriginal('details');
        $saved = $this->edit($survey, function (&$builder): void {
            $builder['questions'][0]['options'][0]['label'] = 'Jā, piedalīšos';
            $builder['questions'][0]['contact']['fields'][0]['label'] = 'Jūsu vārds';
        });
        $this->assertSame('q1', $saved->questions[0]['key']);
        $saved = SurveyWorkflow::close($saved);
        $retry = app(QuestionContactService::class)->submit($saved, 'q1', $payload);
        $this->assertSame($record->id, $retry->id);
        $this->assertSame($ciphertext, $retry->fresh()->getRawOriginal('details'));
        $this->assertSame(['Jā'], $retry->answer['labels']);
        $this->assertSame(1, $saved->questionContacts()->count());
    }

    public function test_exact_retired_contact_retry_succeeds_but_changed_and_new_requests_fail(): void
    {
        $survey = $this->published(true);
        [$payload, $record] = $this->contact($survey);
        $saved = $this->edit($survey, function (&$builder): void {
            $builder['questions'][0]['contact']['fields'][] = ['key' => 'phone', 'label' => 'Tālrunis', 'type' => 'tel', 'required' => false];
        });
        $this->postJson(route('question-contacts.store', ['slug' => $saved->slug, 'question' => 'q1']), $payload)
            ->assertOk()->assertExactJson(['saved' => true]);
        $this->postJson(route('question-contacts.store', ['slug' => $saved->slug, 'question' => 'q1']), [
            ...$payload, 'fields' => [...$payload['fields'], 'email' => 'changed@example.test'],
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_id');
        $this->postJson(route('question-contacts.store', ['slug' => $saved->slug, 'question' => 'q1']), [
            ...$payload, 'contact_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('question');
        $this->get(route('question-contacts.show', ['slug' => $saved->slug, 'question' => 'q1']))
            ->assertOk()->assertSee('Šis jautājums ir mainīts')->assertDontSee('name="fields[', false);
        $this->assertSame(1, $saved->questionContacts()->count());
        $this->assertSame($record->answer, $record->fresh()->answer);
    }

    public function test_archives_never_add_response_or_contact_linkage_columns(): void
    {
        $survey = $this->published(true);
        $this->collectAnswers($survey);
        $this->edit($survey, function (&$builder): void {
            unset($builder['questions'][0]['contact']);
        });
        $this->assertSame(['id', 'survey_id', 'answers', 'respondent_details'], Schema::getColumnListing('survey_responses'));
        $this->assertSame(['id', 'survey_id', 'question_key', 'answer', 'details'], Schema::getColumnListing('question_contacts'));
        $this->assertSame(['id', 'survey_id', 'question_key', 'answer'], Schema::getColumnListing('question_answers'));
        foreach (['question_contacts', 'question_answers'] as $table) {
            $definition = DB::selectOne('SELECT sql FROM sqlite_master WHERE name = ?', [$table]);
            $this->assertStringContainsString('WITHOUT ROWID', strtoupper($definition->sql));
        }
    }
}
