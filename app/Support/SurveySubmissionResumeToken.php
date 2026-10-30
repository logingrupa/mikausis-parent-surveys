<?php

namespace App\Support;

use App\Models\FamilyInvitation;
use App\Models\Survey;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use JsonException;

class SurveySubmissionResumeToken
{
    public static function issue(Survey $survey, ?FamilyInvitation $invitation, string $submissionId): string
    {
        return Crypt::encryptString(json_encode([
            'purpose' => 'survey-submission-resume',
            ...self::scope($survey, $invitation),
            'submission_id' => $submissionId,
        ], JSON_THROW_ON_ERROR));
    }

    public static function draftScope(Survey $survey, ?FamilyInvitation $invitation): string
    {
        return hash_hmac('sha256', 'survey-draft:'.json_encode(self::scope($survey, $invitation), JSON_THROW_ON_ERROR), config('app.key'));
    }

    public static function submissionId(string $token, Survey $survey, ?FamilyInvitation $invitation): string
    {
        abort_if(strlen($token) > 4096, 403);

        try {
            $proof = json_decode(Crypt::decryptString($token), true, 16, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            abort(403);
        }

        abort_unless(is_array($proof)
            && ($proof['purpose'] ?? null) === 'survey-submission-resume'
            && is_string($proof['submission_id'] ?? null) && Str::isUuid($proof['submission_id']), 403);

        foreach (self::scope($survey, $invitation) as $key => $value) {
            abort_unless(array_key_exists($key, $proof) && $proof[$key] === $value, 403);
        }

        return $proof['submission_id'];
    }

    private static function scope(Survey $survey, ?FamilyInvitation $invitation): array
    {
        return [
            'survey_id' => $survey->id,
            'invitation_id' => $invitation?->id,
            'invitation_token_hash' => $invitation?->token_hash,
        ];
    }
}
