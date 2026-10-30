@extends('layouts.public')
@section('title', 'Paldies par viedokli')
@section('content')
<section class="message-panel thanks-panel">
    <span class="section-label">Atbildes ir iesniegtas</span>
    <h1>Paldies, ka palīdzat veidot Mikausi!</h1>
    <p>Jūsu viedoklis pievienots aptaujas rezultātiem.</p>
    <p class="thanks-survey-title muted">{{ $survey->title }}</p>
</section>
@if (! $survey->allow_identified_responses && collect($survey->questions)->contains(fn ($question) => ! empty($question['contact'])))
@if ($survey->isAcceptingResponses())
    @php
        $contactDefinitions = collect($survey->questions)->filter(fn ($question) => ! empty($question['contact']))->map(fn ($question) => [
            'key' => $question['key'], 'title' => $question['title'],
            'prompt' => $question['contact']['prompt'], 'purpose' => $question['contact']['purpose'],
            'triggerValues' => $question['contact']['trigger_values'],
            'fields' => array_map(fn ($field) => [...$field, 'type' => \App\Support\ContactField::type($field)], $question['contact']['fields']),
            'endpoint' => route('question-contacts.store', ['slug' => $survey->slug, 'question' => $question['key']]),
            'contactId' => (string) \Illuminate\Support\Str::uuid(), 'multiple' => $question['type'] === 'multiple',
        ])->values()->all();
    @endphp
    <form id="contact-followup-form" class="contact-page contact-followup" data-contact-scope="{{ $survey->slug }}" data-contact-definition="{{ $survey->formDefinitionToken() }}" data-session-url="{{ route('surveys.session', $survey->slug) }}" aria-labelledby="shared-contacts-title" hidden>
        @csrf
        <div data-followup-intro>
            <h2 id="shared-contacts-title" tabindex="-1">Saistībā ar jautājumiem par:</h2>
            <ul class="contact-links" data-followup-questions></ul>
            <p>Jums ir iespēja atstāt mums kontaktinformāciju, lai varam ar jums sazināties.</p>
        </div>
        <div class="error-summary" data-followup-errors role="alert" tabindex="-1" hidden></div>
        @include('surveys.partials.shared-contacts', ['followup' => true])
        <button id="followup-submit" class="button primary" type="submit" hidden>Saglabāt kontaktinformāciju <span aria-hidden="true">→</span></button>
    </form>
    <div class="contact-page thanks-contact-status" data-followup-status role="status" tabindex="-1" hidden>
        <strong>Kontaktinformācija saglabāta.</strong>
        <p>Kontaktinformācija ir saistīta tikai ar šo aktivitāšu izvēlēm; pārējās aptaujas atbildes paliek anonīmas.</p>
    </div>
    <script id="contact-followup-definitions" type="application/json">{!! json_encode($contactDefinitions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    <noscript>
        <section class="contact-page contact-followup" aria-labelledby="followup-title">
            <h2 id="followup-title">Vai vēlaties atstāt kontaktinformāciju?</h2>
            <ul class="contact-links">
                @foreach ($survey->questions as $question)
                    @if (! empty($question['contact']))<li><a href="{{ route('question-contacts.show', ['slug' => $survey->slug, 'question' => $question['key']]) }}">{{ $question['title'] }} <span aria-hidden="true">→</span></a></li>@endif
                @endforeach
            </ul>
        </section>
    </noscript>
@endif
@endif
    <div class="thanks-actions">
        @if ($canRepeat ?? $survey->isAcceptingResponses())
            @include('surveys.partials.restart')
        @endif
        <a class="text-link" href="https://mikausis.lv/">Uz Mikauša mājaslapu <span aria-hidden="true">↗</span></a>
        <a class="text-link" href="{{ route('surveys.index') }}">Skatīt citas aptaujas</a>
    </div>
@endsection
@push('scripts')
<script src="{{ asset('js/survey-session.js') }}?v={{ filemtime(public_path('js/survey-session.js')) }}" defer></script>
<script src="{{ asset('js/survey-draft-state.js') }}?v={{ filemtime(public_path('js/survey-draft-state.js')) }}" defer></script>
<script src="{{ asset('js/shared-question-contacts.js') }}?v={{ filemtime(public_path('js/shared-question-contacts.js')) }}" defer></script>
<script src="{{ asset('js/contact-followup-state.js') }}?v={{ filemtime(public_path('js/contact-followup-state.js')) }}" defer></script>
<script src="{{ asset('js/contact-collector.js') }}?v={{ filemtime(public_path('js/contact-collector.js')) }}" defer></script>
<script src="{{ asset('js/contact-followup.js') }}?v={{ filemtime(public_path('js/contact-followup.js')) }}" defer></script>
<script src="{{ asset('js/survey-restart.js') }}?v={{ filemtime(public_path('js/survey-restart.js')) }}" defer></script>
@endpush
