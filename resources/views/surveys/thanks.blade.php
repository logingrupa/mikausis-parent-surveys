@extends('layouts.public')
@section('title', 'Paldies par viedokli')
@section('content')
<section class="message-panel thanks-panel"><span class="state-symbol" aria-hidden="true">✓</span><span class="section-label">Atbildes ir iesniegtas</span><h1>Paldies, ka palīdzat<br>veidot Mikausi!</h1><p>Jūsu viedoklis pievienots aptaujas rezultātiem. Administrācija to izmantos, plānojot nākamos soļus.</p><p class="muted">{{ $survey->title }}</p><a class="button primary" href="https://mikausis.lv/">Uz Mikauša mājaslapu <span aria-hidden="true">↗</span></a><a class="text-link" href="{{ route('surveys.index') }}">Skatīt citas aptaujas</a></section>
@if (! $survey->allow_identified_responses && collect($survey->questions)->contains(fn ($question) => ! empty($question['contact'])))
<section class="contact-page contact-followup" aria-labelledby="followup-title">
    <h2 id="followup-title">Vai vēlaties atstāt kontaktinformāciju?</h2>
    <p>Ja pie aktivitātes kontaktus jau nosūtījāt, atkārtoti tie nav jāiesniedz. Varat tos atstāt arī šeit, atsevišķi no aptaujas atbildēm.</p>
    <ul class="contact-links">
        @foreach ($survey->questions as $question)
            @if (! empty($question['contact']))<li><a href="{{ route('question-contacts.show', ['slug' => $survey->slug, 'question' => $question['key']]) }}">{{ $question['title'] }} <span aria-hidden="true">→</span></a></li>@endif
        @endforeach
    </ul>
</section>
@endif
@endsection
