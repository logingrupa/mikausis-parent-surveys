<?php

namespace Tests\Feature;

use App\Filament\Resources\Surveys\Pages\CreateSurvey;
use App\Filament\Resources\Surveys\Pages\EditSurvey;
use App\Filament\Support\SurveyEditorData;
use App\Filament\Support\SurveyWorkflow;
use App\Models\School;
use App\Models\Survey;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SurveyEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite.database', ':memory:');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
        $school = School::create(['name' => 'Mikausis', 'slug' => 'mikausis']);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true, 'school_id' => $school->id])->save();
        $this->actingAs($this->admin);
    }

    private function builder(): array
    {
        return [
            'title' => 'Vecāku ieceres',
            'description' => 'Pastāstiet par savām iecerēm.',
            'sections' => [
                ['key' => 's_intro', 'title' => 'Ievads', 'description' => ''],
                ['key' => 's_details', 'title' => 'Ieceres', 'description' => 'Papildinājumi'],
            ],
            'questions' => [
                [
                    'key' => 'q_first', 'section' => 's_intro', 'type' => 'single',
                    'title' => 'Vai piedalīsieties?', 'description' => '', 'required' => true, 'allow_other' => false,
                    'options' => [['value' => '0', 'label' => 'Jā'], ['value' => 'no', 'label' => 'Nē']],
                    'exclusive_values' => [], 'condition' => null,
                ],
                [
                    'key' => 'q_multi', 'section' => 's_details', 'type' => 'multiple',
                    'title' => 'Kā palīdzēsiet?', 'description' => 'Izvēlieties atbildes.', 'required' => false, 'allow_other' => true,
                    'options' => [['value' => 'time', 'label' => 'Ar laiku'], ['value' => 'other', 'label' => 'Citādi'], ['value' => 'none', 'label' => 'Nevarēšu']],
                    'exclusive_values' => ['none'], 'condition' => ['question' => 'q_first', 'values' => ['0']],
                ],
                [
                    'key' => 'q_branch', 'section' => 's_details', 'type' => 'text',
                    'title' => 'Kāda ir jūsu iecere?', 'description' => '', 'required' => false, 'allow_other' => false,
                    'options' => [], 'exclusive_values' => [], 'condition' => ['question' => 'q_multi', 'values' => ['time', 'other']],
                ],
            ],
        ];
    }

    public function test_new_editor_boots_with_one_section_and_a_choice_question_with_opaque_ids(): void
    {
        $page = Livewire::test(CreateSurvey::class);
        $builder = $page->get('data.builder');
        $this->assertSame('', $builder['title']);
        $this->assertSame('', $builder['description']);
        $this->assertEquals(7, $page->get('data.estimated_minutes'));
        $this->assertCount(1, $builder['sections']);
        $this->assertCount(1, $builder['questions']);
        $this->assertSame('Jautājumi', $builder['sections'][0]['title']);
        $question = $builder['questions'][0];
        $this->assertSame($builder['sections'][0]['key'], $question['section']);
        $this->assertSame('single', $question['type']);
        $this->assertFalse($question['required']);
        $this->assertFalse($question['allow_other']);
        $this->assertNull($question['condition']);
        $this->assertSame([], $question['exclusive_values']);
        $this->assertCount(2, $question['options']);
        foreach ([$builder['sections'][0]['key'], $question['key'], ...array_column($question['options'], 'value')] as $identifier) {
            $this->assertMatchesRegularExpression('/^[sqo]_[a-f0-9]{32}$/', $identifier);
        }
        $this->assertNotSame($question['options'][0]['value'], $question['options'][1]['value']);
        $this->assertNotSame($builder['sections'][0]['key'], SurveyEditorData::empty()['sections'][0]['key']);
        $this->assertArrayNotHasKey('slug', $page->get('data'));
    }

    public function test_server_generates_missing_ids_and_defaults_without_changing_existing_ids(): void
    {
        $survey = SurveyWorkflow::create(['builder' => [
            'title' => 'Jauna aptauja', 'sections' => [['title' => 'Pirmā sadaļa']],
            'questions' => [['title' => 'Izvēlieties', 'options' => [['label' => 'A'], ['value' => '0', 'label' => 'A']], 'condition' => []]],
        ]]);
        $question = $survey->questions[0];
        $this->assertMatchesRegularExpression('/^s_[a-f0-9]{32}$/', $survey->sections[0]['key']);
        $this->assertMatchesRegularExpression('/^q_[a-f0-9]{32}$/', $question['key']);
        $this->assertMatchesRegularExpression('/^o_[a-f0-9]{32}$/', $question['options'][0]['value']);
        $this->assertSame('0', $question['options'][1]['value']);
        $this->assertSame($survey->sections[0]['key'], $question['section']);
        $this->assertSame('single', $question['type']);
        $this->assertFalse($question['required']);
        $this->assertFalse($question['allow_other']);
        $this->assertSame([], $question['exclusive_values']);
        $this->assertNull($question['condition']);
        $this->assertSame(7, $survey->estimated_minutes);
        $state = SurveyEditorData::fromDefinition($survey->toArray());
        SurveyWorkflow::update($survey, $state);
        $this->assertSame($question, $survey->fresh()->questions[0]);
    }

    public function test_builder_is_authoritative_and_hidden_fields_are_never_persisted(): void
    {
        $builder = [...$this->builder(), 'school_id' => 999, 'slug' => 'injected', 'status' => 'open', 'published_at' => now(), 'estimated_minutes' => 100, 'closes_at' => now()];
        $survey = SurveyWorkflow::create([
            'builder' => $builder, 'title' => 'Ignored legacy title', 'sections' => [], 'questions' => [],
            'school_id' => 999, 'slug' => 'forged', 'status' => 'open', 'published_at' => now(),
        ]);
        $this->assertSame($this->builder()['title'], $survey->title);
        $this->assertSame($this->builder()['questions'], $survey->questions);
        $this->assertSame($this->admin->school_id, $survey->school_id);
        $this->assertSame('draft', $survey->status);
        $this->assertNull($survey->published_at);
        $this->assertNull($survey->closes_at);
        $this->assertSame(7, $survey->estimated_minutes);
        $this->assertNotContains($survey->slug, ['injected', 'forged']);
        $this->assertArrayNotHasKey('builder', $survey->getAttributes());
        $this->assertEqualsCanonicalizing(['title', 'description', 'sections', 'questions', 'estimated_minutes', 'closes_at', 'allow_identified_responses', 'respondent_fields'], array_keys(SurveyWorkflow::definition(['builder' => $builder])));
    }

    public function test_create_edit_save_roundtrip_preserves_choices_branching_and_exclusive_answers(): void
    {
        $builder = $this->builder();
        Livewire::test(CreateSurvey::class)
            ->set('data', ['builder' => $builder, 'estimated_minutes' => 9, 'closes_at' => null])
            ->call('create')->assertHasNoFormErrors();
        $survey = Survey::sole();
        $this->assertSame($builder['questions'], $survey->questions);
        $this->assertSame($builder['sections'], $survey->sections);
        $questionsJson = $survey->getRawOriginal('questions');
        $sectionsJson = $survey->getRawOriginal('sections');
        $page = Livewire::test(EditSurvey::class, ['record' => $survey->id]);
        $this->assertEquals([...$builder, 'allow_identified_responses' => false, 'respondent_fields' => null], $page->get('data.builder'));
        $page->call('save')->assertHasNoFormErrors()->assertDispatched('survey-editor-saved');
        $survey->refresh();
        $this->assertSame($questionsJson, $survey->getRawOriginal('questions'));
        $this->assertSame($sectionsJson, $survey->getRawOriginal('sections'));
        $this->assertSame(9, $survey->estimated_minutes);
    }

    public function test_unchanged_legacy_json_preserves_absent_optional_fields(): void
    {
        $builder = $this->builder();
        unset($builder['sections'][0]['description'], $builder['questions'][0]['description'], $builder['questions'][0]['allow_other'], $builder['questions'][2]['options'], $builder['questions'][2]['exclusive_values']);
        $survey = Survey::create([...$builder, 'school_id' => $this->admin->school_id]);
        $original = $survey->fresh()->getRawOriginal('questions');
        Livewire::test(EditSurvey::class, ['record' => $survey->id])->call('save')->assertHasNoFormErrors();
        $this->assertSame($original, $survey->fresh()->getRawOriginal('questions'));
        $this->assertSame($builder['sections'], $survey->fresh()->sections);
    }

    public function test_renaming_labels_preserves_all_ids_and_references(): void
    {
        $builder = $this->builder();
        $survey = SurveyWorkflow::create(['builder' => $builder]);
        $builder['title'] = 'Pārdēvēta aptauja';
        $builder['sections'][0]['title'] = 'Jauns sadaļas nosaukums';
        $builder['questions'][0]['title'] = 'Pārdēvēts jautājums';
        $builder['questions'][0]['options'][0]['label'] = 'Pārdēvēta atbilde';
        $slug = $survey->slug;
        Livewire::test(EditSurvey::class, ['record' => $survey->id])
            ->set('data.builder', $builder)->call('save')->assertHasNoFormErrors();
        $survey->refresh();
        $this->assertSame($builder['questions'], $survey->questions);
        $this->assertSame($builder['sections'], $survey->sections);
        $this->assertSame($builder['title'], $survey->title);
        $this->assertSame($slug, $survey->slug);
    }

    public function test_builder_only_updates_preserve_metadata_and_explicit_null_clears_deadline(): void
    {
        $survey = SurveyWorkflow::create(['builder' => $this->builder(), 'estimated_minutes' => 12, 'closes_at' => now()->addWeek()]);
        $deadline = $survey->closes_at;
        SurveyWorkflow::update($survey, ['builder' => $this->builder()]);
        $this->assertSame(12, $survey->fresh()->estimated_minutes);
        $this->assertTrue($deadline->equalTo($survey->fresh()->closes_at));
        SurveyWorkflow::update($survey, ['builder' => $this->builder(), 'closes_at' => null]);
        $this->assertNull($survey->fresh()->closes_at);
    }

    public function test_legacy_workflow_callers_can_create_and_partially_update_definitions(): void
    {
        $survey = SurveyWorkflow::create($this->builder());
        SurveyWorkflow::update($survey, ['title' => 'Vecā formāta izmaiņa']);
        $this->assertSame('Vecā formāta izmaiņa', $survey->fresh()->title);
        $this->assertSame($this->builder()['questions'], $survey->fresh()->questions);
    }

    public function test_publishing_fills_missing_ids_in_a_legacy_draft(): void
    {
        $survey = Survey::create([
            'school_id' => $this->admin->school_id, 'title' => 'Vecs melnraksts',
            'sections' => [['title' => 'Jautājumi']],
            'questions' => [['title' => 'Izvēlieties', 'options' => [['label' => 'Jā'], ['label' => 'Nē']]]],
        ]);
        $published = SurveyWorkflow::publish($survey);
        $this->assertSame('open', $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertMatchesRegularExpression('/^q_[a-f0-9]{32}$/', $published->questions[0]['key']);
        $this->assertSame($published->sections[0]['key'], $published->questions[0]['section']);
        $this->assertMatchesRegularExpression('/^o_[a-f0-9]{32}$/', $published->questions[0]['options'][0]['value']);
        $this->assertSame($published->questions, $published->fresh()->questions);
    }

    public function test_published_and_stale_draft_updates_validate_malformed_builders_without_saving(): void
    {
        $draft = SurveyWorkflow::create(['builder' => $this->builder()]);
        $published = SurveyWorkflow::publish($draft->fresh());
        $json = $published->getRawOriginal('questions');
        foreach ([$draft, $published] as $record) {
            try {
                SurveyWorkflow::update($record, [
                    'builder' => 'malformed injected content', 'title' => 'Injected title', 'questions' => [],
                    'school_id' => 999, 'status' => 'draft', 'slug' => 'injected', 'published_at' => null,
                    'estimated_minutes' => 11, 'closes_at' => null,
                ]);
                $this->fail('Expected published content validation.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors());
            }
            $saved = $record->fresh();
            $this->assertSame($this->builder()['title'], $saved->title);
            $this->assertSame($json, $saved->getRawOriginal('questions'));
            $this->assertSame($this->builder()['sections'], $saved->sections);
            $this->assertSame('open', $saved->status);
            $this->assertSame($this->admin->school_id, $saved->school_id);
            $this->assertSame($published->slug, $saved->slug);
            $this->assertTrue($published->published_at->equalTo($saved->published_at));
            $this->assertSame(7, $saved->estimated_minutes);
        }
    }

    public function test_invalid_conditions_and_options_emit_readable_numeric_field_errors(): void
    {
        $survey = SurveyWorkflow::create(['builder' => $this->builder()]);
        $builder = $this->builder();
        $builder['questions'][0]['condition'] = ['question' => 'q_branch', 'values' => ['private-code']];
        $builder['questions'][1]['options'][1]['label'] = ['invalid'];
        $builder['questions'][2]['condition']['values'] = ['private-code'];
        $page = Livewire::test(EditSurvey::class, ['record' => $survey->id])->set('data.builder', $builder);
        $page->call('save')->assertHasErrors([
            'data.builder.questions.0.condition.question',
            'data.builder.questions.1.options.1.label',
            'data.builder.questions.2.condition.values',
        ])->assertDispatched('survey-editor-errors', function ($event, $parameters) {
            $errors = $parameters['errors'];
            $this->assertStringContainsString('1. jautājums', $errors['data.builder.questions.0.condition.question'][0]);
            $this->assertStringContainsString('2. atbilde', $errors['data.builder.questions.1.options.1.label'][0]);
            $messages = implode(' ', array_merge(...array_values($errors)));
            foreach (['q_branch', 'private-code', 'slug', 'key', 'value', 'questions.'] as $internal) {
                $this->assertStringNotContainsString($internal, $messages);
            }

            return true;
        })->assertNotDispatched('survey-editor-saved');
        $this->assertSame($this->builder()['questions'], $survey->fresh()->questions);
    }

    public function test_invalid_section_and_question_titles_have_contextual_editor_paths(): void
    {
        $builder = $this->builder();
        $builder['sections'][1]['title'] = '';
        $builder['questions'][0]['title'] = '';
        Livewire::test(CreateSurvey::class)->set('data.builder', $builder)->call('create')
            ->assertHasErrors(['data.builder.sections.1.title', 'data.builder.questions.0.title'])
            ->assertDispatched('survey-editor-errors');
        $this->assertDatabaseCount('surveys', 0);
    }

    public function test_conditions_cannot_reference_a_question_displayed_in_a_later_section(): void
    {
        $builder = $this->builder();
        $builder['questions'][0]['section'] = 's_details';
        $builder['questions'][1]['section'] = 's_intro';
        Livewire::test(CreateSurvey::class)->set('data.builder', $builder)->call('create')
            ->assertHasErrors(['data.builder.questions.1.condition.question']);
        $this->assertDatabaseCount('surveys', 0);
    }

    public function test_page_publication_maps_empty_survey_errors_to_the_editor(): void
    {
        $survey = SurveyWorkflow::create(['builder' => ['title' => 'Tukša aptauja', 'sections' => [], 'questions' => []]]);
        Livewire::test(EditSurvey::class, ['record' => $survey->id])->call('publishSurvey')
            ->assertHasErrors(['data.builder.sections', 'data.builder.questions'])
            ->assertDispatched('survey-editor-errors');
        $this->assertNull($survey->fresh()->published_at);
    }

    public function test_page_saves_remove_blank_placeholder_options_and_keep_existing_values(): void
    {
        $builder = $this->builder();
        array_splice($builder['questions'][0]['options'], 1, 0, [['value' => 'unused', 'label' => '  ']]);
        Livewire::test(CreateSurvey::class)->set('data.builder', $builder)->call('create')->assertHasNoFormErrors();
        $survey = Survey::sole();
        $this->assertSame($this->builder()['questions'], $survey->questions);
        $builder['questions'][0]['options'] = [['value' => 'unused', 'label' => '']];
        Livewire::test(EditSurvey::class, ['record' => $survey->id])->set('data.builder', $builder)->call('save')
            ->assertHasErrors(['data.builder.questions.0.options']);
        $this->assertSame($this->builder()['questions'], $survey->fresh()->questions);
    }

    #[DataProvider('malformedState')]
    public function test_malformed_editor_state_raises_validation_instead_of_server_errors(string $path, mixed $value): void
    {
        $data = ['builder' => $this->builder(), 'title' => 'Valid legacy fallback', 'sections' => [], 'questions' => []];
        data_set($data, $path, $value);
        $this->expectException(ValidationException::class);
        SurveyWorkflow::create($data);
    }

    public static function malformedState(): array
    {
        return [
            'null builder' => ['builder', null],
            'scalar builder' => ['builder', 'invalid'],
            'empty builder overrides legacy' => ['builder', []],
            'scalar sections' => ['builder.sections', 'invalid'],
            'empty string sections' => ['builder.sections', ''],
            'scalar section' => ['builder.sections.0', 'invalid'],
            'array section key' => ['builder.sections.0.key', ['invalid']],
            'scalar questions' => ['builder.questions', 'invalid'],
            'empty string questions' => ['builder.questions', ''],
            'scalar question' => ['builder.questions.0', 'invalid'],
            'array question key' => ['builder.questions.0.key', ['invalid']],
            'scalar options' => ['builder.questions.0.options', 'invalid'],
            'empty string text options' => ['builder.questions.2.options', ''],
            'scalar option' => ['builder.questions.0.options.0', 'invalid'],
            'array option value' => ['builder.questions.0.options.0.value', ['invalid']],
            'scalar exclusives' => ['builder.questions.1.exclusive_values', 'invalid'],
            'empty string exclusives' => ['builder.questions.1.exclusive_values', ''],
            'nested exclusive' => ['builder.questions.1.exclusive_values', [['invalid']]],
            'scalar condition' => ['builder.questions.2.condition', 'invalid'],
            'empty string condition' => ['builder.questions.2.condition', ''],
            'array condition source' => ['builder.questions.2.condition.question', ['invalid']],
            'scalar condition answers' => ['builder.questions.2.condition.values', 'invalid'],
            'nested condition answer' => ['builder.questions.2.condition.values', [['invalid']]],
            'condition missing source' => ['builder.questions.2.condition', ['values' => ['time']]],
            'condition missing answers' => ['builder.questions.2.condition', ['question' => 'q_multi']],
            'non-list questions' => ['builder.questions', ['opaque' => ['title' => 'Invalid']]],
            'non-list options' => ['builder.questions.0.options', ['opaque' => ['label' => 'Invalid']]],
        ];
    }
}
