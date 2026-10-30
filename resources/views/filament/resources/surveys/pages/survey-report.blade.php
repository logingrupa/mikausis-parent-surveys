<x-filament-panels::page>
    <style>
        .mikausis-report { --report-purple: #3c3876; --report-orange: #ff7402; display: grid; gap: 1.5rem; color: #292638; }
        .mikausis-report h2, .mikausis-report h3, .mikausis-report h4 { color: var(--report-purple); font-weight: 700; line-height: 1.45; }
        .mikausis-report h2 { font-size: clamp(1.3rem, 3vw, 1.8rem); }
        .mikausis-report h3 { font-size: 1.1rem; }
        .mikausis-report p { line-height: 1.7; }
        .mikausis-report-card { padding: clamp(1.1rem, 3vw, 2rem); border: 1px solid #e5e3ee; border-radius: 1rem; background: #fff; overflow-wrap: anywhere; }
        .mikausis-report-intro { border-top: 1px solid #dedce8; }
        .mikausis-report-eyebrow { color: var(--report-purple); font-size: .8rem; font-weight: 700; letter-spacing: 0; }
        .mikausis-report-meta, .mikausis-report-note { color: #625d72; font-size: .875rem; }
        .mikausis-report-actions { display: flex; gap: .75rem; flex-wrap: wrap; margin-block: 1.25rem .75rem; }
        .mikausis-report-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); gap: 1rem; margin-block: 1.5rem; }
        .mikausis-report-stats > div { padding: 1rem; border-radius: .75rem; background: #f6f5fb; }
        .mikausis-report-stats dt { font-size: .875rem; color: #625d72; }
        .mikausis-report-stats dd { font-size: 2rem; font-weight: 700; color: var(--report-purple); font-variant-numeric: tabular-nums; }
        .mikausis-report-question { break-inside: avoid; }
        .mikausis-report-question h3 { margin-block: .35rem .75rem; }
        .mikausis-report-totals { display: flex; flex-wrap: wrap; gap: .5rem 1.25rem; margin-block: .75rem; }
        .mikausis-report-totals > div { display: flex; gap: .35rem; }
        .mikausis-report-totals dd { font-weight: 700; font-variant-numeric: tabular-nums; }
        .mikausis-report-options { display: grid; gap: 1.1rem; margin-block: 1.25rem; list-style: none; padding: 0; }
        .mikausis-report-option-label { display: flex; justify-content: space-between; align-items: baseline; gap: 1rem; margin-bottom: .4rem; }
        .mikausis-report-option-value { flex-shrink: 0; font-weight: 700; color: var(--report-purple); font-variant-numeric: tabular-nums; }
        .mikausis-report-track { height: .6rem; border-radius: 1rem; background: #eeedf4; overflow: hidden; }
        .mikausis-report-bar { height: 100%; background: var(--report-purple); border-radius: inherit; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .mikausis-report-comments { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid #e5e3ee; }
        .mikausis-report-comments ul { display: grid; gap: .75rem; margin-top: 1rem; padding: 0; list-style: none; }
        .mikausis-report-comments li { padding: 1rem; border: 1px solid #dedce8; border-radius: .5rem; background: #faf9fc; white-space: pre-wrap; line-height: 1.7; }
        .mikausis-report-empty { border-color: #dedce8; }
        @media (max-width: 480px) {
            .mikausis-report-option-label { display: block; }
            .mikausis-report-option-value { display: block; margin-top: .25rem; }
        }
        @media print {
            .fi-sidebar, .fi-topbar, .fi-breadcrumbs, .mikausis-report-actions, .mikausis-report-export-note { display: none !important; }
            .fi-main { padding: 0 !important; max-width: none !important; }
            .mikausis-report { color: #000; gap: 1rem; }
            .mikausis-report-card { padding: 1rem; border-color: #ccc; border-radius: 0; box-shadow: none; }
            .mikausis-report-comments li { break-inside: avoid; }
            .mikausis-report-stats dd { font-size: 1.5rem; }
        }
    </style>

    <div class="mikausis-report">
        <section class="mikausis-report-card mikausis-report-intro" aria-labelledby="report-survey-title">
            <p class="mikausis-report-eyebrow">Mikausis · Vecāku viedoklis</p>
            <h2 id="report-survey-title">{{ $survey->title }}</h2>
            <p class="mikausis-report-meta">
                {{ match ($survey->status) { 'open' => 'Publicēta aptauja', 'closed' => 'Slēgta aptauja', default => 'Melnraksts' } }}
                · Atjaunots {{ now()->format('d.m.Y. H:i') }}
            </p>

            <div class="mikausis-report-actions">
                @if (collect($survey->reportQuestions())->contains(fn (array $question): bool => is_array($question['contact'] ?? null)))
                    <x-filament::button tag="a" :href="\App\Filament\Resources\Surveys\SurveyResource::getUrl('contacts', ['record' => $survey])" color="gray" icon="heroicon-m-user-group">
                        Brīvprātīgie kontakti
                    </x-filament::button>
                @endif
                @if (! $report['suppressed'])
                    <x-filament::button tag="a" :href="route('reports.export', ['survey' => $survey])" icon="heroicon-m-arrow-down-tray">
                        Lejupielādēt CSV
                    </x-filament::button>
                    <x-filament::button tag="a" :href="route('reports.html', ['survey' => $survey])" target="_blank" rel="noopener noreferrer" color="gray" icon="heroicon-m-document-text">
                        Atvērt drukas skatu
                    </x-filament::button>
                    <x-filament::button tag="a" :href="route('reports.html.download', ['survey' => $survey])" color="gray" icon="heroicon-m-arrow-down-tray">
                        Lejupielādēt HTML
                    </x-filament::button>
                    <x-filament::button tag="a" :href="route('reports.html', ['survey' => $survey, 'print' => 1])" target="_blank" rel="noopener noreferrer" color="gray" icon="heroicon-m-printer">
                        Drukāt atskaiti
                    </x-filament::button>
                @else
                    <x-filament::button disabled icon="heroicon-m-arrow-down-tray">
                        CSV vēl nav pieejams
                    </x-filament::button>
                    <x-filament::button disabled color="gray" icon="heroicon-m-printer">
                        Drukāt atskaiti
                    </x-filament::button>
                @endif
            </div>
            <p class="mikausis-report-note mikausis-report-export-note">CSV satur atbilžu variantu skaitu un procentus. Drukas skatā un HTML failā atbildes sakārtotas pa jautājumiem. Drukas logā atskaiti var saglabāt PDF formātā.</p>

            <dl class="mikausis-report-stats">
                <div><dt>Iesniegtas anketas</dt><dd>{{ $report['response_count'] }}</dd></div>
                <div><dt>Ģimeņu uzaicinājumi</dt><dd>{{ $report['invitations_count'] }}</dd></div>
                <div><dt>Atzīmētas dalības</dt><dd>{{ $report['participation_count'] }}</dd></div>
            </dl>
            <p class="mikausis-report-note">Dalības uzskaite ir atsevišķa no atbilžu satura. Kopīgās saites iesniegumiem dalības atzīme var nebūt. Uzaicinājums ir ģimenei; vienā ģimenē var piedalīties vairāki aizbildņi. Šie skaitļi nav izmantojami kā dalības procenta saucējs.</p>
        </section>

        @if ($report['suppressed'])
            <section class="mikausis-report-card mikausis-report-empty" role="status" aria-labelledby="report-empty-title">
                <h3 id="report-empty-title">{{ $report['response_count'] === 0 ? 'Vēl nav iesniegtu atbilžu' : 'Rezultātu apkopošanai vajadzīgas vēl atbildes' }}</h3>
                <p>Rezultāti un komentāri būs pieejami, kad būs iesniegtas vismaz {{ $report['minimum_responses'] }} anketas. Šis slieksnis palīdz aizsargāt respondentu privātumu.</p>
                <p class="mikausis-report-note">Pašlaik iesniegts: {{ $report['response_count'] }}. Arī CSV eksports līdz šim slieksnim ir slēgts.</p>
            </section>
        @else
            <section class="mikausis-report-card" aria-labelledby="report-reading-title">
                <h3 id="report-reading-title">Kā lasīt rezultātus</h3>
                <p>Katram jautājumam procentus rēķina no cilvēkiem, kuri uz šo jautājumu atbildēja. Vairāku izvēļu jautājumos viens cilvēks var atzīmēt vairākus variantus, tāpēc procentu summa var pārsniegt 100%.</p>
                <p class="mikausis-report-note">“Izlaiduši” ir iesniegtās anketas bez atbildes uz šo jautājumu; tajās ietilpst arī anketas, kurās nosacītais papildjautājums netika parādīts.</p>
            </section>

            @forelse ($report['questions'] as $question)
                <article class="mikausis-report-card mikausis-report-question" aria-labelledby="report-question-{{ $loop->iteration }}">
                    <p class="mikausis-report-eyebrow">Jautājums {{ $loop->iteration }} · {{ match ($question['type']) { 'single' => 'Viena izvēle', 'multiple' => 'Vairākas izvēles', 'text' => 'Brīvais teksts', default => 'Atbildes' } }}</p>
                    <h3 id="report-question-{{ $loop->iteration }}">{{ $question['title'] }}</h3>
                    @if ($question['archived'] ?? false)
                        <p class="mikausis-report-note">Iepriekšējā jautājuma versija · saglabātās atbildes</p>
                    @endif
                    <dl class="mikausis-report-totals">
                        <div><dt>Atbildējuši:</dt><dd>{{ $question['answered'] }}</dd></div>
                        <div><dt>Izlaiduši:</dt><dd>{{ $question['skipped'] ?? '—' }}</dd></div>
                    </dl>
                    @if ($question['skipped'] === null)
                        <p class="mikausis-report-note">Aptaujas saturs ir mainīts. Izlaisto atbilžu skaitu pa versijām neuzskaitām.</p>
                    @endif

                    @if ($question['answered'] === 0)
                        <p class="mikausis-report-note">Uz šo jautājumu vēl nav atbilžu.</p>
                    @endif

                    @if ($question['options'] !== [])
                        <p class="mikausis-report-note">Procentu saucējs: {{ $question['answered'] }} atbildējušie.</p>
                        <ul class="mikausis-report-options">
                            @foreach ($question['options'] as $option)
                                <li>
                                    <div class="mikausis-report-option-label">
                                        <span>{{ $option['label'] }}</span>
                                        <span class="mikausis-report-option-value">{{ $option['count'] }} no {{ $question['answered'] }} · {{ number_format((float) $option['percent'], 2, ',', ' ') }}%</span>
                                    </div>
                                    <div class="mikausis-report-track" aria-hidden="true">
                                        <div class="mikausis-report-bar" style="width: {{ max(0, min(100, (float) $option['percent'])) }}%"></div>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if ($question['texts'] !== [])
                        <section class="mikausis-report-comments" aria-label="Komentāri par jautājumu">
                            <h4>{{ $question['type'] === 'text' ? 'Brīvā teksta atbildes' : 'Komentāri' }} ({{ count($question['texts']) }})</h4>
                            <p class="mikausis-report-note">Respondents brīvajā tekstā var būt norādījis personisku informāciju. Pirms komentāru tālākas kopīgošanas izvērtējiet to saturu.</p>
                            <ul>
                                @foreach ($question['texts'] as $text)
                                    <li>{{ $text }}</li>
                                @endforeach
                            </ul>
                        </section>
                    @elseif ($question['type'] === 'text')
                        <p class="mikausis-report-note">Brīvā teksta atbilžu vēl nav.</p>
                    @endif
                </article>
            @empty
                <section class="mikausis-report-card mikausis-report-empty">
                    <h3>Aptaujā nav apkopojamu jautājumu</h3>
                    <p>Pārbaudiet aptaujas jautājumus administrācijas panelī.</p>
                </section>
            @endforelse
        @endif
    </div>
</x-filament-panels::page>
