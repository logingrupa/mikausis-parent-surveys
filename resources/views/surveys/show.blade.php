{{-- THESIS: actual survey in thematic chapters. OWN-WORLD: original Mikausis logo, violet/orange, Montserrat. STORY: understand, answer, review, submit. FIRST VIEWPORT: survey purpose beside a chapter directory. FORM: chapter workbook, surface seed a90f88c5 candidate 7. --}}
@extends('layouts.public')
@section('title', $survey->title)
@section('content')
@php
    $sections = $survey->sections ?? [];
    $questions = $survey->questions ?? [];
    $usedRoles = $invitation ? $invitation->participations->pluck('role')->all() : [];
    $familyComplete = $invitation && count(array_intersect(['mother', 'father', 'guardian'], $usedRoles)) === 3;
    $oldValues = $oldValues ?? [];
    $oldAnswers = is_array($oldValues['answers'] ?? null) ? $oldValues['answers'] : [];
    $oldOtherAnswers = is_array($oldValues['other_answers'] ?? null) ? $oldValues['other_answers'] : [];
    $respondentFields = $survey->respondentFields();
    $hasQuestionContacts = ! $survey->allow_identified_responses && collect($questions)->contains(fn ($question) => ! empty($question['contact']));
    $isAnonymous = ! in_array($oldValues['anonymous'] ?? true, [false, 0, '0'], true);
    $oldRespondent = is_array($oldValues['respondent'] ?? null) ? $oldValues['respondent'] : [];
    $oldFamilyRole = is_string($oldValues['role'] ?? null) ? $oldValues['role'] : '';
    $identityTargets = ['anonymous' => 'respondent-anonymous', 'respondent' => 'respondent-identity'];
    foreach ($respondentFields as $field) {
        $identityTargets['respondent.'.$field['key']] = 'respondent-'.$field['key'];
    }
@endphp
<div class="survey-page-heading"><a href="{{ route('surveys.index') }}" class="back-link">← Visas aptaujas</a><span class="small-label">Vecāku balss</span></div>
@if (! $survey->isAcceptingResponses())
    <section class="message-panel"><span class="state-symbol" aria-hidden="true">✓</span><h1>Šī aptauja ir noslēgusies.</h1><p>Atbildes vairs netiek pieņemtas. Paldies par interesi un iesaistīšanos!</p><a class="button primary" href="{{ route('surveys.index') }}">Uz aptauju sākumlapu</a></section>
@elseif ($familyComplete)
    <span hidden data-completed-draft-scope="{{ $draftScope }}" data-contact-definition="{{ $survey->formDefinitionToken() }}"></span>
    <section class="message-panel"><span class="state-symbol" aria-hidden="true">✓</span><h1>Paldies jūsu ģimenei!</h1><p>Šajā ģimenes saitē visas pieejamās lomas jau ir iesniegušas atbildes. Jaunu anketu sākt vairs nav nepieciešams.</p><a class="button primary" href="{{ route('surveys.index') }}">Uz aptauju sākumlapu</a></section>
@elseif ($submitted && ! $invitation)
    <span hidden data-completed-draft-scope="{{ $draftScope }}" data-contact-definition="{{ $survey->formDefinitionToken() }}"></span>
    <section class="message-panel"><span class="state-symbol" aria-hidden="true">✓</span><h1>Paldies, jūsu atbilde ir iesniegta.</h1><p>Šajā pārlūkā šī aptauja jau ir aizpildīta.</p>
    @include('surveys.partials.restart')
    <a class="text-link" href="{{ route('surveys.index') }}">Uz aptauju sākumlapu</a></section>
