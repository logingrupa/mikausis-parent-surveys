<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class QuestionAnswer extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['survey_id', 'question_key', 'answer'];

    protected $hidden = ['id', 'survey_id', 'question_key', 'survey'];

    protected function casts(): array
    {
        return ['answer' => 'array'];
    }

    protected static function booted(): void
    {
        static::creating(function (QuestionAnswer $answer) {
            $answer->id = (string) Str::uuid();
        });
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
