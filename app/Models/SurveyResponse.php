<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SurveyResponse extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'survey_id', 'answers', 'respondent_details'];

    protected $hidden = ['respondent_details'];

    protected function casts(): array
    {
        return ['answers' => 'array', 'respondent_details' => 'encrypted:array'];
    }

    protected static function booted(): void
    {
        static::creating(function (SurveyResponse $response) {
            $response->id ??= (string) Str::uuid();
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
