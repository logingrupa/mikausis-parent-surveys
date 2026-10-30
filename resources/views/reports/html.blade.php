{{--
THESIS: A readable report, with each question followed immediately by its summary and answers.
OWN-WORLD: Mikausis logo, local Montserrat, violet headings, orange brand detail, white paper.
STORY: Read the question, compare its aggregate results, then read the individual answers and print.
FIRST VIEWPORT: Compact print control above a paper sheet; logo, survey title and totals open the report.
FORM: A narrow extension of the existing report, specified by the brief; no concept seed or new identity.
--}}
<!DOCTYPE html>
<html lang="lv">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src data:; font-src data:; style-src 'unsafe-inline'; script-src 'unsafe-inline'; base-uri 'none'; form-action 'none'">
    <title>{{ $survey->title }} · Aptaujas atskaite</title>
    <style>
        @if ($fontLatinExt)
        @font-face {
            font-family: Montserrat; font-style: normal; font-weight: 400 700; font-display: swap;
            src: url("{{ $fontLatinExt }}") format("woff2");
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C4, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @endif
        @if ($fontLatin)
        @font-face {
            font-family: Montserrat; font-style: normal; font-weight: 400 700; font-display: swap;
            src: url("{{ $fontLatin }}") format("woff2");
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
        @endif
        :root { color-scheme: light; --violet: #3c3876; --orange: #ff7402; --ink: #27243d; --muted: #666276; --border: #dedce8; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #f7f7fb; color: var(--ink); font: 400 15px/1.75 Montserrat, Arial, sans-serif; }
        h1, h2, h3, p, dl { margin: 0; }
        h1, h2, h3 { color: var(--violet); overflow-wrap: anywhere; }
        h1 { max-width: 26ch; font-size: clamp(1.75rem, 4vw, 2.5rem); font-weight: 600; line-height: 1.25; letter-spacing: -.025em; text-wrap: balance; }
        h2 { font-size: 1.2rem; font-weight: 700; line-height: 1.5; }
        h3 { margin-block: 1.5rem .65rem; font-size: .94rem; font-weight: 700; }
        p { max-width: 75ch; overflow-wrap: anywhere; }
        .print-tools { max-width: 920px; margin: 1.5rem auto; padding-inline: 1.5rem; display: flex; align-items: center; flex-wrap: wrap; gap: .75rem 1.5rem; }
        .print-tools p { flex: 1 1 20rem; font-size: .85rem; color: var(--muted); }
        .print-button { min-height: 46px; border: 0; border-radius: 8px; padding: .7rem 1.2rem; background: var(--violet); color: #fff; cursor: pointer; font: 600 .875rem/1.5 Montserrat, Arial, sans-serif; }
        .print-button:hover { background: #2d295c; }
        .print-button:focus-visible { outline: 3px solid var(--violet); outline-offset: 4px; }
        .report { max-width: 920px; margin: 0 auto 2rem; padding: clamp(1.5rem, 5vw, 3.5rem); background: #fff; border: 1px solid var(--border); border-radius: 16px; }
        .report-logo { display: block; width: 175px; max-width: 60%; height: auto; margin-bottom: 1.75rem; }
        .report-kind { margin-bottom: .5rem; color: var(--violet); font-size: .85rem; font-weight: 600; }
        .report-title { padding-bottom: 1.5rem; border-bottom: 3px solid var(--orange); }
        .report-totals { display: flex; flex-wrap: wrap; gap: .75rem 2rem; margin-block: 1.5rem 1rem; font-size: .85rem; }
        .report-totals > div { display: flex; flex-wrap: wrap; align-items: baseline; gap: .4rem; }
        .report-totals dt { color: var(--muted); }
        .report-totals dd { margin: 0; font-weight: 700; color: var(--violet); font-variant-numeric: tabular-nums; }
        .report-note { color: var(--muted); font-size: .82rem; }
        .reading-notes { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--border); }
        .reading-notes p + p { margin-top: .65rem; }
        .question { margin-top: 2.5rem; padding-top: 1.75rem; border-top: 1px solid var(--border); }
        .question-kind { margin-bottom: .35rem; color: var(--muted); font-size: 13px; }
        .question-totals { display: flex; flex-wrap: wrap; gap: .5rem 1.5rem; margin-top: .75rem; font-size: .85rem; }
        .question-totals > div { display: flex; gap: .35rem; }
        .question-totals dd { margin: 0; font-weight: 600; font-variant-numeric: tabular-nums; }
        .summary { width: 100%; margin-top: 1rem; border-collapse: collapse; table-layout: fixed; font-size: .85rem; }
        .summary caption { padding-bottom: .5rem; text-align: left; font-weight: 600; color: var(--violet); }
        .summary th, .summary td { padding: .6rem .65rem; text-align: left; vertical-align: top; overflow-wrap: anywhere; border-bottom: 1px solid var(--border); }
        .summary thead { background: #eeedf7; color: var(--violet); }
        .summary th { font-weight: 600; }
        .summary th:first-child { width: 64%; }
        .summary .number { text-align: right; font-variant-numeric: tabular-nums; }
        .summary-note { margin-top: .5rem; }
        .answers { margin: 0; padding: 0; list-style: none; }
        .answer { margin-top: .75rem; padding: .9rem 1rem; border: 1px solid var(--border); border-radius: 8px; }
        .respondent { margin-bottom: .4rem; color: var(--violet); font-size: 13px; overflow-wrap: anywhere; }
        .respondent dl { display: flex; flex-wrap: wrap; gap: .2rem 1.25rem; }
        .respondent dl > div { display: flex; flex-wrap: wrap; gap: .25rem; }
        .respondent dt { font-weight: 600; }
        .respondent dd { margin: 0; white-space: pre-wrap; }
        .answer-text { white-space: pre-wrap; overflow-wrap: anywhere; word-break: normal; }
        .report-footer { margin-top: 2.5rem; padding-top: 1rem; border-top: 1px solid var(--border); }
        @page { size: A4; margin: 16mm 15mm; }
        @media (max-width: 520px) {
            body { font-size: 14px; }
            .print-tools { padding-inline: 1rem; margin-block: 1rem; }
            .print-button { width: 100%; }
            .report { padding: 1.25rem; border-radius: 0; border-inline: 0; }
            .summary { font-size: 13px; }
            .summary th, .summary td { padding: .55rem .35rem; }
            .summary th:first-child { width: 56%; }
            .answer { padding: .75rem; }
        }
        @media print {
            body { margin: 0; background: #fff; color: #000; font-size: 10pt; line-height: 1.65; }
            .print-tools { display: none !important; }
            .report { max-width: none; margin: 0; padding: 0; border: 0; border-radius: 0; }
            .report-logo { width: 42mm; margin-bottom: 7mm; }
            h1 { font-size: 22pt; }
            h2 { font-size: 13pt; }
            h3 { font-size: 10pt; margin-top: 6mm; }
            h1, h2, h3, .question-kind, caption { break-after: avoid-page; page-break-after: avoid; }
            .report-title { padding-bottom: 6mm; }
            .question { margin-top: 10mm; padding-top: 6mm; break-inside: auto; }
            .question-totals { break-after: avoid-page; }
            .summary { font-size: 9pt; }
            .summary thead { display: table-header-group; }
            .summary tr { break-inside: avoid-page; }
            .answer { padding: 3mm 4mm; border-radius: 0; break-inside: auto; }
            .answer-text { orphans: 3; widows: 3; }
            .respondent { break-after: avoid-page; }
            .report-note { font-size: 8.5pt; }
            .report-title, .summary thead { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <!--email_off-->
    <div class="print-tools">
        <button class="print-button" type="button" onclick="window.print()">Drukāt / saglabāt PDF</button>
        <p>Drukas logā izvēlieties printeri vai “Saglabāt kā PDF”. Šis HTML fails darbojas arī bez interneta.</p>
    </div>
    <main class="report">
        <header>
            @if ($logo)
                <img class="report-logo" src="{{ $logo }}" width="175" height="47" alt="Mikausis">
            @else
                <p class="report-kind">Mikausis</p>
            @endif
            <div class="report-title">
                <p class="report-kind">Vecāku viedoklis · Aptaujas atskaite</p>
                <h1>{{ $survey->title }}</h1>
            </div>
            <dl class="report-totals">
                <div><dt>Iesniegtas anketas</dt><dd>{{ $report['response_count'] }}</dd></div>
                <div><dt>Ģimeņu uzaicinājumi</dt><dd>{{ $report['invitations_count'] }}</dd></div>
                <div><dt>Atzīmētas dalības</dt><dd>{{ $report['participation_count'] }}</dd></div>
            </dl>
            <p class="report-note">Dalības uzskaite ir atsevišķa no atbilžu satura. Uzaicinājums ir ģimenei; vienā ģimenē var piedalīties vairāki aizbildņi. Šie skaitļi nav izmantojami kā dalības procenta saucējs.</p>
        </header>

        <section class="reading-notes" aria-label="Kā lasīt atskaiti">
            <p>Atbildes ir apkopotas pa jautājumiem. Procentus rēķina no cilvēkiem, kuri uz attiecīgo jautājumu atbildēja. Vairāku izvēļu procentu summa var pārsniegt 100%.</p>
            <p class="report-note">“Izlaiduši” ietver arī anketas, kurās nosacītais papildjautājums netika parādīts. Anonīmo atbilžu secība katrā jautājumā ir nejauša un nav sasaistāma starp jautājumiem. Respondenta dati redzami tikai pie atbildēm, kur viņš izvēlējies tos norādīt. Brīvajā tekstā respondents var pats sevi identificēt.</p>
        </section>

        @forelse ($report['questions'] as $question)
            <section class="question" aria-labelledby="question-{{ $loop->iteration }}">
                <p class="question-kind">Jautājums {{ $loop->iteration }} · {{ match ($question['type']) { 'single' => 'Viena izvēle', 'multiple' => 'Vairākas izvēles', 'text' => 'Brīvais teksts', default => 'Atbildes' } }}</p>
                <h2 id="question-{{ $loop->iteration }}">{{ $question['title'] }}</h2>
                @if ($question['archived'] ?? false)
                    <p class="report-note">Iepriekšējā jautājuma versija · saglabātās atbildes</p>
                @endif
                <dl class="question-totals">
                    <div><dt>Atbildējuši:</dt><dd>{{ $question['answered'] }}</dd></div>
                    <div><dt>Izlaiduši:</dt><dd>{{ $question['skipped'] ?? '—' }}</dd></div>
                </dl>
                @if ($question['skipped'] === null)
                    <p class="report-note">Aptaujas saturs ir mainīts. Izlaisto atbilžu skaitu pa versijām neuzskaitām.</p>
                @endif

                @if ($question['options'] !== [])
                    <table class="summary">
                        <caption>Atbilžu kopsavilkums</caption>
                        <thead><tr><th scope="col">Atbildes variants</th><th scope="col" class="number">Skaits</th><th scope="col" class="number">Procenti</th></tr></thead>
                        <tbody>
                            @foreach ($question['options'] as $option)
                                <tr><td>{{ $option['label'] }}</td><td class="number">{{ $option['count'] }}</td><td class="number">{{ number_format((float) $option['percent'], 2, ',', ' ') }}%</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                    <p class="report-note summary-note">Procentu saucējs: {{ $question['answered'] }} atbildējušie.</p>
                @endif

                @if ($question['answer_rows'] !== [])
                    <h3>Saņemtās atbildes</h3>
                    <ul class="answers">
                        @foreach ($question['answer_rows'] as $row)
                            <li class="answer">
                                <div class="respondent">
                                    @if ($row['respondent'])
                                        <dl>
                                            @foreach ($row['respondent']['fields'] as $field)
                                                <div><dt>{{ $field['label'] }}:</dt><dd>{{ $field['value'] }}</dd></div>
                                            @endforeach
                                        </dl>
                                    @else
                                        <p>Anonīmi</p>
                                    @endif
                                </div>
                                <p class="answer-text">{{ $row['answer'] }}</p>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="report-note summary-note">Uz šo jautājumu vēl nav atbilžu.</p>
                @endif
            </section>
        @empty
            <p class="reading-notes">Šajā aptaujā nav jautājumu.</p>
        @endforelse

        <footer class="report-footer">
            <p class="report-note">Atskaite iestādes lietošanai. Failā ir respondentu sniegtās atbildes un, ja norādīti, viņu dati. Glabājiet un kopīgojiet to atbildīgi.</p>
        </footer>
    </main>
    <!--/email_off-->
    @if ($autoPrint)
        <script id="report-auto-print">{!! file_get_contents(public_path('js/report-print.js')) !!}</script>
    @endif
</body>
</html>
