<x-filament-panels::page>
    <style>
        .mikausis-contacts { display: grid; gap: 1.5rem; color: #292638; overflow-wrap: anywhere; }
        .mikausis-contacts h2, .mikausis-contacts h3 { color: #3c3876; font-weight: 700; line-height: 1.5; }
        .mikausis-contacts h2 { font-size: 1.25rem; }
        .mikausis-contacts h3 { font-size: 1.1rem; }
        .mikausis-contacts p { line-height: 1.7; }
        .mikausis-contact-section { padding: clamp(1rem, 3vw, 2rem); border: 1px solid #e5e3ee; border-radius: 1rem; background: #fff; }
        .mikausis-contact-section > * + * { margin-top: .75rem; }
        .mikausis-contact-note { color: #625d72; font-size: .875rem; }
        .mikausis-contact-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 20rem), 1fr)); gap: 1rem; padding: 0; list-style: none; }
        .mikausis-contact-entry { padding: 1.25rem; border: 1px solid #e5e3ee; border-radius: .75rem; background: #faf9fc; }
        .mikausis-contact-entry dl { display: grid; gap: .3rem; margin-block: 1rem; }
        .mikausis-contact-entry dt { color: #625d72; font-size: .875rem; }
        .mikausis-contact-entry dd { margin-bottom: .5rem; white-space: pre-wrap; }
        .mikausis-contact-answer { font-weight: 600; }
    </style>

    <div class="mikausis-contacts">
        <section class="mikausis-contact-section" aria-labelledby="contacts-survey-title">
            <h2 id="contacts-survey-title">{{ $surveyTitle }}</h2>
            <p>Atsevišķi, brīvprātīgi atstāta kontaktinformācija konkrētu aktivitāšu organizēšanai. Katrs kontakts ir saistīts tikai ar zemāk norādīto jautājumu un tajā izvēlēto atbildi. Citas šī cilvēka aptaujas atbildes šeit nav pieejamas.</p>
            <p class="mikausis-contact-note">Kontakti ir pieejami arī tad, ja iesniegtas mazāk nekā piecas anketas. Šis saraksts ir atsevišķs no anonīmo rezultātu pārskatiem. Dzēsiet kontaktinformāciju, kad tā vairs nav nepieciešama norādītajam mērķim.</p>
        </section>

        @forelse ($groups as $group)
            <section class="mikausis-contact-section" aria-labelledby="contacts-question-{{ $loop->iteration }}">
                <h3 id="contacts-question-{{ $loop->iteration }}">{{ $group['title'] }}</h3>
                @if ($group['archived'] ?? false)
                    <p class="mikausis-contact-note">Iepriekšējā jautājuma versija · saglabātie kontakti</p>
                @endif
                <p class="mikausis-contact-note">{{ $group['purpose'] }}</p>

                @if ($group['entries'] === [])
                    <p role="status">Šim jautājumam vēl nav brīvprātīgi atstātu kontaktu.</p>
                @else
                    <ul class="mikausis-contact-list">
                        @foreach ($group['entries'] as $entry)
                            <li class="mikausis-contact-entry">
                                <p class="mikausis-contact-note">Izvēlētā atbilde</p>
                                <p class="mikausis-contact-answer">{{ implode('; ', $entry['answers']) }}</p>
                                <dl>
                                    @foreach ($entry['fields'] as $field)
                                        <div>
                                            <dt>{{ $field['label'] }}</dt>
                                            <dd>{{ $field['value'] }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                                {{ ($this->deleteContactAction)(['contact' => $entry['id']]) }}
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @empty
            <section class="mikausis-contact-section" role="status">
                <h3>Šajā aptaujā nav jautājumu ar kontaktinformācijas bloku</h3>
                <p>Brīvprātīgi atstātie kontakti šeit tiek rādīti atsevišķi pa jautājumiem.</p>
            </section>
        @endforelse
    </div>
</x-filament-panels::page>
