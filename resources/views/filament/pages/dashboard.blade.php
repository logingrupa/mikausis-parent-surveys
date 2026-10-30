<x-filament-panels::page>
    <div class="admin-intro">
        <p class="admin-eyebrow">{{ auth()->user()->school?->name }}</p>
        <h2 class="admin-title">Vecāku viedoklis. Kopīgi lēmumi.</h2>
        <p>Izveidojiet aptauju, nosūtiet saiti vecākiem un skatiet apkopotos rezultātus.</p>
    </div>

    <div class="admin-stats" aria-label="Iestādes kopsavilkums" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem">
        @foreach ([['Aptaujas', $surveyCount], ['Atvērtas aptaujas', $openCount], ['Anonīmas atbildes', $responseCount], ['Ģimeņu saites', $invitationCount]] as [$label, $value])
            <x-filament::section>
                <p class="admin-stat-label">{{ $label }}</p>
                <p class="admin-stat-value" style="font-size:2rem;font-weight:700;color:#3c3876">{{ $value }}</p>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Jūsu aptaujas" description="Katru aptauju var kopēt nākamajai reizei. Ģimeņu dalība un atbilžu saturs tiek glabāti atsevišķi.">
        <div class="admin-actions" style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.5rem">
            <x-filament::button tag="a" :href="\App\Filament\Resources\Surveys\SurveyResource::getUrl('create')" icon="heroicon-o-plus">Izveidot aptauju</x-filament::button>
            <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\Surveys\SurveyResource::getUrl('index')">Visas aptaujas</x-filament::button>
        </div>
        @forelse ($surveys as $survey)
            @php($surveyEditUrl = \App\Filament\Resources\Surveys\SurveyResource::getUrl('edit', ['record' => $survey]))
            <article class="admin-survey-row">
                <div class="admin-survey-details">
                    <h3><a href="{{ $surveyEditUrl }}">{{ $survey->title }}<x-filament::icon icon="heroicon-o-chevron-right" class="admin-survey-title-icon" /></a></h3>
                    <p>{{ \App\Filament\Resources\Surveys\SurveyResource::statusLabel($survey->status) }} · {{ $survey->responses_count }} atbildes · {{ count($survey->questions ?? []) }} jautājumi</p>
                </div>
                <div class="admin-survey-actions">
                    <x-filament::button tag="a" :href="$surveyEditUrl" icon="heroicon-o-pencil-square">Pārvaldīt</x-filament::button>
                    <x-filament::button tag="a" color="gray" :href="\App\Filament\Resources\Surveys\SurveyResource::getUrl('report', ['record' => $survey])">Skatīt rezultātus</x-filament::button>
                </div>
            </article>
        @empty
            <div class="admin-empty" style="padding:2rem 0">
                <h3 style="font-weight:600">Sāksim ar pirmo aptauju</h3>
                <p style="margin-top:.5rem">Pievienojiet sadaļas un jautājumus. Publicējiet tikai tad, kad saturs ir pārbaudīts.</p>
            </div>
        @endforelse
    </x-filament::section>

    <x-filament::section heading="Kā strādā ģimenes saite?">
        <p>Katrai ģimenei var izveidot savu saiti. Tajā mamma, tētis vai cits aizbildnis atzīmē dalību savā lomā. Dalības uzskaite neparāda, ko konkrētais cilvēks ir atbildējis.</p>
        <p style="margin-top:.75rem">Lai mazinātu atpazīšanas risku, detalizēti rezultāti un eksports ir pieejami no 5 atbildēm.</p>
    </x-filament::section>
</x-filament-panels::page>
