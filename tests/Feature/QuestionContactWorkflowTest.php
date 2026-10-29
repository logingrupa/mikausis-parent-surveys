<?php

namespace Tests\Feature;

use App\Filament\Support\SurveyEditorData;
use App\Filament\Support\SurveyWorkflow;
use App\Models\Survey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class QuestionContactWorkflowTest extends SurveyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create([
            'school_id' => $this->school->id,
            'is_admin' => true,
        ]));
    }

    private function builder(): array
    {
        return [
            'title' => 'Palīdzība pasākumā',
            'description' => 'Pastāstiet par savu līdzdalību.',
            'allow_identified_responses' => false,
            'respondent_fields' => null,
            'sections' => [['key' => 's_help', 'title' => 'Līdzdalība', 'description' => '']],
            'questions' => [[
                'key' => 'q_help', 'section' => 's_help', 'type' => 'single',
                'title' => 'Vai palīdzēsiet?', 'description' => '', 'required' => false,
                'allow_other' => true,
                'options' => [
                    ['value' => 'yes', 'label' => 'Jā'],
                    ['value' => '0', 'label' => 'Vēl nezinu'],
                    ['value' => 'other', 'label' => 'Cits variants'],
                ],
                'exclusive_values' => [], 'condition' => null,
            ]],
        ];
    }

    private static function contact(): array
    {
        return [
            'prompt' => 'Atstāt kontaktinformāciju',
            'purpose' => 'Sazināsimies, lai vienotos par palīdzību šajā pasākumā.',
            'trigger_values' => ['yes'],
            'fields' => [
                ['key' => 'name', 'label' => 'Vārds un uzvārds', 'type' => 'text', 'required' => true],
                ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true],
            ],
        ];
    }

    private static function fields(int $count): array
    {
        $fields = [];
        for ($index = 1; $index <= $count; $index++) {
            $fields[] = [
                'key' => 'detail_'.str_pad(dechex($index), 32, '0', STR_PAD_LEFT),
                'label' => 'Papildinformācija '.$index,
                'type' => 'text',
                'required' => false,
            ];
        }

        return $fields;
    }

    private function assertContactRejected(callable $action, string $path): array
    {
        try {
            $action();
            $this->fail('Expected contact validation to fail at '.$path.'.');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        }

        $matching = array_filter($errors, static fn (string $key): bool => $key === $path || str_starts_with($key, $path.'.'), ARRAY_FILTER_USE_KEY);
        $this->assertNotEmpty($matching, 'Expected '.$path.'; received '.implode(', ', array_keys($errors)));

        foreach ($errors as $key => $messages) {
            $this->assertMatchesRegularExpression('/^questions\.\d+\.contact(?:\.|$)/', $key);
            foreach ($messages as $message) {
                $this->assertMatchesRegularExpression('/[āčēģīķļņšūž]/iu', $message);
                foreach (['The ', 'must ', ' is required', 'questions.', 'q_help', 'trigger_values'] as $internal) {
                    $this->assertStringNotContainsString($internal, $message);
                }
            }
        }

        return $errors;
    }

    public function test_legacy_absent_and_null_contacts_preserve_raw_json_through_update_and_publication(): void
    {
        $builder = $this->builder();
        $builder['allow_identified_responses'] = true;
        unset($builder['sections'][0]['description']);
        foreach (['description', 'required', 'allow_other', 'exclusive_values', 'condition'] as $field) {
            unset($builder['questions'][0][$field]);
        }
        $builder['questions'][] = [
            'key' => 'q_note', 'section' => 's_help', 'type' => 'text',
            'title' => 'Papildinājumi', 'contact' => null,
        ];
        $draft = Survey::create([...$builder, 'school_id' => $this->school->id]);
        $rawQuestions = $draft->getRawOriginal('questions');
        $rawSections = $draft->getRawOriginal('sections');

        $this->assertArrayNotHasKey('contact', SurveyEditorData::empty()['questions'][0]);
        $state = SurveyEditorData::fromDefinition($draft->toArray());
        foreach ([SurveyEditorData::definition($state), SurveyWorkflow::definition($state)] as $definition) {
            $this->assertArrayNotHasKey('contact', $definition['questions'][0]);
            $this->assertArrayHasKey('contact', $definition['questions'][1]);
            $this->assertNull($definition['questions'][1]['contact']);
        }

        $saved = SurveyWorkflow::update($draft, $state);
        $this->assertSame($rawQuestions, $saved->getRawOriginal('questions'));
        $this->assertSame($rawSections, $saved->getRawOriginal('sections'));
        SurveyWorkflow::update($saved, ['title' => 'Pārdēvēta iepriekšējā aptauja']);
        $published = SurveyWorkflow::publish($saved);

        $this->assertSame('open', $published->status);
        $this->assertNotNull($published->published_at);
        $this->assertTrue($published->allow_identified_responses);
        $this->assertSame($rawQuestions, $published->fresh()->getRawOriginal('questions'));
        $this->assertSame($rawSections, $published->fresh()->getRawOriginal('sections'));
        $this->assertArrayNotHasKey('contact', $published->questions[0]);
        $this->assertNull($published->questions[1]['contact']);

        $published = SurveyWorkflow::update($published, SurveyEditorData::fromDefinition($published->toArray()));
        $this->assertSame($rawQuestions, $published->getRawOriginal('questions'));
        $this->assertSame($rawSections, $published->getRawOriginal('sections'));
        $this->assertSame([], $published->archived_questions);
    }

    public static function defaultTriggerOptions(): array
    {
        return [
            'first choice' => [[['value' => 'yes', 'label' => 'Jā'], ['value' => '0', 'label' => 'Vēl nezinu']], ['yes']],
            'skip literal other and retain string zero' => [[['value' => 'other', 'label' => 'Cits'], ['value' => '0', 'label' => 'Vēl nezinu'], ['value' => 'yes', 'label' => 'Jā']], ['0']],
            'no options' => [[], []],
            'only literal other' => [[['value' => 'other', 'label' => 'Cits']], []],
        ];
    }

    public function test_unchanged_contact_json_survives_normalized_editor_values_and_publication(): void
    {
        $builder = $this->builder();
        $contact = self::contact();
        $contact['prompt'] = "\u{00A0}".$contact['prompt']."\u{3000}";
        $contact['fields'][0]['label'] = "\u{200B}".$contact['fields'][0]['label']."\u{00A0}";
        $contact['fields'][0]['required'] = '1';
        $contact['fields'][1]['required'] = '0';
        $builder['questions'][0]['contact'] = $contact;
        $draft = Survey::create([...$builder, 'school_id' => $this->school->id]);
        $raw = $draft->getRawOriginal('questions');
        $state = SurveyEditorData::fromDefinition($draft->toArray());
        $state['builder'] = SurveyEditorData::definition($state);

        $this->assertSame('Atstāt kontaktinformāciju', $state['builder']['questions'][0]['contact']['prompt']);
        $this->assertTrue($state['builder']['questions'][0]['contact']['fields'][0]['required']);
        $this->assertFalse($state['builder']['questions'][0]['contact']['fields'][1]['required']);
        $saved = SurveyWorkflow::update($draft, $state);
        $this->assertSame($raw, $saved->fresh()->getRawOriginal('questions'));
        $published = SurveyWorkflow::publish($saved);
        $this->assertSame($raw, $published->fresh()->getRawOriginal('questions'));
        $published = SurveyWorkflow::update($published, $state);
        $this->assertSame($raw, $published->fresh()->getRawOriginal('questions'));
        $this->assertSame('q_help', $published->questions[0]['key']);
        $this->assertSame([], $published->archived_questions);
    }

    #[DataProvider('defaultTriggerOptions')]
    public function test_explicit_contact_defaults_supply_the_requested_copy_and_fields(array $options, array $triggers): void
    {
        $contact = SurveyEditorData::defaultQuestionContact(['type' => 'single', 'options' => $options]);

        $this->assertEqualsCanonicalizing(['prompt', 'purpose', 'trigger_values', 'fields'], array_keys($contact));
        $this->assertSame('Atstāt kontaktinformāciju', $contact['prompt']);
        $this->assertSame('Kontaktinformācija paredzēta šī jautājuma aktivitātes organizēšanai. Tā netiek piesaistīta citām aptaujas atbildēm.', $contact['purpose']);
        $this->assertSame($triggers, $contact['trigger_values']);
        $this->assertSame(['name', 'email'], array_column($contact['fields'], 'key'));
        $this->assertSame(['text', 'email'], array_column($contact['fields'], 'type'));
        $this->assertSame([true, true], array_column($contact['fields'], 'required'));
        foreach ($contact['fields'] as $field) {
            $this->assertEqualsCanonicalizing(['key', 'label', 'type', 'required'], array_keys($field));
            $this->assertIsString($field['label']);
            $this->assertNotSame('', $field['label']);
        }
    }

    public static function choiceTypes(): array
    {
        return [['single'], ['multiple']];
    }

    #[DataProvider('choiceTypes')]
    public function test_choice_contacts_can_reuse_field_keys_and_triggers_in_distinct_questions(string $type): void
    {
        $builder = $this->builder();
        $builder['questions'][0]['type'] = $type;
        $builder['questions'][0]['contact'] = self::contact();
        $builder['questions'][0]['contact']['trigger_values'] = ['0', 'yes'];
        $builder['questions'][] = [...$builder['questions'][0], 'key' => 'q_second', 'title' => 'Vai palīdzēsiet citā pasākumā?'];

        $draft = SurveyWorkflow::create(['builder' => $builder]);
        foreach ($draft->fresh()->questions as $question) {
            $this->assertSame($builder['questions'][0]['contact'], $question['contact']);
        }
        $state = SurveyEditorData::fromDefinition($draft->toArray());
        $saved = SurveyWorkflow::update($draft, $state);
        $raw = $saved->getRawOriginal('questions');
        $published = SurveyWorkflow::publish($saved);

        $this->assertSame($raw, $published->fresh()->getRawOriginal('questions'));
    }

    public function test_contact_strings_are_unicode_trimmed_and_each_supported_field_type_and_boolean_are_preserved(): void
    {
        $contact = self::contact();
        $contact['fields'] = [
            ['key' => 'name', 'label' => 'Vārds', 'type' => 'text', 'required' => true],
            ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => false],
            ['key' => 'phone', 'label' => 'Tālrunis', 'type' => 'tel', 'required' => 1],
            ['key' => 'role', 'label' => 'Loma', 'type' => 'role', 'required' => 0],
            ['key' => 'detail_0123456789abcdef0123456789abcdef', 'label' => 'Piezīme', 'type' => 'text', 'required' => '1'], // gitleaks:allow -- synthetic field identifier
            ['key' => 'detail_01234567-89ab-cdef-0123-456789abcdef', 'label' => 'Cits e-pasts', 'type' => 'email', 'required' => '0'], // gitleaks:allow -- synthetic field identifier
        ];
        $expected = $contact;
        foreach ([true, false, true, false, true, false] as $index => $required) {
            $expected['fields'][$index]['required'] = $required;
        }
        $padding = "\u{3000}\u{00A0}\u{200B} ";
        $contact['prompt'] = $padding.$contact['prompt'].$padding;
        $contact['purpose'] = $padding.$contact['purpose'].$padding;
        foreach ($contact['fields'] as &$field) {
            $field['label'] = $padding.$field['label'].$padding;
        }
        unset($field);
        $builder = $this->builder();
        $builder['questions'][0]['contact'] = $contact;

        $this->assertSame($expected, SurveyWorkflow::definition(['builder' => $builder])['questions'][0]['contact']);
        $draft = SurveyWorkflow::create(['builder' => $builder]);
        $this->assertSame($expected, $draft->fresh()->questions[0]['contact']);
        $this->assertSame($expected, SurveyEditorData::fromDefinition($draft->toArray())['builder']['questions'][0]['contact']);
    }

    public function test_contact_accepts_exact_text_limits_and_one_to_twelve_fields(): void
    {
        $contact = self::contact();
        $contact['prompt'] = str_repeat('ā', 160);
        $contact['purpose'] = str_repeat('ē', 1000);
        $contact['fields'] = self::fields(12);
        $contact['fields'][0]['label'] = str_repeat('ī', 160);
        $builder = $this->builder();
        $builder['questions'][0]['contact'] = $contact;

        $draft = SurveyWorkflow::create(['builder' => $builder]);
        $this->assertSame($contact, $draft->fresh()->questions[0]['contact']);
        $builder['questions'][0]['contact']['fields'] = [$contact['fields'][0]];
        $saved = SurveyWorkflow::update($draft, ['builder' => $builder]);
        $this->assertSame([$contact['fields'][0]], $saved->fresh()->questions[0]['contact']['fields']);
    }

    public function test_draft_contact_can_be_added_edited_cleared_and_removed_through_workflow(): void
    {
        $draft = SurveyWorkflow::create(['builder' => $this->builder()]);
        $this->assertArrayNotHasKey('contact', $draft->questions[0]);
        $state = SurveyEditorData::fromDefinition($draft->toArray());
        $state['builder']['questions'][0]['contact'] = self::contact();
        $draft = SurveyWorkflow::update($draft, $state);
        $this->assertSame(self::contact(), $draft->fresh()->questions[0]['contact']);

        $questions = $draft->questions;
        $questions[0]['contact'] = [
            'prompt' => 'Sazināties par pasākumu',
            'purpose' => 'Saziņai par šīs aktivitātes norises laiku.',
            'trigger_values' => ['0', 'yes'],
            'fields' => [['key' => 'phone', 'label' => 'Jūsu tālrunis', 'type' => 'tel', 'required' => false]],
        ];
        $draft = SurveyWorkflow::update($draft, ['questions' => $questions]);
        $this->assertSame($questions, $draft->fresh()->questions);

        $state = SurveyEditorData::fromDefinition($draft->toArray());
        $state['builder']['questions'][0]['contact'] = null;
        $draft = SurveyWorkflow::update($draft, $state);
        $this->assertArrayHasKey('contact', $draft->questions[0]);
        $this->assertNull($draft->questions[0]['contact']);

        unset($state['builder']['questions'][0]['contact']);
        $draft = SurveyWorkflow::update($draft, $state);
        $this->assertArrayNotHasKey('contact', $draft->fresh()->questions[0]);
        $this->assertNull($draft->published_at);
    }

    public function test_workflow_duplicate_preserves_contact_json_in_an_independently_editable_draft(): void
    {
        $builder = $this->builder();
        $builder['questions'][0]['contact'] = self::contact();
        $published = SurveyWorkflow::publish(SurveyWorkflow::create(['builder' => $builder]));
        $rawQuestions = $published->getRawOriginal('questions');
        $rawSections = $published->getRawOriginal('sections');

        $copy = SurveyWorkflow::duplicate($published);
        $this->assertNotSame($published->id, $copy->id);
        $this->assertSame('draft', $copy->status);
        $this->assertNull($copy->published_at);
        $this->assertSame($rawQuestions, $copy->getRawOriginal('questions'));
        $this->assertSame($rawSections, $copy->getRawOriginal('sections'));
        $state = SurveyEditorData::fromDefinition($copy->toArray());
        $state['builder']['questions'][0]['contact']['prompt'] = 'Cits uzaicinājums';
        $saved = SurveyWorkflow::update($copy, $state);

        $this->assertSame('Cits uzaicinājums', $saved->fresh()->questions[0]['contact']['prompt']);
        $this->assertSame('draft', $saved->status);
        $this->assertSame($rawQuestions, $published->fresh()->getRawOriginal('questions'));
    }

    public static function publishedStates(): array
    {
        return [['open'], ['closed']];
    }

    #[DataProvider('publishedStates')]
    public function test_published_contact_labels_remain_editable_and_structural_changes_archive_definitions_including_stale_drafts(string $status): void
    {
        $builder = $this->builder();
        $builder['questions'][0]['contact'] = self::contact();
        $staleDraft = SurveyWorkflow::create(['builder' => $builder]);
        $published = SurveyWorkflow::publish($staleDraft->fresh());
        if ($status === 'closed') {
            $published = SurveyWorkflow::close($published);
        }
        $publishedAt = $published->published_at;

        foreach ([$staleDraft, $published] as $index => $record) {
            $state = SurveyEditorData::fromDefinition($record->fresh()->toArray());
            $invalid = $state;
            $invalid['builder']['questions'][0]['contact']['fields'] = 'malformed';
            $invalid['estimated_minutes'] = 12 + $index;
            $before = $record->fresh();
            $this->assertContactRejected(fn () => SurveyWorkflow::update($record, $invalid), 'questions.0.contact.fields');
            $this->assertSame($before->getRawOriginal('questions'), $record->fresh()->getRawOriginal('questions'));
            $this->assertSame($before->estimated_minutes, $record->fresh()->estimated_minutes);

            $state['builder']['questions'][0]['contact']['fields'][0]['label'] = 'Precizēts vārda lauks '.$index;
            $state['estimated_minutes'] = 11;
            $saved = SurveyWorkflow::update($record, $state);
            $this->assertSame('q_help', $saved->questions[0]['key']);
            $this->assertSame('Precizēts vārda lauks '.$index, $saved->questions[0]['contact']['fields'][0]['label']);
            $this->assertSame([], $saved->archived_questions);
            $this->assertFalse($saved->allow_identified_responses);
            $this->assertSame($status, $saved->status);
            $this->assertSame(11, $saved->estimated_minutes);
            $this->assertTrue($publishedAt->equalTo($saved->published_at));
        }

        foreach ([$staleDraft, $published] as $index => $record) {
            $questions = $record->fresh()->questions;
            $original = $questions[0];
            if ($index === 0) {
                $questions[0]['contact']['fields'][] = ['key' => 'phone', 'label' => 'Tālrunis', 'type' => 'tel', 'required' => false];
            } else {
                unset($questions[0]['contact']);
            }
            $record->update(['questions' => $questions]);
            $saved = $record->fresh();
            $this->assertMatchesRegularExpression('/^q_[a-f0-9]{32}$/', $saved->questions[0]['key']);
            $this->assertNotSame($original['key'], $saved->questions[0]['key']);
            $this->assertCount($index + 1, $saved->archived_questions);
            $this->assertContains($original, $saved->archived_questions);
            $questions[0]['key'] = $saved->questions[0]['key'];
            $this->assertSame($questions, $saved->questions);
            $this->assertSame($status, $saved->status);
            $this->assertTrue($publishedAt->equalTo($saved->published_at));
        }
    }

    public function test_global_identification_and_question_contact_are_mutually_exclusive_on_create_update_and_publish(): void
    {
        $builder = $this->builder();
        $builder['questions'][0]['contact'] = self::contact();
        $identifiedBuilder = [...$builder, 'allow_identified_responses' => true];
        $count = Survey::count();
        $this->assertContactRejected(fn () => SurveyWorkflow::create(['builder' => $identifiedBuilder]), 'questions.0.contact');
        $this->assertSame($count, Survey::count());

        $draft = SurveyWorkflow::create(['builder' => $builder]);
        $raw = $draft->getRawOriginal('questions');
        $this->assertContactRejected(fn () => SurveyWorkflow::update($draft, ['allow_identified_responses' => true]), 'questions.0.contact');
        $this->assertFalse($draft->fresh()->allow_identified_responses);
        $this->assertSame($raw, $draft->fresh()->getRawOriginal('questions'));

        $state = SurveyEditorData::fromDefinition($draft->toArray());
        $state['builder']['questions'][0]['contact'] = null;
        $state['builder']['allow_identified_responses'] = true;
        $identified = SurveyWorkflow::update($draft, $state);
        $this->assertTrue($identified->allow_identified_responses);
        $state['builder']['questions'][0]['contact'] = self::contact();
        $this->assertContactRejected(fn () => SurveyWorkflow::update($identified, $state), 'questions.0.contact');
        $this->assertNull($identified->fresh()->questions[0]['contact']);
        $this->assertSame('open', SurveyWorkflow::publish($identified)->status);

        $legacy = Survey::create([...$builder, 'school_id' => $this->school->id]);
        DB::table('surveys')->where('id', $legacy->id)->update(['allow_identified_responses' => true]);
        $legacy->refresh();
        $this->assertContactRejected(fn () => SurveyWorkflow::publish($legacy), 'questions.0.contact');
        $this->assertSame('draft', $legacy->fresh()->status);
        $this->assertNull($legacy->fresh()->published_at);
    }

    public function test_contact_trigger_must_belong_to_its_own_question(): void
    {
        $builder = $this->builder();
        $builder['questions'][0]['contact'] = [...self::contact(), 'trigger_values' => ['elsewhere']];
        $builder['questions'][] = [
            ...$builder['questions'][0], 'key' => 'q_second', 'contact' => null,
            'options' => [['value' => 'elsewhere', 'label' => 'Cita jautājuma atbilde']],
        ];

        $this->assertContactRejected(fn () => SurveyWorkflow::definition(['builder' => $builder]), 'questions.0.contact.trigger_values');
    }

    public function test_invalid_draft_edits_do_not_save_until_contact_is_removed(): void
    {
        $builder = $this->builder();
        $builder['questions'][0]['contact'] = self::contact();
        $draft = SurveyWorkflow::create(['builder' => $builder]);
        $raw = $draft->getRawOriginal('questions');
        $withoutTrigger = $builder;
        array_shift($withoutTrigger['questions'][0]['options']);
        $this->assertContactRejected(fn () => SurveyWorkflow::update($draft, ['builder' => $withoutTrigger]), 'questions.0.contact.trigger_values');
        $this->assertSame($raw, $draft->fresh()->getRawOriginal('questions'));

        $builder['questions'][0]['type'] = 'text';
        $this->assertContactRejected(fn () => SurveyWorkflow::update($draft, ['builder' => $builder]), 'questions.0.contact');
        $this->assertSame($raw, $draft->fresh()->getRawOriginal('questions'));
        $builder['questions'][0]['contact'] = null;
        $saved = SurveyWorkflow::update($draft, ['builder' => $builder]);
        $this->assertSame('text', $saved->fresh()->questions[0]['type']);
        $this->assertNull($saved->questions[0]['contact']);
    }

    public function test_publication_revalidates_malformed_stored_contact_without_freezing_the_draft(): void
    {
        $builder = $this->builder();
        $builder['questions'][0]['contact'] = self::contact();
        $builder['questions'][0]['contact']['fields'][0]['label'] = ['malformed'];
        $draft = Survey::create([...$builder, 'school_id' => $this->school->id]);
        $raw = $draft->getRawOriginal('questions');

        $this->assertContactRejected(fn () => SurveyWorkflow::publish($draft), 'questions.0.contact.fields.0.label');
        $this->assertSame($raw, $draft->fresh()->getRawOriginal('questions'));
        $this->assertSame('draft', $draft->fresh()->status);
        $this->assertNull($draft->fresh()->published_at);
    }

    public static function invalidContacts(): array
    {
        $contact = self::contact();
        $field = $contact['fields'][0];
        $cases = [
            'scalar contact' => ['invalid', ''],
            'blank contact' => ['', ''],
            'false contact' => [false, ''],
            'zero contact' => [0, ''],
            'list contact' => [[$contact], ''],
            'empty contact' => [[], ''],
            'object contact' => [new \stdClass, ''],
            'unknown contact key' => [[...$contact, 'family_id' => 1], ''],
            'null prompt' => [[...$contact, 'prompt' => null], '.prompt'],
            'nested prompt' => [[...$contact, 'prompt' => ['invalid']], '.prompt'],
            'object prompt' => [[...$contact, 'prompt' => new \stdClass], '.prompt'],
            'numeric prompt' => [[...$contact, 'prompt' => 123], '.prompt'],
            'unicode blank prompt' => [[...$contact, 'prompt' => "\u{3000}\u{200B}"], '.prompt'],
            'prompt too long' => [[...$contact, 'prompt' => str_repeat('ā', 161)], '.prompt'],
            'null purpose' => [[...$contact, 'purpose' => null], '.purpose'],
            'nested purpose' => [[...$contact, 'purpose' => ['invalid']], '.purpose'],
            'numeric purpose' => [[...$contact, 'purpose' => 123], '.purpose'],
            'unicode blank purpose' => [[...$contact, 'purpose' => "\u{3000}\u{200B}"], '.purpose'],
            'purpose too long' => [[...$contact, 'purpose' => str_repeat('ē', 1001)], '.purpose'],
            'null triggers' => [[...$contact, 'trigger_values' => null], '.trigger_values'],
            'scalar triggers' => [[...$contact, 'trigger_values' => 'yes'], '.trigger_values'],
            'object triggers' => [[...$contact, 'trigger_values' => new \stdClass], '.trigger_values'],
            'empty triggers' => [[...$contact, 'trigger_values' => []], '.trigger_values'],
            'map triggers' => [[...$contact, 'trigger_values' => ['selected' => 'yes']], '.trigger_values'],
            'sparse triggers' => [[...$contact, 'trigger_values' => [1 => 'yes']], '.trigger_values'],
            'nested trigger' => [[...$contact, 'trigger_values' => [['yes']]], '.trigger_values.0'],
            'null trigger' => [[...$contact, 'trigger_values' => [null]], '.trigger_values.0'],
            'numeric zero trigger' => [[...$contact, 'trigger_values' => [0]], '.trigger_values.0'],
            'boolean trigger' => [[...$contact, 'trigger_values' => [false]], '.trigger_values.0'],
            'blank trigger' => [[...$contact, 'trigger_values' => ['']], '.trigger_values'],
            'unknown trigger' => [[...$contact, 'trigger_values' => ['missing']], '.trigger_values'],
            'literal other trigger' => [[...$contact, 'trigger_values' => ['other']], '.trigger_values'],
            'duplicate trigger' => [[...$contact, 'trigger_values' => ['yes', 'yes']], '.trigger_values'],
            'null fields' => [[...$contact, 'fields' => null], '.fields'],
            'scalar fields' => [[...$contact, 'fields' => 'invalid'], '.fields'],
            'object fields' => [[...$contact, 'fields' => new \stdClass], '.fields'],
            'empty fields' => [[...$contact, 'fields' => []], '.fields'],
            'map fields' => [[...$contact, 'fields' => ['email' => $field]], '.fields'],
            'sparse fields' => [[...$contact, 'fields' => [1 => $field]], '.fields'],
            'too many fields' => [[...$contact, 'fields' => self::fields(13)], '.fields'],
            'scalar field' => [[...$contact, 'fields' => ['invalid']], '.fields.0'],
            'null field' => [[...$contact, 'fields' => [null]], '.fields.0'],
            'list field' => [[...$contact, 'fields' => [[$field]]], '.fields.0'],
            'duplicate field key' => [[...$contact, 'fields' => [$field, $field]], '.fields'],
            'unknown field key' => [[...$contact, 'fields' => [[...$field, 'family_id' => 1]]], '.fields.0'],
            'invalid field identifier' => [[...$contact, 'fields' => [[...$field, 'key' => 'family_id']]], '.fields.0.key'],
            'invalid compact custom identifier' => [[...$contact, 'fields' => [[...$field, 'key' => 'detail_'.str_repeat('a', 31)]]], '.fields.0.key'],
            'uppercase custom identifier' => [[...$contact, 'fields' => [[...$field, 'key' => 'detail_'.str_repeat('A', 32)]]], '.fields.0.key'],
            'malformed custom uuid' => [[...$contact, 'fields' => [[...$field, 'key' => 'detail_01234567-89ab-cdef-0123-456789abcdeg']]], '.fields.0.key'], // gitleaks:allow -- synthetic field identifier
            'nested field identifier' => [[...$contact, 'fields' => [[...$field, 'key' => ['name']]]], '.fields.0.key'],
            'null field identifier' => [[...$contact, 'fields' => [[...$field, 'key' => null]]], '.fields.0.key'],
            'unicode blank label' => [[...$contact, 'fields' => [[...$field, 'label' => "\u{3000}\u{200B}"]]], '.fields.0.label'],
            'label too long' => [[...$contact, 'fields' => [[...$field, 'label' => str_repeat('ī', 161)]]], '.fields.0.label'],
            'nested label' => [[...$contact, 'fields' => [[...$field, 'label' => ['invalid']]]], '.fields.0.label'],
            'numeric label' => [[...$contact, 'fields' => [[...$field, 'label' => 123]]], '.fields.0.label'],
            'null label' => [[...$contact, 'fields' => [[...$field, 'label' => null]]], '.fields.0.label'],
            'invalid type' => [[...$contact, 'fields' => [[...$field, 'type' => 'file']]], '.fields.0.type'],
            'nested type' => [[...$contact, 'fields' => [[...$field, 'type' => ['text']]]], '.fields.0.type'],
            'object type' => [[...$contact, 'fields' => [[...$field, 'type' => new \stdClass]]], '.fields.0.type'],
            'null type' => [[...$contact, 'fields' => [[...$field, 'type' => null]]], '.fields.0.type'],
            'invalid required flag' => [[...$contact, 'fields' => [[...$field, 'required' => 'optional']]], '.fields.0.required'],
            'nested required flag' => [[...$contact, 'fields' => [[...$field, 'required' => [true]]]], '.fields.0.required'],
            'null required flag' => [[...$contact, 'fields' => [[...$field, 'required' => null]]], '.fields.0.required'],
        ];
        foreach (['prompt', 'purpose', 'trigger_values', 'fields'] as $key) {
            $missing = $contact;
            unset($missing[$key]);
            $cases['missing '.$key] = [$missing, '.'.$key];
        }
        foreach (['key', 'label', 'type', 'required'] as $key) {
            $missing = $field;
            unset($missing[$key]);
            $cases['missing field '.$key] = [[...$contact, 'fields' => [$missing]], '.fields.0.'.$key];
        }

        return $cases;
    }

    #[DataProvider('invalidContacts')]
    public function test_invalid_contact_shapes_report_numeric_paths_and_latvian_validation_errors(mixed $contact, string $suffix): void
    {
        $builder = $this->builder();
        $builder['questions'][0]['contact'] = $contact;

        $this->assertContactRejected(fn () => SurveyWorkflow::definition(['builder' => $builder]), 'questions.0.contact'.$suffix);
    }
}
