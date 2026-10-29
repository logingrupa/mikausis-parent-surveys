<?php

namespace App\Filament\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class SurveyEditorData
{
    private const CONTENT_FIELDS = ['title', 'description', 'sections', 'questions', 'allow_identified_responses', 'respondent_fields'];

    public static function empty(): array
    {
        $section = self::identifier('s');

        return [
            'title' => '',
            'description' => '',
            'allow_identified_responses' => false,
            'respondent_fields' => [],
            'sections' => [['key' => $section, 'title' => 'Jautājumi', 'description' => '']],
            'questions' => [[
                'key' => self::identifier('q'),
                'section' => $section,
                'type' => 'single',
                'title' => '',
                'description' => '',
                'required' => false,
                'allow_other' => false,
                'options' => [
                    ['value' => self::identifier('o'), 'label' => ''],
                    ['value' => self::identifier('o'), 'label' => ''],
                ],
                'exclusive_values' => [],
                'condition' => null,
            ]],
        ];
    }

    public static function fromDefinition(array $data): array
    {
        return [
            'builder' => ['allow_identified_responses' => false, 'respondent_fields' => null, ...Arr::only($data, self::CONTENT_FIELDS)],
            ...Arr::only($data, ['estimated_minutes', 'closes_at']),
        ];
    }

    public static function defaultQuestionContact(array $question): array
    {
        $triggers = [];
        foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null) && $option['value'] !== '' && $option['value'] !== 'other') {
                $triggers[] = $option['value'];
                break;
            }
        }

        return [
            'prompt' => 'Atstāt kontaktinformāciju',
            'purpose' => 'Kontaktinformācija paredzēta šī jautājuma aktivitātes organizēšanai. Tā netiek piesaistīta citām aptaujas atbildēm.',
            'trigger_values' => $triggers,
            'fields' => [
                ['key' => 'name', 'label' => 'Vārds un uzvārds', 'type' => 'text', 'required' => true],
                ['key' => 'email', 'label' => 'E-pasts', 'type' => 'email', 'required' => true],
            ],
        ];
    }

    public static function prepareForSave(array $data): array
    {
        if (! is_array($data['builder'] ?? null) || ! is_array($data['builder']['questions'] ?? null)) {
            return $data;
        }

        foreach ($data['builder']['questions'] as &$question) {
            if (! is_array($question) || ! is_array($question['options'] ?? null)) {
                continue;
            }
            $question['options'] = array_values(array_filter($question['options'], static fn ($option): bool => ! (
                is_array($option) && array_key_exists('label', $option)
                && ($option['value'] ?? null) !== 'other'
                && is_string($option['label']) && trim($option['label']) === ''
            )));
        }
        unset($question);

        return $data;
    }

    public static function definition(array $data): array
    {
        if (array_key_exists('builder', $data)) {
            if (! is_array($data['builder'])) {
                throw ValidationException::withMessages(['builder' => 'Aptaujas saturs nav derīgs. Pārlādējiet lapu un mēģiniet vēlreiz.']);
            }

            $data = [
                ...Arr::only($data, ['estimated_minutes', 'closes_at']),
                ...Arr::only($data['builder'], self::CONTENT_FIELDS),
            ];
        } else {
            $data = Arr::only($data, [...self::CONTENT_FIELDS, 'estimated_minutes', 'closes_at']);
        }

        $data += ['description' => '', 'estimated_minutes' => 7, 'closes_at' => null, 'sections' => [], 'questions' => [], 'allow_identified_responses' => false, 'respondent_fields' => null];

        if (is_array($data['respondent_fields'])) {
            foreach ($data['respondent_fields'] as &$field) {
                if (is_array($field) && is_string($field['label'] ?? null)) {
                    $field['label'] = Str::trim($field['label']);
                }
            }
            unset($field);
        }

        if (is_array($data['sections'])) {
            foreach ($data['sections'] as &$section) {
                if (! is_array($section)) {
                    continue;
                }
                if (self::missingIdentifier($section, 'key')) {
                    $section['key'] = self::identifier('s');
                }
                $section += ['title' => 'Jautājumi', 'description' => ''];
            }
            unset($section);
        }

        $firstSection = is_array($data['sections']) ? reset($data['sections']) : null;
        foreach (is_array($data['questions']) ? array_keys($data['questions']) : [] as $index) {
            $question = $data['questions'][$index];
            if (! is_array($question)) {
                continue;
            }
            if (self::missingIdentifier($question, 'key')) {
                $question['key'] = self::identifier('q');
            }
            $question += [
                'section' => is_array($firstSection) ? ($firstSection['key'] ?? null) : null,
                'type' => 'single', 'title' => '', 'description' => '',
                'required' => false, 'allow_other' => false,
                'options' => [], 'exclusive_values' => [], 'condition' => null,
            ];
            if ($question['condition'] === []) {
                $question['condition'] = null;
            }
            if (is_array($question['options'])) {
                foreach ($question['options'] as &$option) {
                    if (is_array($option) && self::missingIdentifier($option, 'value')) {
                        $option['value'] = self::identifier('o');
                    }
                }
                unset($option);
            }
            if (is_array($question['contact'] ?? null)) {
                foreach (['prompt', 'purpose'] as $field) {
                    if (is_string($question['contact'][$field] ?? null)) {
                        $question['contact'][$field] = Str::trim($question['contact'][$field]);
                    }
                }
                if (is_array($question['contact']['fields'] ?? null)) {
                    foreach ($question['contact']['fields'] as &$field) {
                        if (! is_array($field)) {
                            continue;
                        }
                        if (is_string($field['label'] ?? null)) {
                            $field['label'] = Str::trim($field['label']);
                        }
                        // Normalize valid booleans here for unchanged-question comparisons on save.
                        if (in_array($field['required'] ?? null, [true, false, 0, 1, '0', '1'], true)) {
                            $field['required'] = (bool) $field['required'];
                        }
                    }
                    unset($field);
                }
            }
            $data['questions'][$index] = $question;
        }

        return $data;
    }

    private static function missingIdentifier(array $data, string $field): bool
    {
        return ! array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '';
    }

    private static function identifier(string $prefix): string
    {
        return $prefix.'_'.str_replace('-', '', (string) Str::uuid());
    }
}
