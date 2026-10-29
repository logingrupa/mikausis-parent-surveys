<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuestionContact extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'survey_id', 'question_key', 'answer', 'details'];

    // Contact data is exposed only through the dedicated, authorized contact view.
    protected $hidden = ['id', 'survey_id', 'question_key', 'answer', 'details', 'survey'];

    protected function casts(): array
    {
        return ['answer' => 'encrypted:array', 'details' => 'encrypted:array'];
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function scopeForSchool(Builder $query, int|School $school): Builder
    {
        return $query->whereHas('survey', fn (Builder $survey) => $survey->forSchool($school));
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->forSchool($user->school_id ?? 0);
    }
}
