<?php

namespace App\Services;

use App\Models\FamilyInvitation;
use App\Models\FamilyParticipation;
use App\Models\QuestionAnswer;
use App\Models\Survey;
use App\Models\SurveyResponse;
use App\Rules\ContactPhone;
use App\Support\ContactField;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SurveySubmissionService
{
    public function submit(Survey $survey, array $payload, ?FamilyInvitation $invitation = null): SurveyResponse
    {
        $payload += ['anonymous' => true];
        $input = Validator::make($payload, [
            'submission_id' => ['required', 'uuid'],
            'answers' => ['present', 'array'],
            'other_answers' => ['sometimes', 'array'],
            'role' => [$invitation ? 'required' : 'nullable', 'string', Rule::in(['mother', 'father', 'guardian'])],
            'anonymous' => ['required', 'boolean'],
        ], [
            'submission_id.required' => 'Lūdzu, atveriet aptauju vēlreiz.',
            'submission_id.uuid' => 'Lūdzu, atveriet aptauju vēlreiz.',
            'role.required' => 'Lūdzu, izvēlieties savu lomu.',
            'role.in' => 'Lūdzu, izvēlieties derīgu lomu.',
            'anonymous.required' => 'Lūdzu, izvēlieties, vai atbildēt anonīmi.',
            'anonymous.boolean' => 'Lūdzu, izvēlieties, vai atbildēt anonīmi.',
        ])->validate();

        try {
            return DB::transaction(function () use ($survey, $input, $invitation, $payload) {
                // Re-read status and ownership within the same transaction as both inserts.
                $current = Survey::query()->lockForUpdate()->findOrFail($survey->getKey());
                $family = null;
                if ($invitation !== null) {
                    $family = FamilyInvitation::query()->lockForUpdate()->find($invitation->getKey());
                    if ($family === null || (int) $family->survey_id !== (int) $current->id || (int) $family->school_id !== (int) $current->school_id) {
                        throw ValidationException::withMessages(['invitation' => 'Šī ģimenes saite nav derīga šai aptaujai.']);
                    }
                }

                $existing = SurveyResponse::query()->find($input['submission_id']);
                if ($existing !== null) {
                    if ((int) $existing->survey_id !== (int) $current->id) {
                        throw ValidationException::withMessages(['submission_id' => 'Lūdzu, atveriet aptauju vēlreiz.']);
                    }

                    if ($family !== null && ! $family->participations()->where('role', $input['role'])->exists()) {
                        throw ValidationException::withMessages(['role' => 'Šī anketa jau ir iesniegta. Lai atbildētu citā ģimenes lomā, atveriet ģimenes saiti no jauna.']);
                    }

                    return $existing;
                }

                if (! $current->isAcceptingResponses()) {
                    throw ValidationException::withMessages(['survey' => 'Šī aptauja šobrīd nepieņem atbildes.']);
                }

                // Existing retries above remain idempotent even after the definition changes.
                // Reject retired keys before any of the independent answer/participation writes.
                if (array_key_exists('form_definition', $payload)
                    && (! is_string($payload['form_definition']) || ! hash_equals($current->formDefinitionToken(), $payload['form_definition']))) {
                    throw ValidationException::withMessages(['survey' => 'Aptauja ir mainīta. Pārlādējiet lapu un pārbaudiet atbildes pirms iesniegšanas.']);
                }
                $retiredKeys = array_column($current->archived_questions ?? [], 'key');
                if (array_intersect([...array_keys($input['answers']), ...array_keys($input['other_answers'] ?? [])], $retiredKeys) !== []) {
                    throw ValidationException::withMessages(['survey' => 'Aptaujas jautājumi ir mainīti. Pārlādējiet lapu un pārbaudiet atbildes pirms iesniegšanas.']);
                }

                $respondent = $this->normalizeRespondentDetails($current, $payload, $family !== null ? $input['role'] : null);
                $answers = $this->normalizeAnswers($current, $input['answers'], $input['other_answers'] ?? []);

                // These answers must not reveal which other opinions the same parent submitted.
                // Every answered contact-enabled question gets its own unrelated random UUID,
                // whether or not the parent chooses to leave contact information separately.
                foreach ($current->questions ?? [] as $question) {
                    $key = $question['key'];
                    if (is_array($question['contact'] ?? null) && array_key_exists($key, $answers)) {
                        QuestionAnswer::query()->create(['survey_id' => $current->id, 'question_key' => $key, 'answer' => $answers[$key]]);
                        unset($answers[$key]);
                    }
                }

                if ($family !== null) {
                    if ($family->participations()->where('role', $input['role'])->exists()) {
                        throw ValidationException::withMessages(['role' => 'Šajā ģimenes saitē šī loma jau ir iesniegusi atbildi.']);
                    }
                    FamilyParticipation::query()->create(['invitation_id' => $family->id, 'role' => $input['role']]);
                }

                return SurveyResponse::query()->create(['id' => $input['submission_id'], 'survey_id' => $current->id, 'answers' => $answers, 'respondent_details' => $respondent]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Concurrent retries share a UUID; concurrent role claims share a unique key.
            $existing = SurveyResponse::query()->find($input['submission_id']);
            if ($existing !== null && (int) $existing->survey_id === (int) $survey->id) {
                return $existing;
            }
            if ($invitation !== null && $invitation->participations()->where('role', $input['role'])->exists()) {
                throw ValidationException::withMessages(['role' => 'Šajā ģimenes saitē šī loma jau ir iesniegusi atbildi.']);
            }

            throw $exception;
        }
    }

    private function normalizeRespondentDetails(Survey $survey, array $payload, ?string $familyRole): ?array
    {
        if ($payload['anonymous']) {
            return null;
        }
        if (! $survey->allow_identified_responses) {
            throw ValidationException::withMessages(['anonymous' => 'Šajā aptaujā atbildes var iesniegt tikai anonīmi.']);
        }

        $raw = $payload['respondent'] ?? [];
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['respondent' => 'Lūdzu, pārbaudiet respondenta datus.']);
        }

        $fields = array_map(fn (array $field) => [...$field, 'type' => ContactField::type($field)], $survey->respondentFields());
        $values = [];
        $rules = [];
        $attributes = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            $path = 'respondent.'.$key;
            $legacy = match ($key) {
                'name' => $payload['respondent_name'] ?? null,
                'role' => $payload['respondent_role'] ?? null,
                default => null,
            };
            $value = array_key_exists($key, $raw) ? $raw[$key] : $legacy;
            if ($field['type'] === 'role' && $familyRole !== null) {
                $value = $familyRole;
            }
            $value = is_string($value) ? Str::trim($value) : $value;
            $values[$key] = $value === '' ? null : $value;
            $rules[$path] = [($field['required'] ?? false) ? 'required' : 'nullable', 'string'];
            if ($field['type'] === 'role') {
                $rules[$path][] = Rule::in(['mother', 'father', 'guardian']);
            } else {
                $rules[$path][] = 'max:'.match ($field['type']) {
                    'email' => 254,
                    'tel' => 40,
                    default => 160,
                };
                if ($field['type'] === 'email') {
                    $rules[$path][] = 'email:rfc';
                } elseif ($field['type'] === 'tel') {
                    $rules[$path][] = new ContactPhone;
                }
            }
            $attributes[$path] = $field['label'];
        }
        Validator::make(['respondent' => $values], $rules, [
            'required' => 'Lūdzu, aizpildiet lauku ":attribute".',
            'string' => 'Laukā ":attribute" ievadiet tekstu.',
            'max' => 'Lauks ":attribute" nedrīkst pārsniegt :max rakstzīmes.',
            'email' => 'Laukā ":attribute" ievadiet derīgu e-pasta adresi.',
            'in' => 'Laukā ":attribute" izvēlieties derīgu lomu.',
        ], $attributes)->validate();

        $details = [];
        foreach ($fields as $field) {
            $value = $values[$field['key']];
            if ($value !== null) {
                $details[] = ['key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'], 'value' => $value];
            }
        }
        if ($details === []) {
            throw ValidationException::withMessages(['respondent' => 'Norādiet vismaz vienu respondenta datu lauku vai izvēlieties atbildēt anonīmi.']);
        }

        return ['fields' => $details];
    }

    private function normalizeAnswers(Survey $survey, array $rawAnswers, array $otherAnswers): array
    {
        $answers = [];
        $errors = [];

        foreach ($survey->questions ?? [] as $question) {
            $key = $question['key'];
            $condition = $question['condition'] ?? null;
            if ($condition !== null) {
                $earlier = $answers[$condition['question']]['value'] ?? null;
                $selected = is_array($earlier) ? $earlier : ($earlier === null ? [] : [$earlier]);
                if (array_intersect($selected, $condition['values'] ?? []) === []) {
                    continue;
                }
            }

            $raw = $rawAnswers[$key] ?? null;
            $field = 'answers.'.$key;
            if ($raw === null || $raw === '' || $raw === []) {
                if ($question['required'] ?? false) {
                    $errors[$field] = 'Lūdzu, atbildiet uz šo jautājumu.';
                }

                continue;
            }

            $type = $question['type'];
            if ($type === 'text') {
                if (! is_string($raw) || mb_strlen($raw) > 2000) {
                    $errors[$field] = 'Lūdzu, ievadiet tekstu līdz 2000 rakstzīmēm.';

                    continue;
                }
                $value = Str::trim($raw);
                if ($value === '') {
                    if ($question['required'] ?? false) {
                        $errors[$field] = 'Lūdzu, atbildiet uz šo jautājumu.';
                    }

                    continue;
                }
                $answers[$key] = ['value' => $value, 'other' => null];

                continue;
            }

            $allowed = array_column($question['options'] ?? [], 'value');
            if (($question['allow_other'] ?? false) && ! in_array('other', $allowed, true)) {
                $allowed[] = 'other';
            }

            if ($type === 'single') {
                if (! is_string($raw) || ! in_array($raw, $allowed, true)) {
                    $errors[$field] = 'Lūdzu, izvēlieties vienu no norādītajām atbildēm.';

                    continue;
                }
                $value = $raw;
                $selected = [$raw];
            } elseif ($type === 'multiple') {
                if (! is_array($raw) || ! array_is_list($raw) || count($raw) > count($allowed)) {
                    $errors[$field] = 'Lūdzu, izvēlieties norādītos atbilžu variantus.';

                    continue;
                }
                $valid = true;
                $selected = [];
                foreach ($raw as $option) {
                    if (! is_string($option) || ! in_array($option, $allowed, true) || in_array($option, $selected, true)) {
                        $valid = false;
                        break;
                    }
                    $selected[] = $option;
                }
                if (! $valid) {
                    $errors[$field] = 'Lūdzu, izvēlieties derīgus, neatkārtotus atbilžu variantus.';

                    continue;
                }
                if (count($selected) > 1 && array_intersect($selected, $question['exclusive_values'] ?? []) !== []) {
                    $errors[$field] = 'Atbildi “nepiedalos” nevar apvienot ar citiem variantiem.';

                    continue;
                }
                $value = $selected;
            } else {
                $errors[$field] = 'Šī jautājuma atbildi nevar saglabāt.';

                continue;
            }

            $other = null;
            if (in_array('other', $selected, true)) {
                $rawOther = $otherAnswers[$key] ?? null;
                if (! is_string($rawOther) || Str::trim($rawOther) === '' || mb_strlen($rawOther) > 2000) {
                    $errors['other_answers.'.$key] = 'Lūdzu, aprakstiet savu atbildi (līdz 2000 rakstzīmēm).';

                    continue;
                }
                $other = Str::trim($rawOther);
            }
            $answers[$key] = ['value' => $value, 'other' => $other];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        if ($answers === []) {
            throw ValidationException::withMessages(['answers' => 'Lūdzu, atbildiet vismaz uz vienu jautājumu.']);
        }

        return $answers;
    }
}