@else
<form id="survey-form" action="{{ $invitation ? route('surveys.family.submit', ['slug' => $survey->slug, 'token' => request()->route('token')]) : route('surveys.submit', $survey->slug) }}" method="post" novalidate data-section-count="{{ count($sections) }}" data-contact-scope="{{ $survey->slug }}" data-contact-definition="{{ $survey->formDefinitionToken() }}" data-session-url="{{ $invitation ? route('surveys.family.session', ['slug' => $survey->slug, 'token' => request()->route('token')]) : route('surveys.session', $survey->slug) }}" data-session-resume="{{ $submissionResumeToken }}" data-draft-scope="{{ $draftScope }}">
    @csrf
    <input type="hidden" name="submission_id" value="{{ $submissionId }}">
    <input type="hidden" name="form_definition" value="{{ $survey->formDefinitionToken() }}">
    <div class="survey-layout">
        <aside class="survey-guide" aria-label="Aptaujas sadaļas">
            <p class="guide-label">{{ $survey->title }}</p>
            <ol class="chapter-list">
                @foreach ($sections as $index => $section)
                    <li><button type="button" data-go-step="{{ $index }}"><span class="chapter-number">{{ $index + 1 }}</span><span>{{ $section['title'] }}</span></button></li>
                @endforeach
                <li><button type="button" data-go-step="{{ count($sections) }}"><span class="chapter-number" aria-hidden="true">✓</span><span>Pārskatīt un iesniegt</span></button></li>
            </ol>
            <div class="guide-note"><span class="orange-dot" aria-hidden="true"></span><p>Katrs viedoklis palīdz.<br>Atbildiet uz sev aktuālo.</p></div>
            <a class="privacy-link" href="{{ route('surveys.privacy') }}" target="_blank" rel="noopener">Kā sargājam atbildes ↗</a>
        </aside>
        <div class="survey-workspace">
            <div class="mobile-progress" hidden><span id="mobile-step-label">Pirms sākam</span><span id="step-fraction"></span><div class="progress-track"><span id="progress-fill"></span></div></div>
            <div id="form-errors" class="error-summary" role="alert" tabindex="-1" @if (! $errors->any()) hidden @endif>
                <strong>Lūdzu, pārbaudiet atbildes.</strong>
                <ul>
                    @foreach ($errors->getMessages() as $errorKey => $messages)
                        @foreach ($messages as $error)
                            <li>
                                @if (str_starts_with($errorKey, 'answers.') || str_starts_with($errorKey, 'other_answers.'))
                                    <a href="#question-{{ explode('.', $errorKey)[1] }}" data-error-question="{{ explode('.', $errorKey)[1] }}">{{ $error }}</a>
                                @elseif ($errorKey === 'role')
                                    <a href="#role-picker" data-error-role>{{ $error }}</a>
                                @elseif (isset($identityTargets[$errorKey]))
                                    <a href="#{{ $identityTargets[$errorKey] }}" data-error-identity="{{ $identityTargets[$errorKey] }}">{{ $error }}</a>
                                @else
                                    {{ $error }}
                                @endif
                            </li>
                        @endforeach
                    @endforeach
                </ul>
            </div>
            <section class="survey-intro" data-step="-1" aria-labelledby="intro-title">
                <span class="section-label"><span class="orange-dot" aria-hidden="true"></span> Aptuveni {{ $survey->estimated_minutes }} minūtes</span>
                <h1 id="intro-title" tabindex="-1">{{ $survey->title }}</h1>
                <div class="intro-description">{!! nl2br(e($survey->description)) !!}</div>
                @if ($invitation)
                    <fieldset class="role-picker" id="role-picker"><legend>Kurš ģimenes pārstāvis aizpilda?</legend><p class="field-help" id="role-help">Dārziņš redzēs dalības atzīmi, bet atbilžu saturu ģimenei nepiesaistām.</p><div class="role-options">@foreach (['mother' => 'Mamma', 'father' => 'Tētis', 'guardian' => 'Cits aizbildnis'] as $role => $label)<label class="choice"><input type="radio" name="role" value="{{ $role }}" required aria-describedby="role-help error-role" @if($errors->has('role')) aria-invalid="true" @endif @checked(($oldValues['role'] ?? null) === $role && !in_array($role, $usedRoles, true)) @disabled(in_array($role, $usedRoles, true))><span>{{ $label }}@if (in_array($role, $usedRoles, true))<small>Jau aizpildīts</small>@endif</span></label>@endforeach</div><p class="field-error" data-field-error id="error-role" @if(!$errors->has('role')) hidden @endif>{{ $errors->first('role') }}</p></fieldset>
                @endif
                @if ($respondentFields !== [])
                    @include('surveys.partials.respondent')
                @endif
                <button type="button" class="button primary" data-next hidden>Sākt aptauju <span aria-hidden="true">→</span></button>
                <p class="fine-print">Jautājumus var izlaist. Atbildes nosūtīsim tikai pēc pogas “Iesniegt atbildes” nospiešanas.</p>
                <div class="privacy-notice"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14v3"/></svg><div><strong>Jūsu viedoklis ir svarīgs.</strong><p>@if ($respondentFields !== [])Pēc noklusējuma atbildat anonīmi. Ja vēlaties norādīt savus datus, izmantojiet tam paredzētos laukus.@elseif ($hasQuestionContacts)Kontaktinformāciju varat atstāt brīvprātīgi tam paredzētajos laukos. Tā būs saistīta tikai ar izvēli attiecīgajā jautājumā, nevis pārējām aptaujas atbildēm.@else Atbildat anonīmi. Brīvajās atbildēs neiekļaujiet personas datus.@endif</p></div></div>
                @if ($survey->closes_at)<p class="fine-print">Aptauja atvērta līdz {{ $survey->closes_at->format('d.m.Y. H:i') }}.</p>@endif
            </section>
            @foreach ($sections as $index => $section)
                <section class="survey-section" data-step="{{ $index }}" aria-labelledby="section-title-{{ $index }}">
                    <div class="section-heading"><span class="small-label">{{ $index + 1 }}. sadaļa no {{ count($sections) }}</span><h2 id="section-title-{{ $index }}" tabindex="-1">{{ $section['title'] }}</h2>@if (!empty($section['description']))<p>{{ $section['description'] }}</p>@endif</div>
                    @foreach ($questions as $question)
                        @continue(($question['section'] ?? ($sections[0]['key'] ?? null)) !== $section['key'])
                        @php
                            $oldAnswer = $oldAnswers[$question['key']] ?? null;
                            $oldOther = $oldOtherAnswers[$question['key']] ?? null;
                            $fieldMessages = array_merge($errors->get('answers.'.$question['key']), $errors->get('other_answers.'.$question['key']));
                            $fieldDescription = (!empty($question['description']) ? 'help-'.$question['key'].' ' : '').'error-'.$question['key'];
                        @endphp
                        <fieldset class="question" id="question-{{ $question['key'] }}" data-question="{{ $question['key'] }}" data-title="{{ $question['title'] }}" data-type="{{ $question['type'] }}" data-condition="{{ json_encode($question['condition'] ?? null) }}" data-exclusive="{{ json_encode($question['exclusive_values'] ?? []) }}">
                            <legend>{{ $question['title'] }}@if ($question['required'] ?? false)<span class="required-label"> Obligāti</span>@endif</legend>
                            @if (!empty($question['description']))<p class="field-help" id="help-{{ $question['key'] }}">{{ $question['description'] }}</p>@endif
                            @if ($question['type'] === 'multiple')<p class="choice-hint">Varat izvēlēties vairākus variantus.</p>@endif
                            @if ($question['type'] === 'text')
                                <label class="sr-only" for="input-{{ $question['key'] }}">{{ $question['title'] }}</label><textarea id="input-{{ $question['key'] }}" name="answers[{{ $question['key'] }}]" rows="4" maxlength="2000" placeholder="Jūsu domas…" aria-describedby="{{ $fieldDescription }} limit-{{ $question['key'] }}" @required($question['required'] ?? false) @if($fieldMessages) aria-invalid="true" @endif>{{ is_string($oldAnswer) ? $oldAnswer : '' }}</textarea><span class="field-help" id="limit-{{ $question['key'] }}">Līdz 2000 rakstzīmēm.</span>
                            @else
                                @php
                                    $displayOptions = $question['options'] ?? [];
                                    if (($question['allow_other'] ?? false) && !in_array('other', array_column($displayOptions, 'value'), true)) {
                                        $displayOptions[] = ['value' => 'other', 'label' => 'Cits variants'];
                                    }
                                @endphp
                                <div class="answer-options">@foreach ($displayOptions as $option)<label class="choice"><input type="{{ $question['type'] === 'multiple' ? 'checkbox' : 'radio' }}" name="answers[{{ $question['key'] }}]{{ $question['type'] === 'multiple' ? '[]' : '' }}" value="{{ $option['value'] }}" aria-describedby="{{ $fieldDescription }}" @checked(is_array($oldAnswer) ? in_array($option['value'], $oldAnswer, true) : $oldAnswer === $option['value']) @if($fieldMessages) aria-invalid="true" @endif @if (($question['required'] ?? false) && $question['type'] === 'single') required @endif><span>{{ $option['value'] === 'other' ? 'Cits variants' : $option['label'] }}</span></label>@endforeach</div>
                                @if ($question['allow_other'] ?? false)<div class="other-answer" data-other><label for="other-{{ $question['key'] }}">Jūsu variants</label><textarea id="other-{{ $question['key'] }}" name="other_answers[{{ $question['key'] }}]" rows="2" maxlength="2000" placeholder="Lūdzu, pastāstiet īsi…" aria-describedby="{{ $fieldDescription }}">{{ is_string($oldOther) ? $oldOther : '' }}</textarea></div>@endif
                                <button type="button" class="clear-answer" data-clear hidden>Notīrīt izvēli</button>
                            @endif
                            <p class="field-error" data-field-error id="error-{{ $question['key'] }}" @if(!$fieldMessages) hidden @endif>{{ implode(' ', $fieldMessages) }}</p>
                            @if (! $survey->allow_identified_responses && ! empty($question['contact']))
                                @include('surveys.partials.question-contact')
                            @endif
                        </fieldset>
                    @endforeach
                </section>
            @endforeach
            <section class="review-section" data-step="{{ count($sections) }}" aria-labelledby="review-title">
                <span class="section-label"><span class="orange-dot" aria-hidden="true"></span> Pēdējais solis</span><h2 id="review-title" tabindex="-1">Iesniegt atbildes</h2><p class="muted">Pirms iesniegšanas pārbaudiet savas izvēles. Atbildes vēl varat labot.</p><div id="review-answers"></div>
                @if ($hasQuestionContacts)
                    @include('surveys.partials.shared-contacts')
                @endif
                <p class="privacy-final" data-submission-note>@if ($respondentFields !== [])Ja atstājat atzīmi “Anonīmi”, atbilžu saturam nepievienojam jūsu identitāti. Ja noņemat atzīmi un norādāt savus datus, tie kopā ar atbildēm būs redzami administrācijai. @else Atbilžu saturam nepievienojam jūsu identitāti. @if ($hasQuestionContacts)Atbilstošo aktivitāšu kontaktinformāciju nosūtīsim atsevišķi un nepiesaistīsim pārējām atbildēm. @endif @endif Pēc iesniegšanas atbildes vairs nevarēsiet labot.</p><button class="button primary" type="submit" id="submit-button">Iesniegt atbildes <span aria-hidden="true">→</span></button><a class="text-link" data-skip-contacts hidden>Pabeigt bez kontaktu atkārtošanas</a>
            </section>
            <div class="step-actions" hidden><button type="button" class="button secondary" data-previous>← Atpakaļ</button><span class="optional-note">Atbildiet uz sev aktuālo.</span><button type="button" class="button primary" data-next>Tālāk <span aria-hidden="true">→</span></button></div>
            <noscript><p class="privacy-notice">Visi jautājumi ir redzami vienā lapā. Papildjautājumus aizpildiet tikai tad, ja tie attiecas uz jūsu izvēli.</p></noscript>
        </div>
    </div>
</form>
@endif
@endsection
@push('scripts')
<script src="{{ asset('js/survey-session.js') }}?v={{ filemtime(public_path('js/survey-session.js')) }}" defer></script>
<script src="{{ asset('js/survey-draft-state.js') }}?v={{ filemtime(public_path('js/survey-draft-state.js')) }}" defer></script>
<script src="{{ asset('js/shared-question-contacts.js') }}?v={{ filemtime(public_path('js/shared-question-contacts.js')) }}" defer></script>
<script src="{{ asset('js/contact-followup-state.js') }}?v={{ filemtime(public_path('js/contact-followup-state.js')) }}" defer></script>
<script src="{{ asset('js/contact-collector.js') }}?v={{ filemtime(public_path('js/contact-collector.js')) }}" defer></script>
<script src="{{ asset('js/survey.js') }}?v={{ filemtime(public_path('js/survey.js')) }}" defer></script>
<script src="{{ asset('js/survey-restart.js') }}?v={{ filemtime(public_path('js/survey-restart.js')) }}" defer></script>
@endpush
