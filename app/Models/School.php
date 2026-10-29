<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    protected $fillable = ['name', 'slug'];

    public function surveys(): HasMany
    {
        return $this->hasMany(Survey::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FamilyInvitation::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function scopeAccessibleTo(Builder $query, User $user): Builder
    {
        return $query->whereKey($user->school_id ?? 0);
    }
}
