<?php

namespace Tests\Feature;

use App\Models\QuestionContact;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Services\QuestionContactService;
use App\Services\SurveySubmissionService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class ContactFieldValidationTest extends SurveyTestCase
{
    private const CUSTOM_KEY = 'detail_11111111111141118111111111111111';

    private function fieldSurvey(string $mode, array $fields): Survey
    {
        $survey = $this->survey->duplicate();
        if ($mode === 'contact') {
            $questions = $survey->questions;
            $questions[0]['contact'] = [
                'prompt' => 'Contact', 'purpose' => 'Follow up', 'trigger_values' => ['yes'], 'fields' => $fields,
            ];
            $survey->update(['questions' => $questions, 'status' => 'open']);
        } else {
            $survey->update(['allow_identified_responses' => true, 'respondent_fields' => $fields, 'status' => 'open']);
        }

        return $survey;
    }

    private function submitFields(string $mode, Survey $survey, array $fields, ?string $id = null): QuestionContact|SurveyResponse
    {
        $id ??= (string) Str::uuid();
        if ($mode === 'contact') {
            return app(QuestionContactService::class)->submit($survey, 'q1', [
                'contact_id' => $id, 'consent' => true, 'answer' => 'yes', 'fields' => $fields,
            ]);
        }

        return app(SurveySubmissionService::class)->submit($survey, $this->payload(extra: [
            'submission_id' => $id, 'anonymous' => false, 'respondent' => $fields,
        ]));
    }

    private function savedFields(QuestionContact|SurveyResponse $record): array
    {
        return ($record instanceof QuestionContact ? $record->details : $record->respondent_details)['fields'];
    }

    public static function validPhones(): array
    {
        return [
            'local mobile' => ['20000000'],
            'local landline' => ['61234567'],
            'local separators' => ['(20) 000-000'],
            'Latvian plus' => ['+371 20000000'],
            'Latvian zero prefix' => ['00371 61234567'],
            'Latvian formatted plus' => ['+371 (20) 000-000'],
            'Latvian formatted zero prefix' => ['00 371 (61) 234-567'],
            'UK plus' => ['+44 20 7946 0958'],
            'UK zero prefix' => ['0044 (20) 7946-0958'],
            'US plus' => ['+1 (202) 555-0123'],
            'international minimum digits' => ['+1234567'],
            'international maximum digits' => ['+123456789012345'],
            'international maximum zero prefix' => ['00123456789012345'],
        ];
    }

    #[DataProvider('validPhones')]
    public function test_phone_formats_are_accepted_and_preserved_in_both_services(string $phone): void
    {
        foreach (['contact', 'respondent'] as $mode) {
            foreach (['phone', self::CUSTOM_KEY] as $key) {
                $field = ['key' => $key, 'label' => 'Telephone', 'type' => 'tel', 'required' => true];
                $survey = $this->fieldSurvey($mode, [$field]);
                $record = $this->submitFields($mode, $survey, [$key => "\u{3000}".$phone."\u{00A0}"])->fresh();

                unset($field['required']);
                $this->assertSame([[...$field, 'value' => $phone]], $this->savedFields($record));
            }
        }
        $this->assertDatabaseCount('question_contacts', 2);
        $this->assertDatabaseCount('survey_responses', 2);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public static function invalidPhones(): array
    {
        return [
            'unprefixed foreign number' => ['442079460958'],
            'unprefixed Latvian country code' => ['37120000000'],
            'invalid local prefix one' => ['12345678'],
            'invalid local prefix five' => ['51234567'],
            'local trunk prefix' => ['020000000'],
            'local too short' => ['2000000'],
            'local too long' => ['200000000'],
            'Latvian international invalid prefix' => ['+371 12345678'],
            'Latvian zero prefix invalid subscriber' => ['00371 81234567'],
            'Latvian international too short' => ['+371 2000000'],
            'Latvian international too long' => ['+371 200000000'],
            'international too short' => ['+123456'],
            'international zero prefix too short' => ['00123456'],
            'international too long' => ['+1234567890123456'],
            'international zero prefix too long' => ['001234567890123456'],
            'zero leading country code' => ['+012345678'],
            'zero prefix zero leading country code' => ['00012345678'],
            'local all zeros' => ['00000000'],
            'international all zeros' => ['+0000000'],
            'zero prefix all zeros' => ['00000000000'],
            'letters' => ['call me'],
            'extension' => ['+1 (202) 555-0123 ext 5'],
            'letter extension' => ['+37120000000x5'],
            'hash extension' => ['+37120000000#5'],
            'punctuation' => ['+371 2000.0000'],
            'trailing garbage' => ['+37120000000;'],
            'multiple numbers' => ['+37120000000,+37120000001'],
            'misplaced plus' => ['20000000+'],
            'duplicate plus' => ['++37120000000'],
            'embedded newline' => ["2000\n0000"],
            'empty formatting' => ['() -'],
            'blank' => [' '],
            'array' => [['20000000']],
            'integer' => [20000000],
            'boolean' => [true],
        ];
    }

    #[DataProvider('invalidPhones')]
    public function test_invalid_phone_values_are_rejected_for_builtin_and_custom_fields_in_both_services(mixed $phone): void
    {
        foreach (['contact', 'respondent'] as $mode) {
            foreach (['phone', self::CUSTOM_KEY] as $key) {
                $survey = $this->fieldSurvey($mode, [['key' => $key, 'label' => 'Telephone', 'type' => 'tel', 'required' => true]]);
                try {
                    $this->submitFields($mode, $survey, [$key => $phone]);
                    $this->fail('Expected phone validation failure.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey(($mode === 'contact' ? 'fields.' : 'respondent.').$key, $exception->errors());
                }
            }
        }
        $this->assertDatabaseCount('question_contacts', 0);
        $this->assertDatabaseCount('survey_responses', 0);
        $this->assertDatabaseCount('family_participations', 0);
    }

    public function test_new_builtin_fields_use_canonical_types_and_custom_fields_keep_configured_types(): void
    {
        $fields = [
            ['key' => 'name', 'label' => 'Name', 'type' => 'email', 'required' => true],
            ['key' => 'email', 'label' => 'Email', 'type' => 'text', 'required' => true],
            ['key' => 'phone', 'label' => 'Telephone', 'type' => 'text', 'required' => true],
            ['key' => 'role', 'label' => 'Role', 'type' => 'text', 'required' => true],
            ['key' => self::CUSTOM_KEY, 'label' => 'Note', 'type' => 'text', 'required' => false],
        ];
        $values = ['name' => 'Test Parent', 'email' => 'parent@example.test', 'phone' => '+371 (20) 000-000', 'role' => 'guardian', self::CUSTOM_KEY => 'A note'];
        foreach (['contact', 'respondent'] as $mode) {
            $record = $this->submitFields($mode, $this->fieldSurvey($mode, $fields), $values);
            $saved = $this->savedFields($record->fresh());
            $this->assertSame(['text', 'email', 'tel', 'role', 'text'], array_column($saved, 'type'));
            $this->assertSame($values, array_column($saved, 'value', 'key'));
        }
    }

    public static function invalidLegacyBuiltinValues(): array
    {
        return [
            'text phone' => ['phone', 'text', 'not a number'],
            'email phone' => ['phone', 'email', 'parent@example.test'],
            'text role' => ['role', 'text', 'child'],
            'telephone email' => ['email', 'tel', '+371 20000000'],
        ];
    }

    #[DataProvider('invalidLegacyBuiltinValues')]
    public function test_builtin_validation_uses_canonical_type_despite_legacy_configuration(string $key, string $type, string $value): void
    {
        foreach (['contact', 'respondent'] as $mode) {
            $survey = $this->fieldSurvey($mode, [['key' => $key, 'label' => 'Contact', 'type' => $type, 'required' => true]]);
            try {
                $this->submitFields($mode, $survey, [$key => $value]);
                $this->fail('Expected canonical field validation failure.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey(($mode === 'contact' ? 'fields.' : 'respondent.').$key, $exception->errors());
            }
        }
        $this->assertDatabaseCount('question_contacts', 0);
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_email_rfc_formats_remain_valid_and_keep_entered_case_and_spacing_inside_quotes(): void
    {
        foreach (['contact', 'respondent'] as $mode) {
            foreach (['Parent+School@example.test', '"Parent Name"@example.test'] as $email) {
                $survey = $this->fieldSurvey($mode, [['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]]);
                $record = $this->submitFields($mode, $survey, ['email' => ' '.$email.' ']);
                $this->assertSame($email, $this->savedFields($record->fresh())[0]['value']);
            }
        }
    }

    public static function invalidEmails(): array
    {
        return [
            'plain text' => ['not-an-email'],
            'missing local part' => ['@example.test'],
            'missing domain' => ['parent@'],
            'double at sign' => ['parent@@example.test'],
            'space' => ['parent name@example.test'],
        ];
    }

    #[DataProvider('invalidEmails')]
    public function test_email_validation_covers_typed_custom_and_legacy_text_builtin_fields(string $email): void
    {
        foreach (['contact', 'respondent'] as $mode) {
            foreach (['email' => 'text', self::CUSTOM_KEY => 'email'] as $key => $type) {
                $survey = $this->fieldSurvey($mode, [['key' => $key, 'label' => 'Email', 'type' => $type, 'required' => true]]);
                try {
                    $this->submitFields($mode, $survey, [$key => $email]);
                    $this->fail('Expected email validation failure.');
                } catch (ValidationException $exception) {
                    $this->assertArrayHasKey(($mode === 'contact' ? 'fields.' : 'respondent.').$key, $exception->errors());
                }
            }
        }
        $this->assertDatabaseCount('question_contacts', 0);
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_optional_phones_can_remain_empty_in_both_services(): void
    {
        foreach (['contact', 'respondent'] as $mode) {
            $survey = $this->fieldSurvey($mode, [
                ['key' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true],
                ['key' => 'phone', 'label' => 'Telephone', 'type' => 'tel', 'required' => false],
                ['key' => self::CUSTOM_KEY, 'label' => 'Other telephone', 'type' => 'tel', 'required' => false],
            ]);
            $record = $this->submitFields($mode, $survey, ['name' => 'Parent', 'phone' => ' ', self::CUSTOM_KEY => null]);
            $this->assertSame(['name'], array_column($this->savedFields($record), 'key'));
        }
    }

    public function test_legacy_contact_retries_keep_saved_types_invalid_phone_and_email_text_immutable(): void
    {
        $fields = [
            ['key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
            ['key' => 'phone', 'label' => 'Telephone', 'type' => 'text', 'required' => true],
        ];
        $survey = $this->fieldSurvey('contact', $fields);
        $values = ['email' => 'Legacy email text', 'phone' => '+371 12345678'];
        $contact = QuestionContact::create([
            'id' => (string) Str::uuid(), 'survey_id' => $survey->id, 'question_key' => 'q1',
            'answer' => ['values' => ['yes'], 'labels' => ['Jā']],
            'details' => ['fields' => [
                ['key' => 'email', 'label' => 'Email', 'type' => 'text', 'value' => $values['email']],
                ['key' => 'phone', 'label' => 'Telephone', 'type' => 'tel', 'value' => $values['phone']],
            ]],
        ]);
        $ciphertext = $contact->getRawOriginal('details');
        $survey->update(['status' => 'closed']);
        $retry = $this->submitFields('contact', $survey, ['email' => '  '.$values['email'].' ', 'phone' => $values['phone']], $contact->id);
        $this->assertSame($ciphertext, $retry->getRawOriginal('details'));
        $this->assertSame($contact->details, $retry->details);

        foreach ([['email' => 'new@example.test'], ['phone' => '+37112345678']] as $changes) {
            try {
                $this->submitFields('contact', $survey, array_replace($values, $changes), $contact->id);
                $this->fail('Expected immutable contact conflict.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('contact_id', $exception->errors());
            }
        }
        foreach ([str_repeat('2', 41), ['invalid'], null] as $invalid) {
            try {
                $this->submitFields('contact', $survey, [...$values, 'phone' => $invalid], $contact->id);
                $this->fail('Expected existing telephone field validation.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('fields.phone', $exception->errors());
            }
        }
        $survey->update(['status' => 'open']);
        try {
            $this->submitFields('contact', $survey, $values);
            $this->fail('Expected new contact validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fields.email', $exception->errors());
            $this->assertArrayHasKey('fields.phone', $exception->errors());
        }
        $this->assertSame($ciphertext, $contact->fresh()->getRawOriginal('details'));
        $this->assertDatabaseCount('question_contacts', 1);
    }

    public function test_respondent_retry_returns_original_details_before_new_validation(): void
    {
        $survey = $this->fieldSurvey('respondent', [
            ['key' => 'phone', 'label' => 'Telephone', 'type' => 'tel', 'required' => true],
        ]);
        $first = $this->submitFields('respondent', $survey, ['phone' => '+371 20000000']);
        $ciphertext = $first->getRawOriginal('respondent_details');
        $survey->update(['status' => 'closed']);
        $retry = $this->submitFields('respondent', $survey, ['phone' => ['invalid']], $first->id);

        $this->assertSame($ciphertext, $retry->getRawOriginal('respondent_details'));
        $this->assertSame($first->respondent_details, $retry->respondent_details);
        $this->assertDatabaseCount('survey_responses', 1);
    }
}
