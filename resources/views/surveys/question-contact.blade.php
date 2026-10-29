@extends('layouts.public')
@section('title', 'Kontaktinformācija aktivitātei')
@section('content')
@php
    $oldValues = $oldValues ?? [];
    $oldFields = is_array($oldValues['fields'] ?? null) ? $oldValues['fields'] : [];
    $oldAnswer = $oldValues['answer'] ?? [];
    $selectedAnswers = is_array($oldAnswer) ? $oldAnswer : [$oldAnswer];
    $config = $question['contact'];
@endphp
<article class="contact-page">
    <a class="back-link" href="{{ route('surveys.show', $survey->slug) }}">← Atpakaļ uz aptauju</a>
    <span class="section-label">Atsevišķa kontaktinformācija</span>
    <h1>{{ $question['title'] }}</h1>
    @if ($question['archived'] ?? false)
        <p>Šis jautājums ir mainīts. Jaunu kontaktinformāciju iepriekšējai versijai vairs nepieņemam. Atveriet aktuālo aptauju.</p>
    @elseif (! $survey->isAcceptingResponses())
        <p>Šī aptauja ir noslēgusies. Jaunu kontaktinformāciju vairs nepieņemam.</p>
    @else
        <p>{{ $config['purpose'] }}</p>
        <p class="privacy-notice">Šajā lapā nosūtāt tikai izvēli par šo jautājumu un savus kontaktus. Šo ierakstu nepiesaistām citām aptaujas atbildēm vai ģimenes dalības atzīmei. Aptaujas aizpildīšana un kontaktinformācijas atstāšana ir brīvprātīga un atsevišķa darbība.</p>
        @if ($errors->any())
            <div class="error-summary" role="alert" tabindex="-1"><strong>Lūdzu, pārbaudiet kontaktinformāciju.</strong><ul>
                @foreach ($errors->getMessages() as $key => $messages)
                    @foreach ($messages as $message)<li><a href="#{{ str_starts_with($key, 'fields.') ? 'separate-'.explode('.', $key)[1] : ($key === 'answer' ? 'contact-answer' : 'separate-consent') }}">{{ $message }}</a></li>@endforeach
                @endforeach
            </ul></div>
        @endif
        <form method="post" action="{{ route('question-contacts.store', ['slug' => $survey->slug, 'question' => $question['key']]) }}">
            @csrf
            <input type="hidden" name="contact_id" value="{{ $contactId }}">
            <fieldset class="question" id="contact-answer"><legend>Jūsu izvēle šajā jautājumā</legend>
                <div class="answer-options">
                    @foreach ($question['options'] as $option)
                        @continue(! in_array($option['value'], $config['trigger_values'], true))
                        <label class="choice"><input type="{{ $question['type'] === 'multiple' ? 'checkbox' : 'radio' }}" name="answer{{ $question['type'] === 'multiple' ? '[]' : '' }}" value="{{ $option['value'] }}" @checked(in_array($option['value'], $selectedAnswers, true)) @if ($question['type'] === 'single') required @endif aria-describedby="separate-answer-error"><span>{{ $option['label'] }}</span></label>
                    @endforeach
                </div>
                <p class="field-error" id="separate-answer-error">{{ $errors->first('answer') }}</p>
            </fieldset>
            @foreach ($config['fields'] as $field)
                @php
                    $value = is_string($oldFields[$field['key']] ?? null) ? $oldFields[$field['key']] : '';
                    $error = 'fields.'.$field['key'];
                @endphp
                <div class="respondent-field">
                    <label for="separate-{{ $field['key'] }}">{{ $field['label'] }}@if ($field['required'])<span class="required-label"> Obligāti</span>@endif</label>
                    @if ($field['type'] === 'role')
                        <select id="separate-{{ $field['key'] }}" name="fields[{{ $field['key'] }}]" @required($field['required']) aria-describedby="separate-error-{{ $field['key'] }}" @if ($errors->has($error)) aria-invalid="true" @endif>
                            <option value="">Izvēlieties lomu</option>
                            @foreach (['mother' => 'Mamma', 'father' => 'Tētis', 'guardian' => 'Cits aizbildnis'] as $role => $label)<option value="{{ $role }}" @selected($value === $role)>{{ $label }}</option>@endforeach
                        </select>
                    @else
                        <input id="separate-{{ $field['key'] }}" type="{{ $field['type'] }}" name="fields[{{ $field['key'] }}]" value="{{ $value }}" maxlength="{{ match ($field['type']) { 'email' => 254, 'tel' => 40, default => 160 } }}" @required($field['required']) aria-describedby="separate-error-{{ $field['key'] }}" @if ($errors->has($error)) aria-invalid="true" @endif>
                    @endif
                    <p class="field-error" id="separate-error-{{ $field['key'] }}">{{ $errors->first($error) }}</p>
                </div>
            @endforeach
            <label class="anonymous-choice" for="separate-consent"><input id="separate-consent" type="checkbox" name="consent" value="1" required @checked(in_array($oldValues['consent'] ?? false, [true, 1, '1'], true)) aria-describedby="separate-consent-error"><span>{{ $config['prompt'] }}</span></label>
            <p class="field-help">Piekrītu norādītās izvēles un kontaktinformācijas nodošanai iestādei šīs aktivitātes organizēšanai. Šī darbība pati par sevi neiesniedz aptaujas atbildes.</p>
            <p class="field-error" id="separate-consent-error">{{ $errors->first('consent') }}</p>
            <button type="submit" class="button primary">Saglabāt kontaktinformāciju <span aria-hidden="true">→</span></button>
        </form>
    @endif
    <p class="field-help"><a href="{{ route('surveys.privacy') }}">Kā glabājam kontaktinformāciju</a></p>
</article>
@endsection
