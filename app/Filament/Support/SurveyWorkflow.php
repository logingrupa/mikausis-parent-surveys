<?php

namespace App\Filament\Support;

use App\Models\Survey;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SurveyWorkflow
{
    private const FIELD_KEY_PATTERN = '/^(?:name|role|email|phone|detail_[a-f0-9]{32}|detail_[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12})$/';

    public static function definition(array $data, bool $publishing = false): array
    {
        $data = SurveyEditorData::definition($data);
        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'estimated_minutes' => ['required', 'integer', 'min:1', 'max:120'],
            'closes_at' => ['nullable', 'date'],
            'allow_identified_responses' => ['required', 'boolean'],
            'respondent_fields' => ['nullable', 'array', 'list', 'max:12'],
            'respondent_fields.*' => ['required', 'array:key,label,type,required'],
            'respondent_fields.*.key' => ['required', 'string', 'regex:'.self::FIELD_KEY_PATTERN, 'distinct:strict'],
            'respondent_fields.*.label' => ['required', 'string', 'max:160'],
            'respondent_fields.*.type' => ['required', 'in:text,email,tel,role'],
            'respondent_fields.*.required' => ['required', 'boolean'],
            'sections' => ['present', 'array', 'list', 'max:40', ...($publishing ? ['min:1'] : [])],
            'sections.*' => ['required', 'array'],
            'sections.*.key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/', 'distinct:strict'],
            'sections.*.title' => ['required', 'string', 'max:255'],
            'sections.*.description' => ['nullable', 'string', 'max:5000'],
            'questions' => ['present', 'array', 'list', 'max:100', ...($publishing ? ['min:1'] : [])],
            'questions.*' => ['required', 'array'],
            'questions.*.key' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z][A-Za-z0-9_-]*$/', 'distinct:strict'],
            'questions.*.section' => ['required', 'string'],
            'questions.*.type' => ['required', 'in:single,multiple,text'],
            'questions.*.title' => ['required', 'string', 'max:2000'],
            'questions.*.description' => ['nullable', 'string', 'max:5000'],
            'questions.*.required' => ['required', 'boolean'],
            'questions.*.allow_other' => ['required', 'boolean'],
            'questions.*.options' => ['present', 'array', 'list', 'max:100'],
            'questions.*.options.*' => ['required', 'array'],
            'questions.*.options.*.value' => ['required', 'string', 'max:100'],
            'questions.*.options.*.label' => ['required', 'string', 'max:2000'],
            'questions.*.exclusive_values' => ['present', 'array', 'list'],
            'questions.*.exclusive_values.*' => ['string'],
            'questions.*.condition' => ['nullable', 'array'],
            'questions.*.condition.question' => ['required_with:questions.*.condition', 'string'],
            'questions.*.condition.values' => ['required_with:questions.*.condition', 'array', 'list', 'min:1'],
            'questions.*.condition.values.*' => ['string'],
            'questions.*.contact' => ['nullable', 'array:prompt,purpose,trigger_values,fields'],
        ];
        // Scope distinct field keys and trigger values to each question.
        foreach (is_array($data['questions']) ? $data['questions'] : [] as $index => $question) {
            if (! is_array($question) || ($question['contact'] ?? null) === null) {
                continue;
            }
            $path = "questions.$index.contact";
            $rules["$path.prompt"] = ['bail', 'required', 'string', 'max:160'];
            $rules["$path.purpose"] = ['bail', 'required', 'string', 'max:1000'];
            $rules["$path.trigger_values"] = ['bail', 'required', 'array', 'list', 'min:1'];
            $rules["$path.trigger_values.*"] = ['bail', 'required', 'string', 'distinct:strict'];
            $rules["$path.fields"] = ['bail', 'required', 'array', 'list', 'min:1', 'max:12'];
            $rules["$path.fields.*"] = ['required', 'array:key,label,type,required'];
            $rules["$path.fields.*.key"] = ['bail', 'required', 'string', 'regex:'.self::FIELD_KEY_PATTERN, 'distinct:strict'];
            $rules["$path.fields.*.label"] = ['bail', 'required', 'string', 'max:160'];
            $rules["$path.fields.*.type"] = ['bail', 'required', 'string', 'in:text,email,tel,role'];
            $rules["$path.fields.*.required"] = ['required', 'boolean'];
        }
        $validator = Validator::make($data, $rules, [
            'sections.min' => 'Pievienojiet vismaz vienu sadaļu.',
            'questions.min' => 'Pirms publicēšanas pievienojiet vismaz vienu jautājumu.',
            'required' => 'Aizpildiet lauku ":attribute".',
            'required_with' => 'Aizpildiet lauku ":attribute".',
            'present' => 'Aizpildiet lauku ":attribute".',
            'string' => 'Laukā ":attribute" ievadiet tekstu.',
            'array' => 'Lauks ":attribute" nav derīgs. Pārlādējiet lapu un mēģiniet vēlreiz.',
            'list' => 'Lauka ":attribute" secība nav derīga. Pārlādējiet lapu un mēģiniet vēlreiz.',
            'boolean' => 'Laukā ":attribute" izvēlieties jā vai nē.',
            'integer' => 'Laukā ":attribute" ievadiet veselu skaitli.',
            'date' => 'Laukā ":attribute" ievadiet derīgu datumu.',
            'in' => 'Laukā ":attribute" izvēlieties piedāvāto atbildes veidu.',
            'regex' => 'Lauks ":attribute" nav derīgs. Pievienojiet šo vienumu no jauna.',
            'distinct' => 'Lauks ":attribute" atkārtojas. Pievienojiet šo vienumu no jauna.',
            'min' => 'Laukā ":attribute" jānorāda vismaz :min.',
            'max' => 'Lauks ":attribute" pārsniedz atļauto garumu vai skaitu (:max).',
        ], self::attributes($data));
        $validator->after(function ($validator) use ($data) {
            if ($data['respondent_fields'] !== null && ! is_array($data['respondent_fields']) && ! $validator->errors()->has('respondent_fields')) {
                $validator->errors()->add('respondent_fields', 'Respondenta datu lauki nav derīgi. Pārlādējiet lapu un mēģiniet vēlreiz.');
            }
            foreach (['sections' => 'Aptaujas sadaļas', 'questions' => 'Aptaujas jautājumi'] as $field => $label) {
                if (! is_array($data[$field]) && ! $validator->errors()->has($field)) {
                    $validator->errors()->add($field, "Lauks \"$label\" nav derīgs. Pārlādējiet lapu un mēģiniet vēlreiz.");
                }
            }
            $sections = self::stringValues($data['sections'], 'key');
            $sectionOrder = array_flip($sections);
            $earlier = [];
            foreach (is_array($data['questions']) ? $data['questions'] : [] as $index => $question) {
                if (! is_array($question)) {
                    continue;
                }
                $context = ((int) $index + 1).'. jautājums';
                foreach (['options' => 'atbilžu varianti', 'exclusive_values' => 'izslēdzošās atbildes'] as $field => $label) {
                    if (! is_array($question[$field] ?? null) && ! $validator->errors()->has("questions.$index.$field")) {
                        $validator->errors()->add("questions.$index.$field", "$context: pārbaudiet lauku \"$label\". Pārlādējiet lapu un mēģiniet vēlreiz.");
                    }
                }
                if (($question['condition'] ?? null) !== null && ! is_array($question['condition']) && ! $validator->errors()->has("questions.$index.condition")) {
                    $validator->errors()->add("questions.$index.condition", "$context: rādīšanas nosacījums nav derīgs. Izvēlieties nosacījumu no jauna.");
                }
                if (! in_array($question['section'] ?? null, $sections, true)) {
                    $validator->errors()->add("questions.$index.section", "$context: izvēlieties esošu sadaļu.");
                }
                $values = self::stringValues($question['options'] ?? [], 'value');
                if (count($values) !== count(array_unique($values, SORT_STRING))) {
                    $validator->errors()->add("questions.$index.options", "$context: atbilžu varianti atkārtojas. Pievienojiet atkārtoto variantu no jauna.");
                }
                if (($question['type'] ?? '') !== 'text' && count($values) < 1) {
                    $validator->errors()->add("questions.$index.options", "$context: pievienojiet atbilžu variantus.");
                }
                if (is_array($question['exclusive_values'] ?? null) && array_diff(array_filter($question['exclusive_values'], is_string(...)), $values)) {
                    $validator->errors()->add("questions.$index.exclusive_values", "$context: izslēdzošo atbildi izvēlieties no šī jautājuma atbilžu variantiem.");
                }
                $contact = $question['contact'] ?? null;
                if ($contact !== null) {
                    $path = "questions.$index.contact";
                    if (! is_array($contact) && ! $validator->errors()->has($path)) {
                        $validator->errors()->add($path, "$context: kontaktinformācijas iestatījumi nav derīgi. Pievienojiet tos no jauna.");
                    }
                    if (! in_array($question['type'] ?? null, ['single', 'multiple'], true)) {
                        $validator->errors()->add($path, "$context: kontaktinformāciju var pieprasīt tikai izvēles jautājumā.");
                    }
                    if (in_array($data['allow_identified_responses'], [true, 1, '1'], true)) {
                        $validator->errors()->add($path, "$context: pirms kontaktinformācijas pievienošanas izslēdziet kopīgo respondenta datu ievākšanu.");
                    }
                    if (is_array($contact) && is_array($contact['trigger_values'] ?? null)) {
                        foreach ($contact['trigger_values'] as $triggerIndex => $trigger) {
                            if (is_string($trigger) && ($trigger === 'other' || ! in_array($trigger, $values, true))) {
                                $validator->errors()->add("$path.trigger_values.$triggerIndex", "$context: kontaktinformācijas nosacījumā izvēlieties esošu šī jautājuma atbildi, izņemot variantu \"Cits\".");
                            }
                        }
                    }
                }
                $condition = $question['condition'] ?? null;
                if (is_array($condition) && $condition !== []) {
                    $sourceKey = $condition['question'] ?? null;
                    $source = is_string($sourceKey) ? ($earlier[$sourceKey] ?? null) : null;
                    $sourceSection = $source['section'] ?? null;
                    $targetSection = $question['section'] ?? null;
                    $displayedLater = is_string($sourceSection) && is_string($targetSection)
                        && isset($sectionOrder[$sourceSection], $sectionOrder[$targetSection])
                        && $sectionOrder[$sourceSection] > $sectionOrder[$targetSection];
                    if (! $source || ($source['type'] ?? '') === 'text' || $displayedLater) {
                        $validator->errors()->add("questions.$index.condition.question", "$context: nosacījumā izvēlieties agrāku izvēles jautājumu.");
                    } elseif (is_array($condition['values'] ?? null) && array_diff(array_filter($condition['values'], is_string(...)), self::stringValues($source['options'] ?? [], 'value'))) {
                        $validator->errors()->add("questions.$index.condition.values", "$context: nosacījumā izvēlieties kādu no agrākā jautājuma atbildēm.");
                    }
                }
                if (is_string($question['key'] ?? null)) {
                    $earlier[$question['key']] = $question;
                }
            }
        });
        $validator->validate();

        $data['allow_identified_responses'] = (bool) $data['allow_identified_responses'];
        if (is_array($data['respondent_fields'])) {
            foreach ($data['respondent_fields'] as &$field) {
                $field['required'] = (bool) $field['required'];
            }
            unset($field);
        }

        return $data;
    }

    private static function stringValues(mixed $items, string $field): array
    {
        $values = [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (is_array($item) && is_string($item[$field] ?? null)) {
                $values[] = $item[$field];
            }
        }

        return $values;
    }

    private static function attributes(array $data): array
    {
        $attributes = [
            'title' => 'aptaujas nosaukums', 'description' => 'aptaujas ievads',
            'estimated_minutes' => 'aizpildīšanas laiks minūtēs', 'closes_at' => 'atbilžu pieņemšanas termiņš',
            'sections' => 'sadaļas', 'questions' => 'jautājumi',
            'allow_identified_responses' => 'iespēja norādīt vārdu un lomu',
            'respondent_fields' => 'respondenta dati',
        ];
        foreach (is_array($data['respondent_fields']) ? $data['respondent_fields'] : [] as $index => $field) {
            $context = ((int) $index + 1).'. respondenta datu lauks';
            $attributes["respondent_fields.$index"] = $context;
            foreach (['key' => 'identifikators', 'label' => 'nosaukums', 'type' => 'veids', 'required' => 'obligāts lauks'] as $name => $label) {
                $attributes["respondent_fields.$index.$name"] = "$context: $label";
            }
        }
        foreach (is_array($data['sections']) ? $data['sections'] : [] as $index => $section) {
            $context = ((int) $index + 1).'. sadaļa';
            $attributes["sections.$index"] = $context;
            foreach (['key' => '', 'title' => 'virsraksts', 'description' => 'ievads'] as $field => $label) {
                $attributes["sections.$index.$field"] = trim("$context $label");
            }
        }
        foreach (is_array($data['questions']) ? $data['questions'] : [] as $index => $question) {
            $context = ((int) $index + 1).'. jautājums';
            $attributes["questions.$index"] = $context;
            foreach (['key' => '', 'section' => 'sadaļa', 'type' => 'atbildes veids', 'title' => 'teksts', 'description' => 'paskaidrojums', 'required' => 'obligāta atbilde', 'allow_other' => 'cits variants', 'options' => 'atbilžu varianti', 'exclusive_values' => 'izslēdzošās atbildes', 'condition' => 'rādīšanas nosacījums', 'condition.question' => 'nosacījuma jautājums', 'condition.values' => 'nosacījuma atbildes', 'contact' => 'kontaktinformācija', 'contact.prompt' => 'kontaktinformācijas aicinājums', 'contact.purpose' => 'kontaktinformācijas izmantošanas mērķis', 'contact.trigger_values' => 'kontaktinformācijas nosacījuma atbildes', 'contact.fields' => 'kontaktinformācijas lauki'] as $field => $label) {
                $attributes["questions.$index.$field"] = trim("$context $label");
            }
            if (! is_array($question)) {
                continue;
            }
            foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $optionIndex => $option) {
                $optionContext = $context.', '.((int) $optionIndex + 1).'. atbilde';
                $attributes["questions.$index.options.$optionIndex"] = $optionContext;
                $attributes["questions.$index.options.$optionIndex.value"] = $optionContext;
                $attributes["questions.$index.options.$optionIndex.label"] = "$optionContext teksts";
            }
            $contact = $question['contact'] ?? null;
            foreach (is_array($contact) && is_array($contact['fields'] ?? null) ? $contact['fields'] : [] as $fieldIndex => $field) {
                $fieldContext = $context.', '.((int) $fieldIndex + 1).'. kontaktinformācijas lauks';
                $attributes["questions.$index.contact.fields.$fieldIndex"] = $fieldContext;
                foreach (['key' => 'identifikators', 'label' => 'nosaukums', 'type' => 'veids', 'required' => 'obligāts lauks'] as $name => $label) {
                    $attributes["questions.$index.contact.fields.$fieldIndex.$name"] = "$fieldContext: $label";
                }
            }
            foreach (['exclusive_values', 'condition.values', 'contact.trigger_values'] as $field) {
                $items = data_get($question, $field);
                foreach (is_array($items) ? $items : [] as $itemIndex => $item) {
                    $attributes["questions.$index.$field.$itemIndex"] = $attributes["questions.$index.$field"];
                }
            }
        }

        return $attributes;
    }

    public static function create(array $data): Survey
    {
        $schoolId = SchoolAccess::schoolId();
        abort_unless($schoolId, 403);
        $data = self::definition($data);

        return Survey::query()->create([...$data, 'school_id' => $schoolId, 'status' => 'draft']);
    }

    public static function update(Survey $survey, array $data): Survey
    {
        return DB::transaction(function () use ($survey, $data) {
            $current = Survey::query()->lockForUpdate()->findOrFail($survey->getKey());
            SchoolAccess::authorize($current);
            $fields = ['closes_at', 'estimated_minutes', 'title', 'description', 'sections', 'questions', 'allow_identified_responses', 'respondent_fields'];
            $original = $current->only($fields);
            $data = self::definition([...$original, ...Arr::only($data, [...$fields, 'builder'])], $current->published_at !== null);
            $normalizedOriginal = SurveyEditorData::definition($original);
            foreach (['sections', 'questions', 'respondent_fields'] as $field) {
                if ($data[$field] === $normalizedOriginal[$field]) {
                    $data[$field] = $original[$field];
                }
            }
            $current->fill(Arr::only($data, $fields))->save();

            return $current;
        });
    }

    public static function publish(Survey $survey): Survey
    {
        return DB::transaction(function () use ($survey) {
            $current = Survey::query()->lockForUpdate()->findOrFail($survey->getKey());
            SchoolAccess::authorize($current);
            $original = $current->only(['title', 'description', 'estimated_minutes', 'closes_at', 'sections', 'questions', 'allow_identified_responses', 'respondent_fields']);
            $definition = self::definition($original, true);
            if ($current->closes_at && $current->closes_at->isPast()) {
                throw ValidationException::withMessages(['closes_at' => 'Pirms atvēršanas norādiet nākotnes termiņu vai noņemiet termiņu.']);
            }
            if (! $current->published_at) {
                $normalizedOriginal = SurveyEditorData::definition($original);
                foreach (['sections', 'questions'] as $field) {
                    if ($definition[$field] !== $normalizedOriginal[$field]) {
                        $current->setAttribute($field, $definition[$field]);
                    }
                }
            }
            $current->status = 'open';
            $current->unpublished_at = null;
            $current->published_at ??= now();
            $current->save();

            return $current;
        });
    }

    public static function close(Survey $survey): Survey
    {
        $current = Survey::query()->findOrFail($survey->getKey());
        SchoolAccess::authorize($current);
        $current->status = 'closed';
        $current->save();

        return $current;
    }

    public static function unpublish(Survey $survey): Survey
    {
        return DB::transaction(function () use ($survey) {
            $current = Survey::query()->lockForUpdate()->findOrFail($survey->getKey());
            SchoolAccess::authorize($current);
            abort_unless($current->published_at !== null, 422);
            $current->status = 'closed';
            $current->unpublished_at ??= now();
            $current->save();

            return $current;
        });
    }

    public static function duplicate(Survey $survey): Survey
    {
        $current = Survey::query()->findOrFail($survey->getKey());
        SchoolAccess::authorize($current);

        return $current->duplicate();
    }
}
