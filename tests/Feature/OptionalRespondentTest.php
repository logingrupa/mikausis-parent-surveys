<?php

namespace Tests\Feature;

use App\Filament\Support\SurveyEditorData;
use App\Filament\Support\SurveyWorkflow;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Models\User;
use App\Services\SurveySubmissionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class OptionalRespondentTest extends SurveyTestCase
{
    private function identifiedSurvey(?array $fields = null): Survey
    {
        $survey = $this->survey->duplicate();
        $survey->update(['allow_identified_responses' => true, 'respondent_fields' => $fields, 'status' => 'open']);

        return $survey;
    }

    private function namedPayload(array $extra = []): array
    {
        return $this->payload(extra: array_replace([
            'anonymous' => false, 'respondent_name' => 'Anna Bērziņa', 'respondent_role' => 'mother',
        ], $extra));
    }

    private function legacyDetails(string $name = 'Anna Bērziņa', string $role = 'mother'): array
    {
        return ['fields' => [
            ['key' => 'name', 'label' => 'Vārds un uzvārds', 'type' => 'text', 'value' => $name],
            ['key' => 'role', 'label' => 'Loma', 'type' => 'role', 'value' => $role],
        ]];
    }

    private function assertRejected(Survey $survey, array $payload, string $field, $invitation = null): void
    {
        try {
            app(SurveySubmissionService::class)->submit($survey, $payload, $invitation);
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_migration_keeps_existing_surveys_and_responses_anonymous_without_changing_questions(): void
    {
        $response = $this->survey->responses()->create(['answers' => ['q1' => ['value' => 'yes', 'other' => null]]]);
        $questions = $this->survey->getRawOriginal('questions');
        $migration = require database_path('migrations/2026_10_29_000100_add_optional_respondent_details.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('surveys', 'allow_identified_responses'));
        $migration->up();

        $this->assertFalse($this->survey->fresh()->allow_identified_responses);
        $this->assertNull($this->survey->fresh()->respondent_fields);
        $this->assertSame([], $this->survey->fresh()->respondentFields());
        $this->assertNull($response->fresh()->respondent_details);
        $this->assertSame($questions, $this->survey->fresh()->getRawOriginal('questions'));
        $this->assertSame(['id', 'survey_id', 'answers', 'respondent_details'], Schema::getColumnListing('survey_responses'));
        $this->assertSame(['id', 'invitation_id', 'role'], Schema::getColumnListing('family_participations'));
    }

    public static function anonymousSelections(): array
    {
        return ['legacy missing' => [[]], 'checked boolean' => [['anonymous' => true]], 'checked form' => [['anonymous' => '1']]];
    }

    #[DataProvider('anonymousSelections')]
    public function test_anonymous_submissions_drop_identity_without_validating_it(array $selection): void
    {
        foreach ([$this->survey, $this->identifiedSurvey()] as $survey) {
            $response = app(SurveySubmissionService::class)->submit($survey, $this->payload(extra: [
                ...$selection, 'respondent_name' => ['injected' => str_repeat('Private Person', 100)],
                'respondent' => ['name' => ['invalid'], 'email' => 'injected@example.com'],
                'respondent_role' => ['invalid'], 'respondent_details' => ['name' => 'Injected Person', 'role' => 'mother'],
                'family_id' => 99, 'ip' => '127.0.0.1', 'created_at' => now(), 'role' => 'father',
            ]));
            $this->assertNull($response->fresh()->respondent_details);
            $this->assertNull(DB::table('survey_responses')->where('id', $response->id)->value('respondent_details'));
            $this->assertSame(['q1' => ['value' => 'yes', 'other' => null]], $response->fresh()->answers);
        }
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_anonymous_family_response_keeps_the_role_claim_separate_and_drops_respondent_details(): void
    {
        $invitation = $this->invitation();
        $response = app(SurveySubmissionService::class)->submit($this->survey, $this->payload(extra: [
            'anonymous' => true, 'respondent_name' => ['invalid'], 'respondent_role' => 'child', 'role' => 'guardian',
        ]), $invitation);
        $this->assertNull($response->fresh()->respondent_details);
        $this->assertDatabaseHas('family_participations', ['invitation_id' => $invitation->id, 'role' => 'guardian']);
        $this->assertArrayNotHasKey('invitation_id', $response->getAttributes());
        $this->assertArrayNotHasKey('role', $response->getAttributes());
        $this->assertFalse($response->timestamps);
    }

    public function test_disabled_survey_rejects_named_responses_using_current_persisted_setting(): void
    {
        $this->survey->allow_identified_responses = true;
        $this->assertRejected($this->survey, $this->namedPayload(['respondent_name' => [], 'respondent_role' => []]), 'anonymous');
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_named_details_are_unicode_trimmed_encrypted_and_limited_to_name_and_role(): void
    {
        $survey = $this->identifiedSurvey();
        $response = app(SurveySubmissionService::class)->submit($survey, $this->namedPayload([
            'anonymous' => '0', 'respondent_name' => "\u{00A0}\u{200B}Anna Bērziņa\u{3000}",
            'respondent_details' => ['family_id' => 99], 'family_id' => 99, 'ip' => '127.0.0.1',
        ]));
        $this->assertSame($this->legacyDetails(), $response->fresh()->respondent_details);
        $raw = DB::table('survey_responses')->where('id', $response->id)->value('respondent_details');
        $this->assertNotEmpty($raw);
        $this->assertStringNotContainsString('Anna', $raw);
        $this->assertStringNotContainsString('mother', $raw);
        $this->assertArrayNotHasKey('respondent_details', $response->toArray());
        $this->assertDatabaseCount('family_participations', 0);
    }

    public static function invalidIdentity(): array
    {
        return [
            'missing name' => [['respondent_name' => null], 'respondent.name'],
            'unicode blank name' => [['respondent_name' => "\u{00A0}\u{200B}\u{3000}"], 'respondent.name'],
            'array name' => [['respondent_name' => ['Anna']], 'respondent.name'],
            'long unicode name' => [['respondent_name' => str_repeat('ā', 161)], 'respondent.name'],
            'missing role' => [['respondent_role' => null], 'respondent.role'],
            'invalid role' => [['respondent_role' => 'child'], 'respondent.role'],
            'array role' => [['respondent_role' => ['mother']], 'respondent.role'],
            'malformed respondent' => [['respondent' => 'invalid'], 'respondent'],
            'invalid anonymous selection' => [['anonymous' => 'false'], 'anonymous'],
            'array anonymous selection' => [['anonymous' => [false]], 'anonymous'],
        ];
    }

    #[DataProvider('invalidIdentity')]
    public function test_named_validation_rejects_invalid_details_without_writes(array $extra, string $field): void
    {
        $this->assertRejected($this->identifiedSurvey(), $this->namedPayload($extra), $field);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_name_limit_counts_unicode_characters_after_trimming(): void
    {
        $response = app(SurveySubmissionService::class)->submit($this->identifiedSurvey(), $this->namedPayload([
            'respondent_name' => "\u{3000}".str_repeat('ā', 160)."\u{00A0}",
        ]));
        $this->assertSame(str_repeat('ā', 160), $response->fresh()->respondent_details['fields'][0]['value']);
    }

    public function test_named_family_response_uses_validated_participation_role_and_ignores_a_second_picker(): void
    {
        $survey = $this->identifiedSurvey();
        $invitation = $this->invitation($survey);
        $service = app(SurveySubmissionService::class);
        $response = $service->submit($survey, $this->namedPayload(['role' => 'father', 'respondent_role' => ['invalid']]), $invitation);
        $this->assertSame($this->legacyDetails(role: 'father'), $response->fresh()->respondent_details);
        $this->assertDatabaseHas('family_participations', ['invitation_id' => $invitation->id, 'role' => 'father']);
        $this->assertRejected($survey, $this->namedPayload(['role' => 'child', 'respondent_role' => 'mother']), 'role', $invitation);
        $this->assertRejected($survey, $this->namedPayload(['role' => 'father']), 'role', $invitation);
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 1);
    }

    public function test_invalid_named_family_submission_does_not_claim_the_role(): void
    {
        $survey = $this->identifiedSurvey();
        $invitation = $this->invitation($survey);
        $this->assertRejected($survey, $this->namedPayload(['role' => 'mother', 'respondent_name' => '']), 'respondent.name', $invitation);
        $payload = $this->namedPayload(['role' => 'mother']);
        $payload['answers'] = ['q1' => 'invalid'];
        $this->assertRejected($survey, $payload, 'answers.q1', $invitation);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_named_response_insert_failure_rolls_back_the_family_claim(): void
    {
        $survey = $this->identifiedSurvey();
        $invitation = $this->invitation($survey);
        DB::unprepared("CREATE TRIGGER reject_named_response BEFORE INSERT ON survey_responses BEGIN SELECT RAISE(ABORT, 'named insert failure'); END");
        try {
            app(SurveySubmissionService::class)->submit($survey, $this->namedPayload(['role' => 'mother']), $invitation);
            $this->fail('Expected insert failure.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('named insert failure', $exception->getMessage());
        } finally {
            DB::unprepared('DROP TRIGGER reject_named_response');
        }
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_retry_preserves_identity_and_ciphertext_even_if_new_details_are_invalid_or_survey_closed(): void
    {
        $survey = $this->identifiedSurvey();
        $invitation = $this->invitation($survey);
        $payload = $this->namedPayload(['role' => 'guardian']);
        $service = app(SurveySubmissionService::class);
        $first = $service->submit($survey, $payload, $invitation);
        $ciphertext = $first->getRawOriginal('respondent_details');
        $survey->update(['status' => 'closed']);
        foreach ([['anonymous' => true], ['respondent_name' => [], 'respondent_role' => []], ['respondent_name' => 'Someone Else']] as $extra) {
            $retry = $service->submit($survey, array_replace($payload, $extra), $invitation);
            $this->assertSame($first->id, $retry->id);
            $this->assertSame($this->legacyDetails(role: 'guardian'), $retry->respondent_details);
            $this->assertSame($ciphertext, $retry->getRawOriginal('respondent_details'));
        }
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 1);
    }

    public function test_anonymous_retry_cannot_be_upgraded_to_named(): void
    {
        $survey = $this->identifiedSurvey();
        $payload = $this->payload();
        $service = app(SurveySubmissionService::class);
        $first = $service->submit($survey, $payload);
        $retry = $service->submit($survey, $this->namedPayload(['submission_id' => $payload['submission_id']]));
        $this->assertSame($first->id, $retry->id);
        $this->assertNull($retry->fresh()->respondent_details);
        $this->assertDatabaseCount('survey_responses', 1);
    }

    public function test_named_submissions_cannot_cross_invitation_or_response_tenants(): void
    {
        $survey = $this->identifiedSurvey();
        $foreign = $this->otherSurvey();
        $this->assertRejected($survey, $this->namedPayload(['role' => 'mother']), 'invitation', $this->invitation($foreign));
        $payload = $this->namedPayload();
        $response = app(SurveySubmissionService::class)->submit($survey, $payload);
        $this->assertRejected($foreign, $payload, 'submission_id');
        $ownUser = User::factory()->create(['school_id' => $survey->school_id]);
        $foreignUser = User::factory()->create(['school_id' => $foreign->school_id]);
        $this->assertSame([$response->id], SurveyResponse::accessibleTo($ownUser)->pluck('id')->all());
        $this->assertSame(0, SurveyResponse::accessibleTo($foreignUser)->count());
        $this->assertDatabaseCount('survey_responses', 1);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_editor_defaults_round_trip_and_copy_preserve_the_setting_without_changing_question_json(): void
    {
        $this->assertFalse(SurveyEditorData::empty()['allow_identified_responses']);
        $this->assertSame([], SurveyEditorData::empty()['respondent_fields']);
        $this->assertFalse(SurveyEditorData::fromDefinition([])['builder']['allow_identified_responses']);
        $this->assertFalse(SurveyEditorData::definition(['title' => 'Legacy'])['allow_identified_responses']);
        $admin = User::factory()->create(['school_id' => $this->school->id, 'is_admin' => true]);
        $this->actingAs($admin);
        $draft = $this->survey->duplicate();
        $questions = $draft->getRawOriginal('questions');
        $state = SurveyEditorData::fromDefinition($draft->toArray());
        $state['builder']['allow_identified_responses'] = true;
        SurveyWorkflow::update($draft, $state);
        $this->assertTrue($draft->fresh()->allow_identified_responses);
        $this->assertSame($questions, $draft->fresh()->getRawOriginal('questions'));
        $this->assertTrue(SurveyEditorData::fromDefinition($draft->fresh()->toArray())['builder']['allow_identified_responses']);
        $published = SurveyWorkflow::publish($draft);
        $this->assertTrue($published->allow_identified_responses);
        $copy = SurveyWorkflow::duplicate($published);
        $this->assertTrue($copy->allow_identified_responses);
        $copy->update(['allow_identified_responses' => false]);
        $this->assertFalse($copy->fresh()->allow_identified_responses);
        $this->assertTrue($published->fresh()->allow_identified_responses);
        foreach ([$this->survey, $published] as $editable) {
            $before = $editable->allow_identified_responses;
            $state = SurveyEditorData::fromDefinition($editable->fresh()->toArray());
            $state['builder']['allow_identified_responses'] = ! $before;
            SurveyWorkflow::update($editable, $state);
            $this->assertSame(! $before, $editable->fresh()->allow_identified_responses);
            $this->assertSame($questions, $editable->fresh()->getRawOriginal('questions'));
            $this->assertSame('open', $editable->fresh()->status);
        }
    }

    public function test_stale_draft_model_can_change_published_identity_setting_before_any_records_exist(): void
    {
        $draft = $this->survey->duplicate();
        $published = $draft->fresh();
        $published->update(['status' => 'open']);
        $draft->update(['allow_identified_responses' => true]);

        $saved = $draft->fresh();
        $this->assertTrue($saved->allow_identified_responses);
        $this->assertSame('open', $saved->status);
        $this->assertTrue($published->published_at->equalTo($saved->published_at));
    }

    public function test_form_validation_recovers_named_fields_only_in_current_request_without_session_flashing(): void
    {
        $survey = $this->identifiedSurvey();
        $url = route('surveys.submit', ['slug' => $survey->slug]);
        $id = $this->get($url)->assertOk()->viewData('submissionId');
        $payload = $this->payload(extra: ['submission_id' => $id, 'anonymous' => false, 'respondent' => ['name' => 'Private Name', 'role' => 'child']]);
        $this->post($url, $payload)->assertUnprocessable()->assertViewIs('surveys.show')
            ->assertViewHas('oldValues', [
                'answers' => $payload['answers'], 'anonymous' => false, 'respondent' => ['name' => 'Private Name', 'role' => 'child'],
            ])->assertViewHas('errors', fn ($errors) => $errors->has('respondent.role'))
            ->assertSessionMissing(['_old_input', 'respondent_name', 'respondent_role', 'errors']);
        $this->assertStringNotContainsString('Private Name', json_encode(session()->all(), JSON_THROW_ON_ERROR));
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('respondent.role')->assertJsonMissingPath('oldValues');
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_anonymous_validation_rerender_drops_injected_identity_from_old_values(): void
    {
        $url = route('surveys.submit', ['slug' => $this->survey->slug]);
        $id = $this->get($url)->assertOk()->viewData('submissionId');
        $payload = $this->payload(['q1' => 'invalid'], [
            'submission_id' => $id, 'anonymous' => true, 'respondent_name' => 'Injected Private Name', 'respondent_role' => ['invalid'],
            'respondent' => ['name' => 'Injected Private Name'],
        ]);
        $this->post($url, $payload)->assertUnprocessable()
            ->assertViewHas('oldValues', ['answers' => $payload['answers'], 'anonymous' => true])
            ->assertSessionMissing(['_old_input', 'respondent_name', 'respondent_role']);
        $this->assertStringNotContainsString('Injected Private Name', json_encode(session()->all(), JSON_THROW_ON_ERROR));
    }

    public function test_configured_details_preserve_labels_types_and_order_and_discard_unknown_or_empty_fields(): void
    {
        $custom = 'detail_'.Str::uuid();
        $fields = [
            ['key' => 'email', 'label' => 'Saziņas e-pasts', 'type' => 'email', 'required' => true],
            ['key' => $custom, 'label' => 'Kā jūs uzrunāt?', 'type' => 'text', 'required' => false],
            ['key' => 'phone', 'label' => 'Tālrunis', 'type' => 'tel', 'required' => false],
        ];
        $survey = $this->identifiedSurvey($fields);
        $response = app(SurveySubmissionService::class)->submit($survey, $this->namedPayload([
            'respondent_name' => ['not configured'], 'respondent_role' => ['not configured'],
            'respondent' => ['email' => "\u{3000}anna@example.com\u{00A0}", $custom => '  Anna  ', 'phone' => "\u{200B}", 'unknown' => ['discard']],
        ]));
        $this->assertSame(['fields' => [
            ['key' => 'email', 'label' => 'Saziņas e-pasts', 'type' => 'email', 'value' => 'anna@example.com'],
            ['key' => $custom, 'label' => 'Kā jūs uzrunāt?', 'type' => 'text', 'value' => 'Anna'],
        ]], $response->fresh()->respondent_details);
        $this->assertStringNotContainsString('anna@example.com', $response->getRawOriginal('respondent_details'));
    }

    public static function invalidConfiguredValues(): array
    {
        return [
            'required missing' => ['text', true, null],
            'unicode blank required' => ['text', true, "\u{3000}\u{200B}"],
            'text too long' => ['text', false, str_repeat('ā', 161)],
            'malformed text' => ['text', false, ['not text']],
            'email invalid' => ['email', false, 'not-an-email'],
            'email too long' => ['email', false, str_repeat('a', 247).'@test.lv'],
            'telephone too long' => ['tel', false, str_repeat('1', 41)],
            'telephone array' => ['tel', false, ['123']],
            'role invalid' => ['role', false, 'parent'],
        ];
    }

    #[DataProvider('invalidConfiguredValues')]
    public function test_custom_fields_enforce_configured_type_required_and_length(string $type, bool $required, mixed $value): void
    {
        $key = 'detail_'.Str::uuid();
        $survey = $this->identifiedSurvey([['key' => $key, 'label' => 'Mana informācija', 'type' => $type, 'required' => $required]]);
        $this->assertRejected($survey, $this->namedPayload(['respondent' => [$key => $value]]), 'respondent.'.$key);
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_all_optional_empty_fields_require_a_value_when_not_anonymous(): void
    {
        $survey = $this->identifiedSurvey([['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => false]]);
        $this->assertRejected($survey, $this->namedPayload(['respondent' => ['email' => '', 'unknown' => 'Private']]), 'respondent');
        $this->assertRejected($this->identifiedSurvey([]), $this->namedPayload(), 'respondent');
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_custom_role_field_inherits_validated_family_role_without_requiring_a_name(): void
    {
        $key = 'detail_'.Str::uuid();
        $survey = $this->identifiedSurvey([['key' => $key, 'label' => 'Jūsu saistība ar bērnu', 'type' => 'role', 'required' => true]]);
        $invitation = $this->invitation($survey);
        $response = app(SurveySubmissionService::class)->submit($survey, $this->payload(extra: [
            'anonymous' => false, 'role' => 'guardian', 'respondent' => [$key => ['invalid']],
        ]), $invitation);
        $this->assertSame(['fields' => [
            ['key' => $key, 'label' => 'Jūsu saistība ar bērnu', 'type' => 'role', 'value' => 'guardian'],
        ]], $response->fresh()->respondent_details);
        $this->assertDatabaseCount('family_participations', 1);
    }

    public function test_generic_inputs_take_priority_over_legacy_compatibility_fields(): void
    {
        $survey = $this->identifiedSurvey();
        $this->assertRejected($survey, $this->namedPayload(['respondent' => ['name' => null, 'role' => 'mother']]), 'respondent.name');
        $response = app(SurveySubmissionService::class)->submit($survey, $this->namedPayload([
            'respondent' => ['name' => 'New Name', 'role' => 'father'],
        ]));
        $this->assertSame($this->legacyDetails(name: 'New Name', role: 'father'), $response->fresh()->respondent_details);
    }

    public static function invalidFieldDefinitions(): array
    {
        $field = ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => false];

        return [
            'scalar schema' => ['invalid', 'respondent_fields'],
            'blank schema' => ['', 'respondent_fields'],
            'map schema' => [['field' => $field], 'respondent_fields'],
            'too many fields' => [array_fill(0, 13, $field), 'respondent_fields'],
            'scalar field' => [['invalid'], 'respondent_fields.0'],
            'duplicate key' => [[$field, $field], 'respondent_fields.0.key'],
            'invalid key' => [[array_replace($field, ['key' => 'family_id'])], 'respondent_fields.0.key'],
            'missing label' => [[array_replace($field, ['label' => "\u{3000}\u{200B}"])], 'respondent_fields.0.label'],
            'long label' => [[array_replace($field, ['label' => str_repeat('ā', 161)])], 'respondent_fields.0.label'],
            'invalid type' => [[array_replace($field, ['type' => 'file'])], 'respondent_fields.0.type'],
            'invalid required' => [[array_replace($field, ['required' => 'optional'])], 'respondent_fields.0.required'],
            'extra metadata' => [[array_replace($field, ['family_id' => 1])], 'respondent_fields.0'],
        ];
    }

    #[DataProvider('invalidFieldDefinitions')]
    public function test_editor_rejects_malformed_respondent_field_schemas(mixed $fields, string $path): void
    {
        try {
            SurveyWorkflow::definition(['title' => 'Valid survey', 'allow_identified_responses' => true, 'respondent_fields' => $fields]);
            $this->fail('Expected schema validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($path, $exception->errors());
        }
    }

    public function test_published_schema_can_change_before_records_and_only_labels_can_change_afterwards(): void
    {
        $this->actingAs(User::factory()->create(['school_id' => $this->school->id, 'is_admin' => true]));
        $draft = $this->survey->duplicate();
        $questions = $draft->getRawOriginal('questions');
        $state = SurveyEditorData::fromDefinition($draft->toArray());
        $state['builder']['allow_identified_responses'] = true;
        $state['builder']['respondent_fields'] = [['key' => 'phone', 'label' => '  Tālrunis  ', 'type' => 'tel', 'required' => false]];
        $saved = SurveyWorkflow::update($draft, $state);
        $fields = [['key' => 'phone', 'label' => 'Tālrunis', 'type' => 'tel', 'required' => false]];
        $this->assertSame($fields, $saved->respondentFields());
        $this->assertSame($questions, $saved->getRawOriginal('questions'));
        $this->assertSame($fields, SurveyEditorData::fromDefinition($saved->toArray())['builder']['respondent_fields']);
        $published = SurveyWorkflow::publish($saved);
        $this->assertSame($questions, $published->getRawOriginal('questions'));
        $fields = [['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => false]];
        SurveyWorkflow::update($draft, ['respondent_fields' => $fields]);
        $this->assertSame($fields, $published->fresh()->respondentFields());
        $this->assertSame($questions, $published->fresh()->getRawOriginal('questions'));
        $copy = SurveyWorkflow::duplicate($published);
        $this->assertSame($fields, $copy->respondentFields());
        $copy->update(['allow_identified_responses' => false, 'respondent_fields' => []]);
        $this->assertSame([], $copy->respondentFields());
        $this->assertSame($fields, $published->fresh()->respondentFields());

        $published->responses()->create(['answers' => ['q1' => ['value' => 'yes', 'other' => null]]]);
        try {
            $draft->update(['respondent_fields' => []]);
            $this->fail('Expected schema protection after a response exists.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('respondent_fields', $exception->errors());
        }
        $this->assertSame($fields, $published->fresh()->respondentFields());

        $fields[0]['label'] = 'Saziņas e-pasts';
        SurveyWorkflow::update($draft, ['respondent_fields' => $fields]);
        $this->assertSame($fields, $published->fresh()->respondentFields());
        $this->assertSame($questions, $published->fresh()->getRawOriginal('questions'));
    }
}
