@extends('layouts.public')
@section('title', 'Kontaktinformācija saglabāta')
@section('content')
<section class="message-panel">
    <span class="state-symbol" aria-hidden="true">✓</span>
    <h1>Kontaktinformācija saglabāta.</h1>
    <p>Iestāde varēs sazināties par šo aktivitāti:</p>
    <p><strong>{{ $question['title'] }}</strong></p>
    <p class="muted">Šis ieraksts nav piesaistīts citām aptaujas atbildēm. Tas pats par sevi neiesniedz aptaujas anketu.</p>
    <a class="button primary" href="{{ route('surveys.show', $survey->slug) }}">Atvērt aptauju</a>
    <a class="text-link" href="{{ route('surveys.index') }}">Uz aptauju sākumlapu</a>
</section>
@endsection
