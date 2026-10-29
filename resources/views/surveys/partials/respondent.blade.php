<fieldset class="respondent-identity" id="respondent-identity" aria-describedby="respondent-help error-respondent" tabindex="-1">
    <legend>Kā vēlaties atbildēt?</legend>
    <input type="hidden" name="anonymous" value="0">
    <label class="anonymous-choice" for="respondent-anonymous">
        <input type="checkbox" name="anonymous" id="respondent-anonymous" value="1" @checked($isAnonymous) aria-describedby="respondent-help error-anonymous" @if ($errors->has('anonymous')) aria-invalid="true" @endif>
        <span>Anonīmi</span>
    </label>
    <p class="field-help" id="respondent-help">Atstājiet atzīmi, lai atbildētu bez saviem datiem. Ja vēlaties tos norādīt, noņemiet atzīmi.</p>
    <p class="field-error" data-field-error id="error-anonymous" @if (! $errors->has('anonymous')) hidden @endif>{{ $errors->first('anonymous') }}</p>
    <div class="respondent-details" data-respondent-details>
        <p class="respondent-disclosure">Jūsu norādītie dati būs redzami administrācijai kopā ar jūsu atbildēm, arī atskaites izdrukā un HTML eksportā.</p>
        @foreach ($respondentFields as $field)
            @php
                $fieldKey = $field['key'];
                $fieldValue = $oldRespondent[$fieldKey] ?? ($fieldKey === 'name' ? ($oldValues['respondent_name'] ?? '') : ($fieldKey === 'role' ? ($oldValues['respondent_role'] ?? '') : ''));
                $fieldValue = is_string($fieldValue) ? $fieldValue : '';
                $fieldError = 'respondent.'.$fieldKey;
            @endphp
            <div class="respondent-field" data-respondent-field="{{ $fieldKey }}">
                @if ($field['type'] === 'role' && $invitation)
                    <span class="respondent-field-label">{{ $field['label'] }}</span>
                    <p class="respondent-family-role" id="respondent-{{ $fieldKey }}" tabindex="-1" data-respondent-family-role data-respondent-key="{{ $fieldKey }}" data-respondent-label="{{ $field['label'] }}">{{ ['mother' => 'Mamma', 'father' => 'Tētis', 'guardian' => 'Cits aizbildnis'][$oldFamilyRole] ?? 'Izmantojam iepriekš izvēlēto ģimenes lomu.' }}</p>
                @else
                    <label for="respondent-{{ $fieldKey }}">{{ $field['label'] }}@if ($field['required'])<span class="required-label"> Obligāti, ja norādāt savus datus</span>@endif</label>
                    @if ($field['type'] === 'role')
                        <select id="respondent-{{ $fieldKey }}" name="respondent[{{ $fieldKey }}]" data-respondent-input data-respondent-label="{{ $field['label'] }}" data-required="{{ $field['required'] ? 'true' : 'false' }}" aria-describedby="error-respondent-{{ $fieldKey }} respondent-help" @required(! $isAnonymous && $field['required']) @if ($errors->has($fieldError)) aria-invalid="true" @endif>
                            <option value="">Izvēlieties lomu</option>
                            @foreach (['mother' => 'Mamma', 'father' => 'Tētis', 'guardian' => 'Cits aizbildnis'] as $value => $label)
                                <option value="{{ $value }}" @selected($fieldValue === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    @else
                        <input id="respondent-{{ $fieldKey }}" name="respondent[{{ $fieldKey }}]" type="{{ $field['type'] }}" value="{{ ! $isAnonymous ? $fieldValue : '' }}" maxlength="{{ match ($field['type']) { 'email' => 254, 'tel' => 40, default => 160 } }}" data-respondent-input data-respondent-label="{{ $field['label'] }}" data-required="{{ $field['required'] ? 'true' : 'false' }}" aria-describedby="error-respondent-{{ $fieldKey }} respondent-help" @required(! $isAnonymous && $field['required']) @if ($errors->has($fieldError)) aria-invalid="true" @endif>
                    @endif
                @endif
                <p class="field-error" data-field-error id="error-respondent-{{ $fieldKey }}" @if (! $errors->has($fieldError)) hidden @endif>{{ $errors->first($fieldError) }}</p>
            </div>
        @endforeach
    </div>
    <p class="field-error" data-field-error id="error-respondent" @if (! $errors->has('respondent')) hidden @endif>{{ $errors->first('respondent') }}</p>
</fieldset>
