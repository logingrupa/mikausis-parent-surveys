@php
    $repeatParameters = ['slug' => $survey->slug];
    if ($invitation ?? null) {
        $repeatParameters['token'] = $invitation->token;
    }
@endphp
<form method="post" action="{{ route(($invitation ?? null) ? 'surveys.family.restart' : 'surveys.restart', $repeatParameters) }}" data-survey-restart data-session-url="{{ route(($invitation ?? null) ? 'surveys.family.session' : 'surveys.session', $repeatParameters) }}" data-draft-scope="{{ $draftScope ?? \App\Support\SurveySubmissionResumeToken::draftScope($survey, $invitation ?? null) }}" data-contact-scope="{{ $survey->slug }}" data-contact-definition="{{ $survey->formDefinitionToken() }}">
    @csrf
    <button type="submit" class="button secondary">Aizpildīt vēlreiz (Otram vecākam)</button>
    <p class="field-error" data-restart-error role="alert" tabindex="-1" hidden></p>
</form>
