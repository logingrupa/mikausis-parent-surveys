@php
    $contactConfig = $question['contact'];
    $contactPrefix = 'contact-'.$question['key'];
@endphp
<div class="question-contact" data-question-contact data-contact-id="{{ (string) \Illuminate\Support\Str::uuid() }}" data-contact-prompt="{{ $contactConfig['prompt'] }}" data-contact-purpose="{{ $contactConfig['purpose'] }}" data-triggers="{{ json_encode($contactConfig['trigger_values']) }}" data-endpoint="{{ route('question-contacts.store', ['slug' => $survey->slug, 'question' => $question['key']]) }}" hidden>
    <label class="anonymous-choice" for="{{ $contactPrefix }}-consent">
        <input type="checkbox" id="{{ $contactPrefix }}-consent" data-contact-consent aria-describedby="{{ $contactPrefix }}-purpose" disabled>
        <span>{{ $contactConfig['prompt'] }}</span>
    </label>
    <p class="field-help" id="{{ $contactPrefix }}-purpose">{{ $contactConfig['purpose'] }}</p>
    <div class="respondent-details" data-contact-details hidden>
        <p class="respondent-disclosure">Šī kontaktinformācija būs redzama iestādei tikai kopā ar izvēli šajā jautājumā. Pārējām aptaujas atbildēm to nepievienojam.</p>
        @foreach ($contactConfig['fields'] as $field)
            <div class="respondent-field">
                <label for="{{ $contactPrefix }}-{{ $field['key'] }}">{{ $field['label'] }}@if ($field['required'])<span class="required-label"> Obligāti, ja atstājat kontaktinformāciju</span>@endif</label>
                @if ($field['type'] === 'role')
                    <select id="{{ $contactPrefix }}-{{ $field['key'] }}" data-contact-field="{{ $field['key'] }}" data-contact-type="role" data-contact-label="{{ $field['label'] }}" data-required="{{ $field['required'] ? 'true' : 'false' }}" disabled>
                        <option value="">Izvēlieties lomu</option>
                        @foreach (['mother' => 'Mamma', 'father' => 'Tētis', 'guardian' => 'Cits aizbildnis'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                @else
                    <input id="{{ $contactPrefix }}-{{ $field['key'] }}" type="{{ $field['type'] }}" maxlength="{{ match ($field['type']) { 'email' => 254, 'tel' => 40, default => 160 } }}" data-contact-field="{{ $field['key'] }}" data-contact-type="{{ $field['type'] }}" data-contact-label="{{ $field['label'] }}" data-required="{{ $field['required'] ? 'true' : 'false' }}" disabled>
                @endif
            </div>
        @endforeach
    </div>
    <p class="field-help" data-contact-status role="status" hidden></p>
</div>
