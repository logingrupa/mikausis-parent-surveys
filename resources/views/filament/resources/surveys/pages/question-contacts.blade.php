<x-filament-panels::page>
    <style>
        .mikausis-contacts { display: grid; gap: 1.5rem; color: #292638; overflow-wrap: anywhere; }
        .mikausis-contacts h2, .mikausis-contacts h3 { color: #3c3876; font-weight: 700; line-height: 1.5; }
        .mikausis-contacts h2 { font-size: 1.25rem; }
        .mikausis-contacts h3 { font-size: 1.1rem; }
        .mikausis-contacts h4 { color: #3c3876; font-weight: 600; line-height: 1.5; }
        .mikausis-contacts p { line-height: 1.7; }
        .mikausis-contact-section { padding: clamp(1rem, 3vw, 2rem); border: 1px solid #e5e3ee; border-radius: 1rem; background: #fff; }
        .mikausis-contact-section > * + * { margin-top: .75rem; }
        .mikausis-contact-note { color: #625d72; font-size: .875rem; }
        .mikausis-contact-list { display: grid; gap: 1rem; padding: 0; list-style: none; }
        .mikausis-contact-entry { padding: 1.25rem; border: 1px solid #e5e3ee; border-radius: .75rem; background: #faf9fc; }
        .mikausis-contact-answer { font-weight: 600; }
        .mikausis-contact-details { display: flex; flex-wrap: wrap; gap: 1rem 3rem; margin-block: 1rem; }
        .mikausis-contact-details dt { color: #625d72; font-size: .875rem; }
        .mikausis-contact-details dd { white-space: pre-wrap; }
        .mikausis-contact-entry > * + * { margin-top: .75rem; }
    </style>

    <div class="mikausis-contacts">
        <section class="mikausis-contact-section" aria-labelledby="contacts-survey-title">
            <h2 id="contacts-survey-title">{{ $surveyTitle }}</h2>
            <p>Šeit redzami tikai atstātie kontakti un aktivitātes, par kurām sazināties. Pārējās aptaujas atbildes paliek anonīmas.</p>
            <p class="mikausis-contact-note">Kontakti ir pieejami arī tad, ja iesniegtas mazāk nekā piecas anketas. Šis saraksts ir atsevišķs no anonīmo rezultātu pārskatiem. Dzēsiet kontaktinformāciju, kad tā vairs nav nepieciešama norādītajam mērķim.</p>
        </section>

        @forelse ($contacts as $contact)
            <section class="mikausis-contact-section" data-contact-card aria-labelledby="contacts-entry-{{ $loop->iteration }}">
                @if ($contact['label'])
                    <p class="mikausis-contact-note">{{ $contact['label'] }}</p>
                @endif
                <h3 id="contacts-entry-{{ $loop->iteration }}">{{ $contact['title'] }}</h3>
                @if ($contact['fields'])
                    <dl class="mikausis-contact-details">
                        @foreach ($contact['fields'] as $field)
                            <div><dt>{{ $field['label'] }}</dt><dd>{{ $field['value'] }}</dd></div>
                        @endforeach
                    </dl>
                @endif
                <ul class="mikausis-contact-list">
                    @foreach ($contact['activities'] as $activity)
                        <li class="mikausis-contact-entry" data-contact-activity>
                            <h4>{{ $activity['title'] }}</h4>
                            @if ($activity['archived'])
                                <p class="mikausis-contact-note">Iepriekšējā jautājuma versija · saglabātie kontakti</p>
                            @endif
                            <p class="mikausis-contact-note">{{ $activity['purpose'] }}</p>
                            <p class="mikausis-contact-note">Izvēlētā atbilde</p>
                            <p class="mikausis-contact-answer">{{ implode('; ', $activity['answers']) }}</p>
                            {{ ($this->deleteContactAction)(['contact' => $activity['id']]) }}
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <section class="mikausis-contact-section" role="status">
                <h3>{{ $hasContactQuestions ? 'Brīvprātīgie kontakti: 0' : 'Šajā aptaujā nav jautājumu ar kontaktinformācijas bloku' }}</h3>
            </section>
        @endforelse
    </div>
</x-filament-panels::page>
