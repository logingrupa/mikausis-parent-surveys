<?php

namespace App\Services;

use App\Models\QuestionContact;
use App\Models\Survey;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class QuestionContactService
{
    public function submit(Survey $survey, string $questionKey, array $payload): QuestionContact
    {
        $input = Validator::make($payload, [
            'contact_id' => ['required', 'uuid'],
            'consent' => ['required', 'boolean', 'accepted'],
        ], [
            'contact_id.required' => 'Lūdzu, atveriet kontaktinformācijas veidlapu vēlreiz.',
            'contact_id.uuid' => 'Lūdzu, atveriet kontaktinformācijas veidlapu vēlreiz.',
            'consent.required' => 'Lai atstātu kontaktinformāciju, apstipriniet piekrišanu.',
            'consent.boolean' => 'Lai atstātu kontaktinformāciju, apstipriniet piekrišanu.',
            'consent.accepted' => 'Lai atstātu kontaktinformāciju, apstipriniet piekrišanu.',
        ])->validate();

        $answer = null;
        $details = null;
        try {
            return DB::transaction(function () use ($survey, $questionKey, $payload, $input, &$answer, &$details) {
                $current = Survey::query()->lockForUpdate()->findOrFail($survey->getKey());
                if ($current->allow_identified_responses) {
                    throw ValidationException::withMessages(['survey' => 'Šajā aptaujā atsevišķa jautājuma kontaktinformāciju atstāt nevar.']);
                }

                $existing = QuestionContact::query()->find($input['contact_id']);
                if ($existing !== null) {
                    $this->existingInScope($existing, $current, $questionKey);
                }
                // Retired definitions are available only for confirming an already saved request.
                $question = $current->questionDefinition($questionKey, includeArchived: $existing !== null);
                if (! is_array($question) || ! in_array($question['type'] ?? null, ['single', 'multiple'], true) || ! is_array($question['contact'] ?? null)) {
                    throw ValidationException::withMessages(['question' => 'Šim jautājumam kontaktinformācijas veidlapa nav pieejama.']);
                }

                $answer = $this->normalizeAnswer($question, $payload['answer'] ?? null);
                $details = $this->normalizeDetails($question['contact']['fields'] ?? null, $payload['fields'] ?? null);
                if ($existing !== null) {
                    return $this->exactRetry($existing, $answer, $details);
                }
                if ($current->published_at === null || ! $current->isAcceptingResponses()) {
                    throw ValidationException::withMessages(['survey' => 'Šī aptauja šobrīd nepieņem kontaktinformāciju.']);
                }

                return QuestionContact::query()->create([
                    'id' => $input['contact_id'],
                    'survey_id' => $current->id,
                    'question_key' => $questionKey,
                    'answer' => $answer,
                    'details' => $details,
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            $existing = QuestionContact::query()->find($input['contact_id']);
            if ($existing !== null && is_array($answer) && is_array($details)) {
                return $this->exactRetry($this->existingInScope($existing, $survey, $questionKey), $answer, $details);
            }

            throw $exception;
        }
    }

    private function existingInScope(QuestionContact $contact, Survey $survey, string $questionKey): QuestionContact
    {
        if ((int) $contact->survey_id !== (int) $survey->id || $contact->question_key !== $questionKey) {
            throw ValidationException::withMessages(['contact_id' => 'Lūdzu, atveriet šī jautājuma kontaktinformācijas veidlapu vēlreiz.']);
        }

        return $contact;
    }

    private function exactRetry(QuestionContact $contact, array $answer, array $details): QuestionContact
    {
        $storedValues = $contact->answer['values'] ?? [];
        $values = $answer['values'] ?? [];
        sort($storedValues, SORT_STRING);
        sort($values, SORT_STRING);
        // Wording corrections do not change an immutable request. Compare semantic
        // values by stable keys and types; preserve the original encrypted labels.
        if ($storedValues !== $values || $this->comparableDetails($contact->details) !== $this->comparableDetails($details)) {
            throw ValidationException::withMessages(['contact_id' => 'Kontaktinformācija ar šo identifikatoru jau ir saglabāta ar citiem datiem. Jūsu izmaiņas nav saglabātas.']);
        }

        return $contact;
    }

    private function comparableDetails(array $details): array
    {
        $fields = [];
        foreach ($details['fields'] ?? [] as $field) {
            $fields[$field['key']] = ['type' => $field['type'], 'value' => $field['value']];
        }
        ksort($fields, SORT_STRING);

        return $fields;
    }

    private function normalizeAnswer(array $question, mixed $raw): array
    {
        $triggers = $question['contact']['trigger_values'] ?? null;
        $options = $question['options'] ?? null;
        $selected = is_string($raw) ? [$raw] : $raw;
        $valid = is_array($triggers) && array_is_list($triggers) && $triggers !== []
            && is_array($options) && is_array($selected) && array_is_list($selected) && $selected !== []
            && count($selected) <= count($triggers)
            && (($question['type'] ?? null) !== 'single' || count($selected) === 1);
        $labels = [];
        foreach (is_array($options) ? $options : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null) && is_string($option['label'] ?? null)) {
                $labels[$option['value']] = $option['label'];
            }
        }
        $seen = [];
        foreach (is_array($selected) ? $selected : [] as $value) {
            if (! is_string($value) || $value === 'other' || ! in_array($value, is_array($triggers) ? $triggers : [], true)
                || ! array_key_exists($value, $labels) || in_array($value, $seen, true)) {
                $valid = false;
                break;
            }
            $seen[] = $value;
        }
        if (count($seen) > 1 && array_intersect($seen, $question['exclusive_values'] ?? []) !== []) {
            $valid = false;
        }
        if (! $valid) {
            throw ValidationException::withMessages(['answer' => 'Izvēlieties šī jautājuma piedāvāto atbildi, kurai var atstāt kontaktinformāciju.']);
        }

        // Store only the selected contact-triggering values and their published labels.
        $values = [];
        $selectedLabels = [];
        foreach ($options as $option) {
            if (is_array($option) && in_array($option['value'] ?? null, $seen, true)) {
                $values[] = $option['value'];
                $selectedLabels[] = $option['label'];
            }
        }

        return ['values' => $values, 'labels' => $selectedLabels];
    }

    private function normalizeDetails(mixed $fields, mixed $raw): array
    {
        if (! is_array($fields) || ! array_is_list($fields) || $fields === [] || count($fields) > 12) {
            throw ValidationException::withMessages(['fields' => 'Šī jautājuma kontaktinformācijas lauki nav pieejami.']);
        }
        if (! is_array($raw)) {
            throw ValidationException::withMessages(['fields' => 'Lūdzu, aizpildiet kontaktinformāciju.']);
        }

        $values = [];
        $rules = [];
        $attributes = [];
        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null) || ! is_string($field['label'] ?? null)
                || ! preg_match('/^(?:name|role|email|phone|detail_[a-f0-9]{32}|detail_[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/', $field['key'])
                || ! in_array($field['type'] ?? null, ['text', 'email', 'tel', 'role'], true)
                || array_key_exists($field['key'], $values)) {
                throw ValidationException::withMessages(['fields' => 'Šī jautājuma kontaktinformācijas lauki nav derīgi.']);
            }
            $key = $field['key'];
            $path = 'fields.'.$key;
            $value = $raw[$key] ?? null;
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
                }
            }
            $attributes[$path] = $field['label'];
        }
        Validator::make(['fields' => $values], $rules, [
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
            throw ValidationException::withMessages(['fields' => 'Norādiet vismaz vienu kontaktinformācijas lauku.']);
        }

        return ['fields' => $details];
    }
}
