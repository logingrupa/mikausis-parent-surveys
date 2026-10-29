@extends('layouts.public')
@section('title', 'Vecāku balss')
@section('content')
<section class="welcome-layout">
    <div class="welcome-copy">
        <span class="section-label"><span class="orange-dot" aria-hidden="true"></span> Mikauša vecākiem</span>
        <h1>Jūsu domas.<br>Mūsu nākamie soļi.</h1>
        <p class="lead">Mikausis aug kopā ar ģimenēm. Pastāstiet, kas jums ir svarīgs un ko varam veidot kopā.</p>
        <div class="welcome-assurance">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6l-8-3Z"/><path d="m8 12 3 3 5-6"/></svg>
            <p><strong>Bez vecāku kontiem.</strong><br>Atbildes varat iesniegt anonīmi. Dažās aptaujās savus datus var norādīt brīvprātīgi.</p>
        </div>
    </div>
    <div class="survey-directory">
        <div class="directory-heading"><h2>Aktuālās aptaujas</h2><span class="live-label">{{ $surveys->count() }} atvērtas</span></div>
        @forelse ($surveys as $survey)
            <article class="survey-invitation">
                <span class="small-label">Aicinām piedalīties</span>
                <h3>{{ $survey->title }}</h3>
                <p class="muted">{{ count($survey->sections ?? []) }} tematiskās sadaļas <span aria-hidden="true">·</span> Aptuveni {{ $survey->estimated_minutes }} minūtes</p>
                @if ($survey->closes_at)<p class="deadline">Atbildes gaidām līdz {{ $survey->closes_at->format('d.m.Y. H:i') }}</p>@endif
                <a class="button primary" href="{{ route('surveys.show', $survey->slug) }}">Atvērt aptauju <span aria-hidden="true">→</span></a>
                <p class="fine-print">Aizpildiet sev ērtā laikā, arī telefonā.</p>
            </article>
        @empty
            <div class="empty-state"><h3>Šobrīd atvērtu aptauju nav.</h3><p>Kad būs jauna aptauja, dārziņš jums nosūtīs saiti. Paldies par atsaucību!</p></div>
        @endforelse
    </div>
</section>
@endsection
