<?php

namespace App\Filament\Support;

use App\Models\Survey;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

final class SchoolAccess
{
    public static function schoolId(): ?int
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_admin && $user->school_id && $user->school()->exists()
            ? (int) $user->school_id : null;
    }

    public static function owns(Survey $survey): bool
    {
        $schoolId = self::schoolId();

        return $schoolId !== null && $schoolId === (int) $survey->school_id;
    }

    public static function authorize(Survey $survey): void
    {
        abort_unless(self::owns($survey), 403);
    }
}
