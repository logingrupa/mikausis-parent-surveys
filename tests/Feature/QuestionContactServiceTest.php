<?php

namespace Tests\Feature;

use App\Models\QuestionContact;
use App\Models\Survey;
use App\Models\User;
use App\Services\QuestionContactService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class QuestionContactServiceTest extends SurveyTestCase
{
    private function contactSurvey(?array $fields = null): Survey
    {
        $survey = $this->survey->duplicate();
        $questions = $survey->questions;
        $contact = [
            'prompt' => 'Atstāt kontaktinformāciju',
            'purpose' => 'Saziņai par talku.',
            'trigger_values' => ['yes'],
            'fields' => $fields ?? [
                ['key' => 'name', 'label' => 'Vārds un uzvārds', 'type' => 'text', 'required' => true],
                ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true],
            ],
        ];
        $questions[0]['contact'] = $contact;
        $questions[1]['contact'] = [...$contact, 'trigger_values' => ['a', 'b', 'no']];
        $survey->update(['questions' => $questions, 'status' => 'open']);

        return $survey;
    }

    private function contactPayload(array $extra = []): array
    {
        return array_replace([
            'contact_id' => (string) Str::uuid(), 'consent' => true, 'answer' => 'yes',
            'fields' => ['name' => 'Sintētiskais Vecāks', 'email' => 'test@example.com'],
        ], $extra);
    }

    private function assertContactRejected(Survey $survey, array $payload, string $field, string $question = 'q1'): void
    {
        try {
            app(QuestionContactService::class)->submit($survey, $question, $payload);
            $this->fail('Expected contact validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_schema_has_only_independent_contact_identity_and_encrypted_payload_columns(): void
    {
        $this->assertSame(['id', 'survey_id', 'question_key', 'answer', 'details'], Schema::getColumnListing('question_contacts'));
        $columns = collect(Schema::getColumns('question_contacts'))->keyBy('name');
        $this->assertStringContainsString('text', $columns['answer']['type_name']);
        $this->assertStringContainsString('text', $columns['details']['type_name']);
        $this->assertSame(['id', 'survey_id', 'answers', 'respondent_details'], Schema::getColumnListing('survey_responses'));
        $this->assertSame(['id', 'invitation_id', 'role'], Schema::getColumnListing('family_participations'));
    }

    public function test_submission_encrypts_only_this_answer_and_configured_populated_contact_fields(): void
    {
        $survey = $this->contactSurvey([
            ['key' => 'name', 'label' => 'Vārds', 'type' => 'text', 'required' => true],
            ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true],
            ['key' => 'phone', 'label' => 'Tālrunis', 'type' => 'tel', 'required' => false],
        ]);
        $payload = $this->contactPayload([
            'fields' => ['name' => "\u{2003}Sintētiskais Vecāks\u{00a0}", 'email' => ' test@example.com ', 'phone' => '  ', 'unselected' => ['ignored']],
            'answers' => ['q2' => ['a', 'b']], 'submission_id' => (string) Str::uuid(),
            'response_id' => 'ignored', 'family_id' => 10, 'invitation_id' => 20,
            'session_id' => 'ignored', 'ip' => '127.0.0.1', 'created_at' => '2026-01-01',
        ]);
        $session = session()->all();
        $contact = app(QuestionContactService::class)->submit($survey, 'q1', $payload)->fresh();

        $this->assertSame($payload['contact_id'], $contact->id);
        $this->assertSame(['values' => ['yes'], 'labels' => ['Jā']], $contact->answer);
        $this->assertSame(['fields' => [
            ['key' => 'name', 'label' => 'Vārds', 'type' => 'text', 'value' => 'Sintētiskais Vecāks'],
            ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'value' => 'test@example.com'],
        ]], $contact->details);
        $raw = DB::table('question_contacts')->where('id', $contact->id)->first();
        $this->assertStringNotContainsString('Sintētiskais', $raw->details);
        $this->assertStringNotContainsString('test@example.com', $raw->details);
        $this->assertStringNotContainsString('values', $raw->answer);
        $this->assertStringNotContainsString('labels', $raw->answer);
        $this->assertFalse($contact->timestamps);
        $this->assertFalse($contact->incrementing);
        $this->assertSame([], $contact->load('survey')->toArray());
        $this->assertSame($session, session()->all());
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
        $this->assertDatabaseCount('family_invitations', 0);
    }

    public function test_multi_answer_is_normalized_in_published_order_and_only_trigger_choices_are_stored(): void
    {
        $survey = $this->contactSurvey();
        $contact = app(QuestionContactService::class)->submit($survey, 'q2', $this->contactPayload(['answer' => ['b', 'a']]));
        $this->assertSame(['values' => ['a', 'b'], 'labels' => ['A', 'B']], $contact->answer);
        $this->assertContactRejected($survey, $this->contactPayload(['answer' => ['a', 'no']]), 'answer', 'q2');
        $this->assertContactRejected($survey, $this->contactPayload(['answer' => ['a', 'other']]), 'answer', 'q2');
    }

    public function test_role_is_self_reported_and_is_never_taken_from_family_or_main_response_data(): void
    {
        $survey = $this->contactSurvey([['key' => 'role', 'label' => 'Loma', 'type' => 'role', 'required' => true]]);
        $invitation = $this->invitation($survey);
        session()->put('family_role', 'mother');
        $payload = $this->contactPayload(['role' => 'mother', 'invitation_id' => $invitation->id, 'fields' => ['role' => 'guardian']]);
        $contact = app(QuestionContactService::class)->submit($survey, 'q1', $payload);
        $this->assertSame('guardian', $contact->details['fields'][0]['value']);
        $this->assertContactRejected($survey, $this->contactPayload(['fields' => [], 'role' => 'mother']), 'fields.role');
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_idempotent_retry_returns_original_but_uuid_cannot_be_reused_for_another_question_or_survey(): void
    {
        $survey = $this->contactSurvey();
        $payload = $this->contactPayload();
        $original = app(QuestionContactService::class)->submit($survey, 'q1', $payload);
        $retry = app(QuestionContactService::class)->submit($survey, 'q1', $payload);
        $this->assertSame($original->details, $retry->details);
        $this->assertContactRejected($survey, [...$payload, 'fields' => [...$payload['fields'], 'email' => 'changed@example.com']], 'contact_id');
        $this->assertContactRejected($survey, [...$payload, 'answer' => 'a'], 'contact_id', 'q2');
        $this->assertContactRejected($this->contactSurvey(), $payload, 'contact_id');
        $this->assertDatabaseCount('question_contacts', 1);
    }

    public function test_exact_normalized_retry_succeeds_after_close_but_changed_email_or_new_uuid_cannot_be_saved(): void
    {
        $survey = $this->contactSurvey();
        $payload = $this->contactPayload();
        $original = app(QuestionContactService::class)->submit($survey, 'q1', $payload);
        $ciphertext = $original->getRawOriginal('details');
        $survey->update(['status' => 'closed']);
        $retry = app(QuestionContactService::class)->submit($survey, 'q1', [...$payload, 'fields' => [
            ...$payload['fields'], 'name' => "\u{2003}".$payload['fields']['name'].' ', 'unconfigured' => 'ignored',
        ]]);
        $this->assertSame($original->id, $retry->id);
        $this->assertSame($ciphertext, $retry->getRawOriginal('details'));
        $this->assertContactRejected($survey, [...$payload, 'fields' => [...$payload['fields'], 'email' => 'corrected@example.com']], 'contact_id');
        $this->assertContactRejected($survey, [...$payload, 'contact_id' => (string) Str::uuid()], 'survey');
        $this->assertSame('test@example.com', $original->fresh()->details['fields'][1]['value']);
        $this->assertDatabaseCount('question_contacts', 1);
    }

    public function test_same_uuid_cannot_silently_ignore_a_changed_selected_answer(): void
    {
        $survey = $this->contactSurvey();
        $payload = $this->contactPayload(['answer' => 'a']);
        $original = app(QuestionContactService::class)->submit($survey, 'q2', $payload);
        $this->assertContactRejected($survey, [...$payload, 'answer' => 'b'], 'contact_id', 'q2');
        $this->assertSame(['values' => ['a'], 'labels' => ['A']], $original->fresh()->answer);
        $this->assertDatabaseCount('question_contacts', 1);
    }

    public function test_service_re_reads_persisted_status_and_configuration(): void
    {
        $survey = $this->contactSurvey();
        $survey->status = 'closed'; // A stale local edit must not override the stored open state.
        app(QuestionContactService::class)->submit($survey, 'q1', $this->contactPayload());
        Survey::query()->whereKey($survey->id)->update(['status' => 'closed']);
        $survey->status = 'open';
        $this->assertContactRejected($survey, $this->contactPayload(), 'survey');
        Survey::query()->whereKey($survey->id)->update(['status' => 'open', 'allow_identified_responses' => true]);
        $survey->allow_identified_responses = false;
        $this->assertContactRejected($survey, $this->contactPayload(), 'survey');
        $this->assertDatabaseCount('question_contacts', 1);
    }

    public function test_closed_expired_unpublished_or_unsupported_question_cannot_accept_contact(): void
    {
        $survey = $this->contactSurvey();
        $this->assertContactRejected($survey, $this->contactPayload(), 'question', 'missing');
        $this->assertContactRejected($survey, $this->contactPayload(), 'question', 'q3');
        $survey->update(['closes_at' => now()->subSecond()]);
        $this->assertContactRejected($survey, $this->contactPayload(), 'survey');
        Survey::query()->whereKey($survey->id)->update(['closes_at' => null, 'published_at' => null]);
        $this->assertContactRejected($survey, $this->contactPayload(), 'survey');
        $this->assertDatabaseCount('question_contacts', 0);
    }

    public static function invalidContacts(): array
    {
        return [
            'missing id' => [['contact_id' => null], 'contact_id'],
            'invalid id' => [['contact_id' => 'not-a-uuid'], 'contact_id'],
            'no consent' => [['consent' => false], 'consent'],
            'missing consent' => [['consent' => null], 'consent'],
            'malformed consent' => [['consent' => ['1']], 'consent'],
            'nonboolean consent' => [['consent' => 'yes'], 'consent'],
            'empty answer' => [['answer' => []], 'answer'],
            'nontrigger answer' => [['answer' => 'no'], 'answer'],
            'other answer' => [['answer' => 'other'], 'answer'],
            'unknown answer' => [['answer' => 'invented'], 'answer'],
            'duplicate answer' => [['answer' => ['yes', 'yes']], 'answer'],
            'answer object' => [['answer' => ['selection' => 'yes']], 'answer'],
            'malformed answer' => [['answer' => [['yes']]], 'answer'],
            'missing fields' => [['fields' => null], 'fields'],
            'malformed fields' => [['fields' => 'wrong'], 'fields'],
            'empty name' => [['fields' => ['name' => "\u{2003}", 'email' => 'a@example.com']], 'fields.name'],
            'long name' => [['fields' => ['name' => str_repeat('a', 161), 'email' => 'a@example.com']], 'fields.name'],
            'malformed name' => [['fields' => ['name' => ['private'], 'email' => 'a@example.com']], 'fields.name'],
            'invalid email' => [['fields' => ['name' => 'Test', 'email' => 'invalid']], 'fields.email'],
            'long email' => [['fields' => ['name' => 'Test', 'email' => str_repeat('a', 250).'@example.com']], 'fields.email'],
        ];
    }

    #[DataProvider('invalidContacts')]
    public function test_invalid_payload_is_rejected_without_writing_any_response(array $extra, string $field): void
    {
        $this->assertContactRejected($this->contactSurvey(), $this->contactPayload($extra), $field);
        $this->assertDatabaseCount('question_contacts', 0);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_optional_fields_still_require_one_populated_field_and_apply_phone_and_role_limits(): void
    {
        $survey = $this->contactSurvey([
            ['key' => 'phone', 'label' => 'Tālrunis', 'type' => 'tel', 'required' => false],
            ['key' => 'role', 'label' => 'Loma', 'type' => 'role', 'required' => false],
        ]);
        $this->assertContactRejected($survey, $this->contactPayload(['fields' => ['phone' => ' ', 'role' => null, 'unconfigured' => 'private']]), 'fields');
        $this->assertContactRejected($survey, $this->contactPayload(['fields' => ['phone' => str_repeat('1', 41)]]), 'fields.phone');
        $this->assertContactRejected($survey, $this->contactPayload(['fields' => ['role' => 'child']]), 'fields.role');
        $contact = app(QuestionContactService::class)->submit($survey, 'q1', $this->contactPayload(['fields' => ['phone' => '+371 20000000']]));
        $this->assertCount(1, $contact->details['fields']);
    }

    public function test_relationship_and_school_scopes_keep_other_school_contacts_separate(): void
    {
        $survey = $this->contactSurvey();
        $contact = app(QuestionContactService::class)->submit($survey, 'q1', $this->contactPayload());
        $other = $this->otherSurvey();
        $admin = new User;
        $admin->school_id = $this->school->id;
        $this->assertSame($contact->id, $survey->questionContacts()->sole()->id);
        $this->assertSame($contact->id, QuestionContact::query()->accessibleTo($admin)->sole()->id);
        $this->assertSame(0, QuestionContact::query()->forSchool($other->school_id)->count());
        $this->assertSame(0, QuestionContact::query()->accessibleTo(new User)->count());
    }
}
