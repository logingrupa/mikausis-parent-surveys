<?php

namespace App\Services;

use App\Models\FamilyParticipation;
use App\Models\Survey;
use App\Models\SurveyResponse;

class SurveyReportService
{
    private const MINIMUM_RESPONSES = 5;

    public function build(Survey $survey, bool $includeAnswerRows = false): array
    {
        // The aggregate report and CSV retain their existing shape and never load names.
        $responses = $survey->responses()->get($includeAnswerRows ? ['answers', 'respondent_details'] : ['answers']);
        $responseCount = $responses->count();
        $suppressed = $responseCount < self::MINIMUM_RESPONSES;
        $invitations = $survey->invitations()->where('school_id', $survey->school_id);
        $responseRows = $suppressed ? [] : $responses->map(fn (SurveyResponse $response): array => [
            'answers' => $response->answers,
            'respondent' => $includeAnswerRows ? $this->respondent($response) : null,
        ])->all();
        $definitions = $survey->reportQuestions();
        $hasHistory = ! empty($survey->archived_questions);
        $contactKeys = collect($definitions)->filter(fn ($question) => ! empty($question['contact']))->pluck('key')->all();
        $isolatedAnswers = ! $suppressed && $contactKeys !== []
            ? $survey->questionAnswers()->whereIn('question_key', $contactKeys)->get(['question_key', 'answer'])->groupBy('question_key')
            : collect();

        return [
            'response_count' => $responseCount,
            'minimum_responses' => self::MINIMUM_RESPONSES,
            'suppressed' => $suppressed,
            'questions' => $suppressed ? [] : array_values(array_filter(array_map(
                function (array $question) use ($responseRows, $isolatedAnswers, $includeAnswerRows, $responseCount, $hasHistory): array {
                    if (empty($question['contact'])) {
                        $summary = $this->summarizeQuestion($question, $responseRows, $includeAnswerRows);
                    } else {
                        // A contact-enabled answer never shares a questionnaire identifier or row position.
                        $rows = $isolatedAnswers->get($question['key'], collect())->map(fn ($answer) => [
                            'answers' => [$question['key'] => $answer->answer], 'respondent' => null,
                        ])->all();
                        // Preserve legacy records without exposing any global identity or other answers.
                        foreach ($responseRows as $row) {
                            if (isset($row['answers'][$question['key']])) {
                                $rows[] = ['answers' => [$question['key'] => $row['answers'][$question['key']]], 'respondent' => null];
                            }
                        }
                        while (count($rows) < $responseCount) {
                            $rows[] = ['answers' => [], 'respondent' => null];
                        }
                        $summary = $this->summarizeQuestion($question, $rows, $includeAnswerRows);
                    }
                    // We deliberately store no per-person version/exposure metadata.
                    // A revised form therefore has no reliable version-specific skipped count.
                    if ($hasHistory || ($question['introduced_after_publication'] ?? false)) {
                        $summary['skipped'] = null;
                        $summary['archived'] = (bool) ($question['archived'] ?? false);
                    }

                    return $summary;
                },
                array_values($definitions),
            ), fn (array $summary): bool => empty($summary['archived']) || $summary['answered'] > 0)),
            'invitations_count' => (clone $invitations)->count(),
            'participation_count' => FamilyParticipation::query()
                ->whereIn('invitation_id', (clone $invitations)->select('id'))
                ->count(),
        ];
    }

