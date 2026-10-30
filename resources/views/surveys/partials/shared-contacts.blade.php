<section class="shared-contacts" data-shared-contacts hidden aria-labelledby="shared-contacts-title">
    @unless ($followup ?? false)<h3 id="shared-contacts-title" tabindex="-1">Kontaktinformācija aktivitātēm</h3>@endunless
    <p class="field-help" id="shared-contacts-help">@if ($followup ?? false)Kontaktinformācija ir saistīta tikai ar šo aktivitāšu izvēlēm; pārējās aptaujas atbildes paliek anonīmas.@else Pēc noklusējuma atbildat anonīmi. Kontaktus varat atstāt pēc izvēles. Tie būs saistīti tikai ar zemāk norādīto aktivitāšu izvēlēm; pārējās aptaujas atbildes paliek anonīmas.@endif</p>
    <label class="anonymous-choice" for="shared-contact-consent">
        <input id="shared-contact-consent" type="checkbox" data-shared-contact-consent aria-describedby="shared-contacts-help" aria-controls="shared-contact-details" disabled>
        <span id="shared-contact-prompt">Atstāt kontaktinformāciju</span>
    </label>
    <div id="shared-contact-details" data-shared-contact-details hidden>
        <p class="field-help" id="shared-contacts-purpose" data-shared-contact-purpose hidden></p>
        <p class="field-help" id="shared-contact-activities-label" @if ($followup ?? false) hidden @endif>Kontaktinformācija paredzēta šīm aktivitātēm:</p>
        <ul class="shared-contact-activities" data-shared-contact-activities aria-labelledby="shared-contact-activities-label" @if ($followup ?? false) hidden @endif></ul>
        <div class="respondent-details" data-shared-contact-fields></div>
        <p class="field-help" data-shared-contact-status role="status" hidden></p>
    </div>
</section>
