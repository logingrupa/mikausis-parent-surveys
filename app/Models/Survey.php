<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Survey extends Model
{
    protected $fillable = ['school_id', 'title', 'slug', 'description', 'sections', 'questions', 'status', 'closes_at', 'published_at', 'estimated_minutes', 'allow_identified_responses', 'respondent_fields'];

    protected $attributes = ['status' => 'draft', 'estimated_minutes' => 8, 'allow_identified_responses' => false, 'respondent_fields' => null, 'archived_questions' => '[]'];

    protected function casts(): array
    {
        return ['sections' => 'array', 'questions' => 'array', 'archived_questions' => 'array', 'closes_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'unpublished_at' => 'immutable_datetime', 'estimated_minutes' => 'integer', 'allow_identified_responses' => 'boolean', 'respondent_fields' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (Survey $survey) {
            $survey->slug ??= static::newSlug($survey->title);
            $survey->sections ??= [];
            $survey->questions ??= [];
        });
    }

    public function save(array $options = [])
    {
        // The lock spans reconciliation AND the write, including direct and stale model saves.
        return $this->getConnection()->transaction(function () use ($options) {
            $this->mergeAttributesFromCachedCasts();
            $stored = $this->exists ? $this->newQuery()->lockForUpdate()->findOrFail($this->getKey()) : null;
            if ($stored !== null) {
                $changes = $this->getDirty();
                unset($changes['archived_questions']);
                $this->setRawAttributes($stored->getAttributes(), true);
                $this->setRawAttributes(array_replace($stored->getAttributes(), $changes));
            } else {
                $this->archived_questions = [];
            }
            $this->prepareDefinitionSave($stored);

            return parent::save($options);
        });
    }

    public function delete()
    {
        return $this->getConnection()->transaction(function () {
            $stored = $this->exists ? $this->newQuery()->lockForUpdate()->find($this->getKey()) : null;
            if ($stored !== null && ($stored->published_at !== null || $stored->responses()->exists()
                || $stored->questionAnswers()->exists() || $stored->questionContacts()->exists())) {
                throw ValidationException::withMessages(['survey' => 'Publicētu aptauju vai aptauju ar atbildēm dzēst nevar.']);
            }

            return parent::delete();
        });
    }

    private function prepareDefinitionSave(?self $stored): void
    {
        if (! in_array($this->status, ['draft', 'open', 'closed'], true)) {
            throw ValidationException::withMessages(['status' => 'Izvēlieties derīgu aptaujas statusu.']);
        }
        if ($stored?->published_at !== null) {
            foreach (['school_id', 'slug', 'published_at'] as $field) {
                if ($this->isDirty($field)) {
                    throw ValidationException::withMessages([$field => 'Publicētas aptaujas skolu, saiti un sākotnējo publicēšanas datumu mainīt nevar.']);
                }
            }
            if ($this->status === 'draft') {
                throw ValidationException::withMessages(['status' => 'Publicētu aptauju nevar atgriezt melnrakstā.']);
            }
            if ($this->isDirty('questions')) {
                $this->reconcilePublishedQuestions($stored);
            }
        } elseif (is_array($this->questions)) {
            // A fresh survey or copy starts without historical exposure metadata.
            $questions = $this->questions;
            foreach ($questions as &$question) {
                if (is_array($question)) {
                    unset($question['introduced_after_publication']);
                }
            }
            unset($question);
            if ($questions !== $this->questions) {
                $this->questions = $questions;
            }
        }
        if ($stored !== null && $this->isDirty(['allow_identified_responses', 'respondent_fields'])
            && ($this->allow_identified_responses !== $stored->allow_identified_responses
                || self::fieldSchema($this->identityFields()) !== self::fieldSchema($stored->identityFields()))
            && ($stored->responses()->exists() || $stored->questionAnswers()->exists() || $stored->questionContacts()->exists())) {
            throw ValidationException::withMessages(['respondent_fields' => 'Pēc pirmo atbilžu vai kontaktu saņemšanas respondenta datu ievākšanas veidu un laukus mainīt nevar. Lauku nosaukumus var labot.']);
        }
        if ($this->allow_identified_responses) {
            foreach ($this->questions ?? [] as $index => $question) {
                if (is_array($question) && ($question['contact'] ?? null) !== null) {
                    throw ValidationException::withMessages(["questions.$index.contact" => 'Jautājuma kontaktinformāciju nevar apvienot ar kopīgo respondenta datu ievākšanu.']);
                }
            }
        }
        if ($this->status === 'open' && $this->published_at === null) {
            $this->published_at = now();
        }
        if ($this->status === 'draft' && $this->published_at !== null) {
            throw ValidationException::withMessages(['status' => 'Melnrakstam nevar būt publicēšanas datums.']);
        }
    }

    private function reconcilePublishedQuestions(self $stored): void
    {
        $questions = $this->questions;
        if (! is_array($questions) || ! array_is_list($questions)) {
            throw ValidationException::withMessages(['questions' => 'Aptaujas jautājumi nav derīgi.']);
        }
        $old = array_column($stored->questions ?? [], null, 'key');
        $archive = $stored->archived_questions ?? [];
        $retired = array_column($archive, null, 'key');
        $active = [];
        foreach ($questions as $index => $question) {
            $key = is_array($question) ? ($question['key'] ?? null) : null;
            if (! is_string($key) || $key === '' || isset($active[$key]) || isset($retired[$key])) {
                throw ValidationException::withMessages(["questions.$index.key" => 'Jautājuma identifikators vairs nav derīgs. Pārlādējiet aptaujas redaktoru.']);
            }
            unset($question['archived']);
            // This is survey-definition metadata, never a respondent/version identifier.
            // Reconcile it on the server so clients cannot fabricate or erase it.
            unset($question['introduced_after_publication']);
            if (! isset($old[$key]) || ($old[$key]['introduced_after_publication'] ?? false)) {
                $question['introduced_after_publication'] = true;
            }
            $questions[$index] = $question;
            $active[$key] = true;
        }
        $replacements = [];
        // Propagate replacement identities through conditional questions as well.
        do {
            $changed = false;
            foreach ($questions as $question) {
                $key = $question['key'];
                $source = $question['condition']['question'] ?? null;
                if (isset($old[$key]) && ! isset($replacements[$key])
                    && (self::questionStructureChanged($old[$key], $question) || isset($replacements[$source ?? '']))) {
                    $replacements[$key] = 'q_'.str_replace('-', '', (string) Str::uuid());
                    $changed = true;
                }
            }
        } while ($changed);
        foreach ($old as $key => $question) {
            if (! isset($active[$key]) || isset($replacements[$key])) {
                $archive[] = $question;
            }
        }
        foreach ($questions as &$question) {
            $question['key'] = $replacements[$question['key']] ?? $question['key'];
            if (isset($question['condition']['question'])) {
                $source = $question['condition']['question'];
                if (! isset($active[$source])) {
                    throw ValidationException::withMessages(['questions' => 'Rādīšanas nosacījumā izvēlieties esošu jautājumu.']);
                }
                $question['condition']['question'] = $replacements[$source] ?? $source;
            }
        }
        unset($question);
        $this->questions = $questions;
        if ($archive !== ($stored->archived_questions ?? [])) {
            $this->archived_questions = $archive;
        }
    }

    private static function questionStructureChanged(array $old, array $new): bool
    {
        return ($old['type'] ?? null) !== ($new['type'] ?? null)
            || (bool) ($old['allow_other'] ?? false) !== (bool) ($new['allow_other'] ?? false)
            || array_filter(array_column($old['options'] ?? [], 'value'), fn ($value): bool => ! in_array($value, array_column($new['options'] ?? [], 'value'), true)) !== []
            || self::sortedValues($old['exclusive_values'] ?? []) !== self::sortedValues($new['exclusive_values'] ?? [])
            || self::conditionSchema($old['condition'] ?? null) !== self::conditionSchema($new['condition'] ?? null)
            || self::contactSchema($old['contact'] ?? null) !== self::contactSchema($new['contact'] ?? null);
    }

    private static function sortedValues(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }

    private static function conditionSchema(?array $condition): ?array
    {
        return $condition ? ['question' => $condition['question'] ?? null, 'values' => self::sortedValues($condition['values'] ?? [])] : null;
    }

    private static function fieldSchema(array $fields): array
    {
        $schema = array_map(fn (array $field): array => ['key' => $field['key'], 'type' => $field['type'], 'required' => (bool) ($field['required'] ?? false)], $fields);
        usort($schema, fn (array $left, array $right): int => strcmp($left['key'], $right['key']));

        return $schema;
    }

    private static function contactSchema(?array $contact): ?array
    {
        return $contact === null ? null : ['trigger_values' => self::sortedValues($contact['trigger_values'] ?? []), 'fields' => self::fieldSchema($contact['fields'] ?? [])];
    }

    /** Active definitions followed by historical definitions, without modifying stored JSON. */
    public function reportQuestions(): array
    {
        return [...($this->questions ?? []), ...array_map(fn (array $question): array => [...$question, 'archived' => true], $this->archived_questions ?? [])];
    }

    public function questionDefinition(string $key, bool $includeArchived = false): ?array
    {
        foreach ($includeArchived ? $this->reportQuestions() : ($this->questions ?? []) as $question) {
            if (($question['key'] ?? null) === $key) {
                return $question;
            }
        }

        return null;
    }

    /** A form-only fingerprint; never attached to response or contact rows. */
    public function formDefinitionToken(): string
    {
        return hash('sha256', json_encode([$this->title, $this->description, $this->sections, $this->questions, $this->allow_identified_responses, $this->respondent_fields], JSON_THROW_ON_ERROR));
    }

    private static function newSlug(string $title): string
    {
        return Str::limit(Str::slug($title), 150, '').'-'.Str::lower(Str::random(16));
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    public function questionContacts(): HasMany
    {
        return $this->hasMany(QuestionContact::class);
    }

    public function questionAnswers(): HasMany
    {
        return $this->hasMany(QuestionAnswer::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FamilyInvitation::class);
    }

    public function scopeForSchool(Builder $query, int|School $school): Builder
    {
        return $query->where('school_id', $school instanceof School ? $school->getKey() : $school);
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('status', ['open', 'closed'])->whereNotNull('published_at')->whereNull('unpublished_at');
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->forSchool($user->school_id ?? 0);
    }

    public function isAcceptingResponses(): bool
    {
        return $this->unpublished_at === null && $this->status === 'open' && ($this->closes_at === null || $this->closes_at->isFuture());
    }

    public function respondentFields(): array
    {
        if (! $this->allow_identified_responses) {
            return [];
        }

        return $this->identityFields();
    }

    private function identityFields(): array
    {
        return $this->respondent_fields ?? [
            ['key' => 'name', 'label' => 'Vārds un uzvārds', 'type' => 'text', 'required' => true],
            ['key' => 'role', 'label' => 'Loma', 'type' => 'role', 'required' => true],
        ];
    }

    public function duplicate(): Survey
    {
        return static::create(['school_id' => $this->school_id, 'title' => Str::limit($this->title, 246, '').' (kopija)', 'description' => $this->description, 'sections' => $this->sections, 'questions' => $this->questions, 'status' => 'draft', 'estimated_minutes' => $this->estimated_minutes, 'allow_identified_responses' => $this->allow_identified_responses, 'respondent_fields' => $this->respondent_fields]);
    }
}