    private function summarizeQuestion(array $question, array $responses, bool $includeAnswerRows): array
    {
        $options = [];

        if ($question['type'] !== 'text') {
            foreach ($question['options'] ?? [] as $option) {
                $options[] = [
                    'value' => $option['value'],
                    'label' => $option['label'],
                    'count' => 0,
                    'percent' => 0.0,
                ];
            }

            if (($question['allow_other'] ?? false) && ! in_array('other', array_column($options, 'value'), true)) {
                $options[] = ['value' => 'other', 'label' => 'Cits', 'count' => 0, 'percent' => 0.0];
            }
        }

        $answered = 0;
        $texts = [];
        $answerRows = [];
        $optionValues = array_column($options, 'value');

        foreach ($responses as $response) {
            $responseAnswers = $response['answers'];
            $answer = $responseAnswers[$question['key']] ?? [];
            $value = $answer['value'] ?? null;

            if ($question['type'] === 'text') {
                if (is_string($value) && trim($value) !== '') {
                    $answered++;
                    $texts[] = trim($value);

                    if ($includeAnswerRows) {
                        $answerRows[] = ['answer' => trim($value), 'respondent' => $response['respondent']];
                    }
                }

                continue;
            }

            // Count each selected option once per respondent, including multiple-choice answers.
            $selected = array_values(array_intersect($optionValues, is_array($value) ? $value : [$value]));

            if ($selected === []) {
                continue;
            }

            $answered++;

            foreach ($options as &$option) {
                if (in_array($option['value'], $selected, true)) {
                    $option['count']++;
                }
            }
            unset($option);

            $other = $answer['other'] ?? null;

            if (($question['allow_other'] ?? false) && in_array('other', $selected, true)
                && is_string($other) && trim($other) !== '') {
                $texts[] = trim($other);
            }

            if ($includeAnswerRows) {
                $labels = [];

                foreach ($options as $option) {
                    if (! in_array($option['value'], $selected, true)) {
                        continue;
                    }

                    $label = $option['label'];

                    if ($option['value'] === 'other' && ($question['allow_other'] ?? false)
                        && is_string($other) && trim($other) !== '') {
                        $label .= ': '.trim($other);
                    }

                    $labels[] = $label;
                }

                $answerRows[] = ['answer' => implode('; ', $labels), 'respondent' => $response['respondent']];
            }
        }

        foreach ($options as &$option) {
            $option['percent'] = $answered === 0 ? 0.0 : round($option['count'] / $answered * 100, 2);
        }
        unset($option);

        // Each question gets a separate permutation so text cannot be linked by response order.
        shuffle($texts);

        $summary = [
            'key' => $question['key'],
            'title' => $question['title'],
            'type' => $question['type'],
            'answered' => $answered,
            'skipped' => count($responses) - $answered,
            'options' => $options,
            'texts' => $texts,
        ];

        if ($includeAnswerRows) {
            // Shuffle anew for every question. No response key or row number leaves this service.
            shuffle($answerRows);
            $summary['answer_rows'] = $answerRows;
        }

        return $summary;
    }

    private function respondent(SurveyResponse $response): ?array
    {
        $details = $response->respondent_details;

        if (! is_array($details)) {
            return null;
        }

        $fields = $details['fields'] ?? null;

        // Support earlier opt-in submissions while rendering all new detail schemas generically.
        if ($fields === null) {
            $fields = [
                ['key' => 'name', 'label' => 'Vārds un uzvārds', 'value' => $details['name'] ?? null],
                ['key' => 'role', 'label' => 'Loma', 'value' => $details['role'] ?? null],
            ];
        }

        if (! is_array($fields)) {
            return null;
        }

        $displayFields = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['label'] ?? null)
                || ! is_string($field['value'] ?? null)
                || trim($field['label']) === '' || trim($field['value']) === '') {
                continue;
            }

            $value = trim($field['value']);
            $key = $field['key'] ?? null;

            if (($field['type'] ?? ($key === 'role' ? 'role' : null)) === 'role') {
                $value = match ($value) {
                    'mother' => 'Mamma',
                    'father' => 'Tētis',
                    'guardian' => 'Cits aizbildnis',
                    default => $value,
                };
            }

            // Field keys can contain UUIDs; only their human-readable labels and values leave here.
            $displayFields[] = ['label' => trim($field['label']), 'value' => $value];
        }

        return $displayFields === [] ? null : ['fields' => $displayFields];
    }
}
