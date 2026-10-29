<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FamilyInvitation extends Model
{
    protected $fillable = ['school_id', 'survey_id', 'label'];

    protected $hidden = ['token', 'token_hash', 'label'];

    protected function casts(): array
    {
        return ['token' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::creating(function (FamilyInvitation $invitation) {
            $invitation->token = Str::random(48);
            $invitation->token_hash = hash('sha256', $invitation->token);
        });
        static::saving(function (FamilyInvitation $invitation) {
            $survey = Survey::query()->find($invitation->survey_id);
            if ($survey === null || (int) $survey->school_id !== (int) $invitation->school_id) {
                throw ValidationException::withMessages(['survey_id' => 'Aptaujai un ģimenes saitei jābūt no vienas iestādes.']);
            }
            if ($invitation->exists && $invitation->isDirty(['school_id', 'survey_id', 'token', 'token_hash'])) {
                throw ValidationException::withMessages(['survey_id' => 'Esošu ģimenes saiti nevar pārcelt uz citu aptauju vai iestādi.']);
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function survey(): BelongsTo
    {
        return $this->belongsTo(Survey::class);
    }

    public function participations(): HasMany
    {
        return $this->hasMany(FamilyParticipation::class, 'invitation_id');
    }

    public function scopeForSchool(Builder $query, int|School $school): Builder
    {
        $schoolId = $school instanceof School ? $school->getKey() : $school;

        return $query->where('school_id', $schoolId)->whereHas('survey', fn (Builder $survey) => $survey->forSchool($schoolId));
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->forSchool($user->school_id ?? 0);
    }
}
