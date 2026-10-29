<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class FamilyParticipation extends Model
{
    public $timestamps = false;

    protected $fillable = ['invitation_id', 'role'];

    protected static function booted(): void
    {
        static::saving(function (FamilyParticipation $participation) {
            if (! in_array($participation->role, ['mother', 'father', 'guardian'], true)) {
                throw ValidationException::withMessages(['role' => 'Izvēlieties vecāka lomu.']);
            }
        });
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(FamilyInvitation::class, 'invitation_id');
    }

    public function scopeForSchool(Builder $query, int|School $school): Builder
    {
        return $query->whereHas('invitation', fn (Builder $invitation) => $invitation->forSchool($school));
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->forSchool($user->school_id ?? 0);
    }
}
