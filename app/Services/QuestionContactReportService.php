<?php

namespace App\Services;

use App\Models\QuestionContact;
use App\Models\Survey;
use App\Rules\ContactPhone;
use App\Support\ContactField;
use Illuminate\Support\Facades\Validator;

class QuestionContactReportService
{
    public function build(Survey $survey): array
    {
        $records = $survey->questionContacts()->inRandomOrder()
            ->get(['id', 'question_key', 'answer', 'details'])->groupBy('question_key');
        $cards = [];

        foreach ($survey->reportQuestions() as $question) {
            if (! is_array($question['contact'] ?? null)) {
                continue;
            }

            foreach ($records->get($question['key'], collect()) as $contact) {
                $fields = $this->fields($contact, $question);
                $key = $this->groupKey($contact, $fields);
                if (! isset($cards[$key])) {
                    $display = array_map(fn (array $field): array => [
                        'label' => $field['label'], 'value' => $field['display'],
                    ], $fields);
                    $heading = array_shift($display);
                    $cards[$key] = [
                        'title' => $heading['value'] ?? 'Kontaktinformācija',
                        'label' => $heading['label'] ?? null,
                        'fields' => $display,
                        'activities' => [],
                    ];
                }

                $values = $contact->answer['values'] ?? [];
                $cards[$key]['activities'][] = [
                    'id' => $contact->getKey(),
                    'title' => $question['title'],
                    'purpose' => $question['contact']['purpose'],
                    'archived' => (bool) ($question['archived'] ?? false),
                    'answers' => collect($question['options'] ?? [])
                        ->filter(fn (array $option): bool => in_array($option['value'], $values, true))
                        ->pluck('label')->all(),
                ];
            }
        }

        $cards = array_values($cards);
        shuffle($cards);

        return $cards;
    }

    private function fields(QuestionContact $contact, array $question): array
    {
        $stored = collect($contact->details['fields'] ?? [])
            ->filter(fn ($field): bool => is_array($field) && is_string($field['key'] ?? null))
            ->keyBy('key');
        $fields = [];

        foreach ($question['contact']['fields'] as $definition) {
            $value = $stored->get($definition['key'])['value'] ?? null;
            if (! is_string($value) || trim($value) === '') {
                continue;
            }

            $type = ContactField::type($definition);
            $display = $type === 'role' ? match ($value) {
                'mother' => 'Mamma', 'father' => 'Tētis', 'guardian' => 'Cits aizbildnis', default => null,
            } : $value;
            if ($display !== null) {
                $fields[] = [
                    'key' => $definition['key'], 'label' => $definition['label'],
                    'type' => $type, 'value' => $value, 'display' => $display,
                ];
            }
        }

        return $fields;
    }

    private function groupKey(QuestionContact $contact, array $fields): string
    {
        $hasRecipient = false;
        foreach ($fields as $field) {
            if (! in_array($field['type'], ['email', 'tel'], true)) {
                continue;
            }
            $rule = $field['type'] === 'email' ? 'email:rfc' : new ContactPhone;
            $hasRecipient = $hasRecipient || Validator::make(['value' => $field['value']], ['value' => [$rule]])->passes();
        }
        if (! $hasRecipient) {
            return $contact->getKey();
        }

        // A shared card requires identical populated fields, not a name match.
        $identity = array_map(fn (array $field): array => [
            $field['key'], $field['type'], $field['label'], $field['value'],
        ], $fields);
        usort($identity, fn (array $first, array $second): int => strcmp($first[0], $second[0]));

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}
