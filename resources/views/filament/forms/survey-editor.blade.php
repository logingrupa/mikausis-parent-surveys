<div class="survey-editor-field">
    @if ($errors->has($getStatePath().'*'))
        <div class="se-errors" role="alert">
            <strong>Pārbaudiet atzīmētos laukus.</strong>
            <ul>
                @foreach ($errors->getMessages() as $errorPath => $messages)
                    @if (str_starts_with($errorPath, $getStatePath()))
                        @foreach ($messages as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    @endif
                @endforeach
            </ul>
        </div>
    @endif
    <div
        wire:ignore
        x-load
        x-load-src="{{ asset('js/survey-editor.js') }}?v={{ filemtime(public_path('js/survey-editor.js')) }}"
        x-data="surveyEditor({ state: $wire.$entangle('{{ $getStatePath() }}'), disabled: @js($isDisabled()), saveAction: @js($getLivewire() instanceof \App\Filament\Resources\Surveys\Pages\CreateSurvey ? 'create' : 'save'), existing: @js((bool) $getRecord()?->exists) })"
        x-on:survey-editor-errors.window="receiveErrors($event.detail.errors)"
        x-on:survey-editor-saved.window="saved()"
        class="survey-editor"
        :inert="saving"
        :aria-busy="saving"
    >
        <div class="se-toolbar">
            <div class="se-tabs" aria-label="Aptaujas redaktora skats">
                <button type="button" :aria-pressed="!preview" @click="togglePreview(false)" x-show="!disabled">Rediģēt</button>
                <button type="button" :aria-pressed="preview" @click="togglePreview(true)">Vecāku skats</button>
            </div>
            <span class="se-save-state" aria-live="polite" x-text="disabled ? 'Tikai apskatei' : dirty ? 'Izmaiņas vēl nav saglabātas' : existing ? 'Izmaiņas saglabātas' : 'Saite tiks izveidota automātiski'"></span>
            <button type="button" class="se-save-button" x-show="!disabled" @click="save()" :disabled="saving" x-text="saving ? 'Saglabājam…' : 'Saglabāt'"></button>
        </div>
        @if ($getRecord()?->published_at)
            <p class="se-frozen">Izmaiņas būs redzamas vecāku saitē pēc saglabāšanas. Iepriekšējās atbildes tiks saglabātas.</p>
        @endif
        <p class="se-frozen" x-show="disabled">Šī aptauja ir pieejama tikai apskatei.</p>
        <p class="se-notice" role="status" x-show="notice" x-text="notice" x-cloak></p>

        <div class="se-intro">
            <template x-if="!preview && !disabled">
                <div>
                    <label class="se-sr-only" for="editor-survey-title">Aptaujas nosaukums</label>
                    <textarea id="editor-survey-title" data-survey-title class="se-survey-title se-inline" rows="1" maxlength="255" placeholder="Aptaujas nosaukums" x-model="state.title" @input="resize($el)" :aria-invalid="Boolean(errors['data.builder.title'])" aria-describedby="editor-title-error"></textarea>
                    <p id="editor-title-error" class="se-error" x-show="errors['data.builder.title']" x-text="(errors['data.builder.title'] || []).join(' ')"></p>
                    <label class="se-sr-only" for="editor-survey-description">Ievads vecākiem</label>
                    <textarea id="editor-survey-description" class="se-description se-inline" rows="2" maxlength="10000" placeholder="Īss ievads vecākiem (nav obligāts)" x-model="state.description" @input="resize($el)"></textarea>
                </div>
            </template>
            <template x-if="preview || disabled">
                <div><h2 class="se-preview-title" x-text="state.title || 'Aptaujas nosaukums'"></h2><p class="se-preview-description" x-show="state.description" x-text="state.description"></p></div>
            </template>
        </div>

        <template x-if="(!preview && allowsIdentifiedResponses) || disabled">
            <section class="se-identification" aria-labelledby="editor-identification-heading">
                <h3 id="editor-identification-heading">Respondenta dati</h3>
                <template x-if="!disabled">
                    <div>
                        <template x-if="allowsIdentifiedResponses">
                            <div>
                                <p id="editor-identification-help" class="se-helper">Izvēlieties, ko jautāt vecākiem. Lauku nosaukumus var mainīt un atzīmēt obligātos laukus. Vecāku skatā “Anonīmi” sākumā ir atzīmēts. Noņemot atzīmi, var aizpildīt šos laukus. Norādītie dati būs redzami administrācijas atskaitēs līdzās atbildēm.</p>
                                <div class="se-respondent-fields">
                                    <template x-for="(field, fieldIndex) in respondentFields" :key="field.key">
                                        <div class="se-respondent-field">
                                            <div class="se-respondent-field-top">
                                                <div class="se-identity-field">
                                                    <label :for="'editor-respondent-label-' + field.key">Lauka nosaukums</label>
                                                    <input data-respondent-label :id="'editor-respondent-label-' + field.key" type="text" :value="field.label" @input="updateRespondentField(field.key, 'label', $event.target.value)" maxlength="160" :aria-describedby="'editor-respondent-type-' + field.key + ' editor-respondent-error-' + field.key" :aria-invalid="Boolean(respondentFieldError(fieldIndex))">
                                                </div>
                                                <button type="button" class="se-icon-button se-remove" @click="removeRespondentField(field.key)" :aria-label="'Noņemt lauku: ' + (field.label || 'Lauks bez nosaukuma')" title="Noņemt lauku">×</button>
                                            </div>
                                            <p class="se-helper" :id="'editor-respondent-type-' + field.key" x-text="respondentFieldType(field)"></p>
                                            <label class="se-checkbox-row"><input type="checkbox" :checked="field.required" @change="updateRespondentField(field.key, 'required', $event.target.checked)" :aria-label="'Obligāts, ja neatbild anonīmi: ' + (field.label || 'Lauks bez nosaukuma')"><span>Obligāts, ja neatbild anonīmi</span></label>
                                            <p class="se-error" :id="'editor-respondent-error-' + field.key" x-show="respondentFieldError(fieldIndex)" x-text="respondentFieldError(fieldIndex)"></p>
                                        </div>
                                    </template>
                                </div>
                                <p id="editor-respondent-limit" class="se-helper">Pievienojiet līdz 12 laukiem.</p>
                                <div class="se-respondent-add-fields" role="group" aria-label="Pievienot respondenta datu lauku" aria-describedby="editor-respondent-limit">
                                    <button type="button" class="se-text-button" @click="addRespondentField('name')" :disabled="respondentFields.length >= 12 || hasRespondentField('name')">+ Vārds un uzvārds</button>
                                    <button type="button" class="se-text-button" @click="addRespondentField('role')" :disabled="respondentFields.length >= 12 || hasRespondentField('role')">+ Loma</button>
                                    <button type="button" class="se-text-button" @click="addRespondentField('email')" :disabled="respondentFields.length >= 12 || hasRespondentField('email')">+ E-pasts</button>
                                    <button type="button" class="se-text-button" @click="addRespondentField('phone')" :disabled="respondentFields.length >= 12 || hasRespondentField('phone')">+ Tālrunis</button>
                                    <button type="button" class="se-text-button" @click="addRespondentField('custom')" :disabled="respondentFields.length >= 12">+ Cits teksta lauks</button>
                                </div>
                                <button type="button" class="se-text-button se-respondent-remove-block" @click="pendingDelete = 'respondent-data'">Noņemt respondenta datu bloku</button>
                                <div class="se-confirm" x-show="pendingDelete === 'respondent-data'" x-cloak>
                                    <p>Noņemt šo bloku? Visas atbildes būs anonīmas un vecākiem šos laukus vairs nerādīs.</p>
                                    <button type="button" @click="removeRespondentBlock()">Noņemt bloku</button>
                                    <button type="button" @click="pendingDelete = null">Atcelt</button>
                                </div>
                            </div>
                        </template>
                        <p class="se-error" x-show="errors['data.builder.allow_identified_responses']" x-text="(errors['data.builder.allow_identified_responses'] || []).join(' ')"></p>
                        <p class="se-error" x-show="errors['data.builder.respondent_fields']" x-text="(errors['data.builder.respondent_fields'] || []).join(' ')"></p>
                    </div>
                </template>
                <template x-if="disabled">
                    <div>
                        <p class="se-helper" x-text="allowsIdentifiedResponses ? 'Vecāki var izvēlēties norādīt zemāk redzamos datus. Sākumā ir atzīmēts “Anonīmi”. Norādītie dati ir redzami administrācijas atskaitēs līdzās atbildēm.' : hasQuestionContacts ? 'Respondenta datu bloks nav pievienots. Kontaktinformāciju var atstāt tikai pie atsevišķiem jautājumiem; tā netiek piesaistīta citām atbildēm.' : 'Visas atbildes ir anonīmas. Respondenta datu bloks nav pievienots.'"></p>
                        <ul class="se-respondent-summary" x-show="allowsIdentifiedResponses"><template x-for="field in respondentFields" :key="field.key"><li><span x-text="field.label"></span><span class="se-helper" x-text="field.required ? ' · obligāts, ja neatbild anonīmi' : ' · nav obligāts'"></span></li></template></ul>
                    </div>
                </template>
            </section>
        </template>

        <template x-if="(preview || disabled) && allowsIdentifiedResponses">
            <section class="se-identification" aria-labelledby="preview-identification-heading">
                <h3 id="preview-identification-heading">Respondenta dati</h3>
                <label class="se-checkbox-row se-identification-toggle" for="preview-anonymous">
                    <input id="preview-anonymous" type="checkbox" :checked="previewRespondent.anonymous" @change="setPreviewAnonymous($event.target.checked)" aria-describedby="preview-identification-help" aria-controls="preview-identity-fields" :aria-expanded="previewIdentified">
                    <span>Anonīmi</span>
                </label>
                <p id="preview-identification-help" class="se-helper">Ja vēlaties norādīt savus datus, noņemiet atzīmi “Anonīmi”. Norādītie dati būs redzami bērnudārza administrācijai līdzās jūsu atbildēm.</p>
                <div id="preview-identity-fields">
                    <template x-if="previewIdentified">
                        <div class="se-identity-fields">
                            <template x-for="field in respondentFields" :key="field.key">
                                <div class="se-identity-field">
                                    <label :for="'preview-respondent-' + field.key"><span x-text="field.label"></span><span class="se-required-star" x-show="field.required" aria-hidden="true"> *</span><span class="se-sr-only" x-show="field.required"> (Obligāts)</span></label>
                                    <template x-if="contactFieldType(field) !== 'role'"><input :id="'preview-respondent-' + field.key" :type="['email', 'tel'].includes(contactFieldType(field)) ? contactFieldType(field) : 'text'" x-model="previewRespondent.values[field.key]" :maxlength="contactFieldType(field) === 'email' ? 254 : contactFieldType(field) === 'tel' ? 40 : 160" autocomplete="off" form="survey-editor-preview-only" @keydown.enter.prevent :aria-required="field.required" aria-describedby="preview-identification-help"></template>
                                    <template x-if="contactFieldType(field) === 'role'">
                                        <select :id="'preview-respondent-' + field.key" x-model="previewRespondent.values[field.key]" form="survey-editor-preview-only" :aria-required="field.required" aria-describedby="preview-identification-help">
                                            <option value="">Izvēlieties lomu</option>
                                            <option value="mother">Mamma</option>
                                            <option value="father">Tētis</option>
                                            <option value="guardian">Cits aizbildnis</option>
                                        </select>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </section>
        </template>

        <template x-for="(section, sectionIndex) in state.sections" :key="section.key">
            <div class="se-chapter">
                <div class="se-section-heading" x-show="state.sections.length > 1 || section.title !== 'Jautājumi' || section.description">
                    <span class="se-kicker" x-text="'Sadaļa ' + (sectionIndex + 1) + ' no ' + state.sections.length"></span>
                    <template x-if="!preview && !disabled">
                        <div>
                            <label class="se-sr-only" :for="'editor-section-' + section.key">Sadaļas nosaukums</label>
                            <input class="se-section-title se-inline" :id="'editor-section-' + section.key" x-model="section.title" maxlength="255" placeholder="Sadaļas nosaukums">
                            <label class="se-sr-only" :for="'editor-section-description-' + section.key">Sadaļas paskaidrojums</label>
                            <textarea class="se-description se-inline" :id="'editor-section-description-' + section.key" x-model="section.description" @input="resize($el)" rows="1" maxlength="5000" placeholder="Sadaļas paskaidrojums (nav obligāts)"></textarea>
                            <button type="button" class="se-text-button se-muted" x-show="state.sections.length > 1" @click="pendingDelete = section.key">Noņemt sadaļu</button>
                            <div class="se-confirm" x-show="pendingDelete === section.key" x-cloak><p>Jautājumi paliks aptaujā un tiks pārvietoti uz citu sadaļu.</p><button type="button" @click="removeSection(section)">Noņemt sadaļu</button><button type="button" @click="pendingDelete = null">Atcelt</button></div>
                        </div>
                    </template>
                    <template x-if="preview || disabled"><div><h3 x-text="section.title"></h3><p x-show="section.description" x-text="section.description"></p></div></template>
                </div>

                <template x-for="question in sectionQuestions(section.key)" :key="question.key">
                    <article class="se-question" :class="{'is-active':active === question.key && !preview, 'has-error':fieldError(question, '') || ruleIssue(question) || contactIssue(question)}" :id="'editor-question-' + question.key" @focusin="active = question.key" @dragover.prevent @drop.prevent="drop(question)" x-show="!preview || previewVisible(question)">
                        <div x-show="!preview && !disabled">
                            <div class="se-question-top">
                                <span class="se-question-number" x-text="number(question) + '. jautājums'"></span>
                                <label class="se-type-label"><span class="se-sr-only">Atbildes veids</span><select :value="question.type" @change="changeType(question, $event.target.value)"><option value="single">Viena izvēle</option><option value="multiple">Vairākas izvēles</option><option value="text">Brīvs teksts</option></select></label>
                            </div>
                            <label class="se-sr-only" :for="'editor-title-' + question.key" x-text="number(question) + '. jautājuma teksts'"></label>
                            <textarea :id="'editor-title-' + question.key" data-question-title class="se-question-title se-inline" rows="1" x-model="question.title" @input="resize($el)" maxlength="2000" placeholder="Rakstiet jautājumu…" :aria-invalid="Boolean(fieldError(question, 'title'))" :aria-describedby="'editor-error-' + question.key"></textarea>
                            <p class="se-error" :id="'editor-error-' + question.key" x-show="fieldError(question, 'title')" x-text="fieldError(question, 'title')"></p>
                            <textarea class="se-description se-inline" rows="1" x-show="question.description || more[question.key]" :aria-label="number(question) + '. jautājuma paskaidrojums'" x-model="question.description" @input="resize($el)" maxlength="5000" placeholder="Paskaidrojums (nav obligāts)"></textarea>

                            <div class="se-options" x-show="question.type !== 'text'">
                                <template x-for="(option, optionIndex) in options(question)" :key="option.value">
                                    <div class="se-option-row">
                                        <span class="se-option-marker" :class="{'is-checkbox':question.type === 'multiple'}" aria-hidden="true"></span>
                                        <label class="se-sr-only" :for="'editor-option-' + question.key + '-' + option.value" x-text="(optionIndex + 1) + '. atbildes variants'"></label>
                                        <input class="se-option-input se-inline" :id="'editor-option-' + question.key + '-' + option.value" x-model="option.label" @keydown.enter.prevent="addOption(question, option.value)" maxlength="2000" :placeholder="'Atbildes variants ' + (optionIndex + 1)" :aria-invalid="Boolean(fieldError(question, 'options'))">
                                        <button type="button" class="se-icon-button se-remove" @click="removeOption(question, option)" :aria-label="'Noņemt atbildi: ' + (option.label || (optionIndex + 1) + '. variants')" title="Noņemt atbildi">×</button>
                                    </div>
                                </template>
                                <div class="se-option-row se-other" x-show="question.allow_other"><span class="se-option-marker" :class="{'is-checkbox':question.type === 'multiple'}" aria-hidden="true"></span><span>Cits variants: <span class="se-answer-line"></span></span><button type="button" class="se-icon-button se-remove" @click="toggleOther(question, false)" aria-label="Noņemt variantu Cits">×</button></div>
                                <div class="se-add-options"><button type="button" class="se-text-button" @click="addOption(question)">+ Pievienot atbildi</button><span x-show="!question.allow_other">vai</span><button type="button" class="se-text-button" x-show="!question.allow_other" @click="toggleOther(question, true)">pievienot “Cits”</button></div>
                                <p class="se-error" x-show="fieldError(question, 'options')" x-text="fieldError(question, 'options')"></p>
                            </div>
                            <div class="se-text-answer" x-show="question.type === 'text'">Vecāki šeit ierakstīs savu atbildi.</div>

                            <div class="se-question-footer">
                                <label class="se-required"><input type="checkbox" x-model="question.required"> Obligāts jautājums</label>
                                <div class="se-question-tools">
                                    <button type="button" class="se-icon-button" draggable="true" @dragstart="dragging = question.key; $event.dataTransfer.setData('text/plain', question.key)" @dragend="dragging = null" @click="move(question, -1)" :disabled="sectionQuestions(section.key)[0]?.key === question.key" aria-label="Pārvietot jautājumu augstāk" title="Augstāk (vai pārvelciet)">↑</button>
                                    <button type="button" class="se-icon-button" @click="move(question, 1)" :disabled="sectionQuestions(section.key).at(-1)?.key === question.key" aria-label="Pārvietot jautājumu zemāk" title="Zemāk">↓</button>
                                    <button type="button" class="se-text-button" @click="duplicate(question)" :disabled="state.questions.length >= 100">Kopēt</button>
                                    <button type="button" class="se-text-button" @click="more[question.key] = !more[question.key]; resizeAll()" :aria-expanded="Boolean(more[question.key])">Vairāk <span aria-hidden="true">⌄</span></button>
                                    <button type="button" class="se-icon-button se-remove" @click="pendingDelete = question.key" aria-label="Dzēst jautājumu" title="Dzēst jautājumu">×</button>
                                </div>
                            </div>

                            <div class="se-more" x-show="more[question.key]" x-cloak>
                                <label class="se-setting" x-show="state.sections.length > 1"><span>Sadaļa</span><select x-model="question.section" @change="orderBySections()"><template x-for="target in state.sections" :key="target.key"><option :value="target.key" :selected="target.key === question.section" x-text="target.title"></option></template></select></label>
                                <div class="se-setting"><label :for="'editor-condition-' + question.key">Kad rādīt šo jautājumu?</label><select :id="'editor-condition-' + question.key" :value="question.condition?.question || ''" @change="setSource(question, $event.target.value)"><option value="">Vienmēr</option><template x-for="sourceQuestion in sources(question)" :key="sourceQuestion.key"><option :value="sourceQuestion.key" :selected="sourceQuestion.key === question.condition?.question" x-text="'Ja atbilde uz: ' + (sourceQuestion.title || number(sourceQuestion) + '. jautājums')"></option></template></select></div>
                                <div class="se-condition-values" x-show="question.condition?.question"><p>Rādīt, ja izvēlēta vismaz viena no šīm atbildēm:</p><template x-for="sourceOption in (source(question)?.options || [])" :key="sourceOption.value"><label class="se-checkbox-row"><input type="checkbox" :checked="question.condition?.values?.includes(sourceOption.value)" @change="toggleConditionValue(question, sourceOption.value, $event.target.checked)"><span x-text="sourceOption.value === 'other' ? 'Cits variants' : sourceOption.label"></span></label></template></div>
                                <div class="se-setting" x-show="question.type === 'multiple'"><p>Atbildes, kuras nevar izvēlēties kopā ar citām</p><p class="se-helper">Piemēram, atbilde “Nepiedalīšos”.</p><template x-for="exclusiveOption in question.options" :key="exclusiveOption.value"><label class="se-checkbox-row"><input type="checkbox" :checked="question.exclusive_values?.includes(exclusiveOption.value)" @change="toggleExclusive(question, exclusiveOption.value, $event.target.checked)"><span x-text="exclusiveOption.value === 'other' ? 'Cits variants' : exclusiveOption.label || 'Atbilde bez teksta'"></span></label></template></div>
                            </div>
                            <p class="se-error" x-show="ruleIssue(question)" x-text="ruleIssue(question)"></p>
                            <p class="se-error" x-show="fieldError(question, 'condition')" x-text="fieldError(question, 'condition')"></p>
                            <div class="se-confirm" x-show="pendingDelete === question.key" x-cloak><p>Dzēst šo jautājumu un tā atbilžu variantus?</p><button type="button" @click="removeQuestion(question)">Dzēst jautājumu</button><button type="button" @click="pendingDelete = null">Atcelt</button></div>
                        </div>

                        <template x-if="preview || disabled">
                            <div class="se-parent-question">
                                <h4><span x-text="question.title || 'Jautājums bez teksta'"></span><span class="se-required-star" x-show="question.required" aria-label="Obligāts"> *</span></h4>
                                <p class="se-preview-description" x-show="question.description" x-text="question.description"></p>
                                <template x-if="question.type === 'text'"><textarea rows="3" class="se-preview-text" placeholder="Jūsu domas…" :aria-label="question.title || 'Jūsu atbilde'"></textarea></template>
                                <template x-if="question.type !== 'text'"><div class="se-parent-options"><p class="se-helper" x-show="question.type === 'multiple'">Varat izvēlēties vairākus variantus.</p><template x-for="option in displayOptions(question)" :key="option.value"><label class="se-parent-choice"><input :type="question.type === 'multiple' ? 'checkbox' : 'radio'" :name="'preview-' + question.key" :checked="(previewAnswers[question.key] || []).includes(option.value)" @change="answerPreview(question, option.value, $event.target.checked)"><span x-text="option.value === 'other' ? 'Cits variants' : option.label || 'Atbildes variants'"></span></label></template><textarea class="se-preview-text" rows="2" x-show="question.allow_other && (previewAnswers[question.key] || []).includes('other')" placeholder="Jūsu variants" aria-label="Jūsu variants"></textarea></div></template>
                            </div>
                        </template>
                    </article>
                </template>

                <div class="se-section-add" x-show="!preview && !disabled"><button type="button" class="se-add-question" @click="addQuestion(section.key)" :disabled="state.questions.length >= 100"><span aria-hidden="true">+</span> Pievienot jautājumu</button></div>
            </div>
        </template>

        <div class="se-add-section" x-show="!preview && !disabled">
            <button type="button" class="se-text-button" @click="addSection()" :disabled="state.sections.length >= 40">+ Pievienot sadaļu</button>
            <button type="button" data-add-respondent class="se-text-button" x-show="!allowsIdentifiedResponses" @click="addRespondentBlock()" :disabled="hasQuestionContacts" aria-describedby="editor-add-respondent-help">+ Respondenta dati</button>
            <span>Sadaļas palīdz sadalīt garāku aptauju.</span>
            <span id="editor-add-respondent-help" class="se-helper" x-show="!allowsIdentifiedResponses" x-text="hasQuestionContacts ? 'Lai pievienotu respondenta datu bloku, vispirms noņemiet visas jautājumu atzīmes kontaktinformācijas sadaļā.' : 'Bez respondenta datu bloka visas atbildes ir anonīmas.'"></span>
        </div>

        <template x-if="!preview && !disabled">
            <section id="editor-contact-configuration" class="se-identification se-contact-configuration" aria-labelledby="editor-contact-heading-shared">
                <h3 id="editor-contact-heading-shared">Kontaktinformācija aktivitātēm</h3>
                <p id="editor-contact-help-shared" class="se-helper">Atzīmējiet jautājumus un atbildes, pie kurām piedāvāt atstāt kontaktus. Visiem izvēlētajiem jautājumiem veidojiet vienus un tos pašus laukus.</p>
                <p class="se-helper">Vecāki pēc izvēles ievada kontaktus vienreiz aptaujas beigās. Atbilstošās aktivitātes nosaka viņu atbildes un šeit izvēlētie nosacījumi. Pārējās atbildes paliek anonīmas.</p>
                <p class="se-error" x-show="allowsIdentifiedResponses">Lai vāktu kontaktus aktivitātēm, vispirms noņemiet “Respondenta dati” bloku. Ja saglabāti abi bloki, noņemiet vienu no tiem.</p>
                <fieldset class="se-contact-question-list" aria-describedby="editor-contact-help-shared">
                    <legend>Pie kuriem jautājumiem piedāvāt atstāt kontaktus?</legend>
                    <p class="se-helper" x-show="!state.questions.length">Vispirms pievienojiet izvēles jautājumu.</p>
                    <template x-for="question in state.questions" :key="question.key">
                        <div class="se-contact-question-row">
                            <label class="se-checkbox-row">
                                <input :id="'editor-contact-question-' + question.key" type="checkbox" :checked="Boolean(question.contact)" :disabled="!supportsContact(question) || (allowsIdentifiedResponses && !question.contact)" @change="toggleQuestionContact(question, $event.target.checked)" :aria-describedby="'editor-contact-question-help-' + question.key">
                                <span x-text="number(question) + '. ' + (question.title || 'Jautājums bez teksta')"></span>
                            </label>
                            <p class="se-helper" :id="'editor-contact-question-help-' + question.key" x-show="!supportsContact(question)">Teksta jautājumam kontaktus pievienot nevar. Izvēlieties vienas vai vairāku atbilžu jautājumu.</p>
                            <template x-if="question.contact">
                                <div class="se-contact-question-details">
                                    <button type="button" class="se-contact-trigger-toggle" :id="'editor-contact-trigger-toggle-' + question.key" x-show="supportsContact(question)" @click="toggleContactSettings(question)" :aria-expanded="contactSettingsOpen(question)" :aria-controls="'editor-contact-triggers-' + question.key"><span x-text="contactConditionSummary(question)"></span><span class="se-contact-trigger-action" x-text="contactSettingsOpen(question) ? 'Aizvērt' : 'Mainīt atbildes'"></span></button>
                                    <fieldset class="se-contact-triggers" :id="'editor-contact-triggers-' + question.key" x-show="supportsContact(question) && contactSettingsOpen(question)" x-cloak :aria-describedby="'editor-contact-trigger-error-' + question.key + ' editor-contact-issue-' + question.key" tabindex="-1">
                                        <legend>Piedāvāt kontaktus, ja izvēlēta vismaz viena atbilde:</legend>
                                        <template x-for="(contactOption, contactOptionIndex) in options(question)" :key="contactOption.value">
                                            <label class="se-checkbox-row"><input type="checkbox" :checked="question.contact.trigger_values?.includes(contactOption.value)" @change="toggleContactTrigger(question, contactOption.value, $event.target.checked)"><span x-text="contactOption.label || 'Atbildes variants ' + (contactOptionIndex + 1)"></span></label>
                                        </template>
                                        <template x-for="missingValue in invalidContactTriggers(question)" :key="missingValue">
                                            <label class="se-checkbox-row se-contact-missing"><input type="checkbox" checked @change="toggleContactTrigger(question, missingValue, $event.target.checked)"><span>Trūkstoša atbilde — noņemiet atzīmi un izvēlieties esošu atbildi.</span></label>
                                        </template>
                                        <p class="se-helper" x-show="!options(question).length">Vispirms pievienojiet atbildes variantu.</p>
                                    </fieldset>
                                    <p class="se-error" :id="'editor-contact-trigger-error-' + question.key" x-show="fieldError(question, 'contact.trigger_values')" x-text="fieldError(question, 'contact.trigger_values')"></p>
                                    <p class="se-error" :id="'editor-contact-issue-' + question.key" x-show="contactTriggerIssue(question)" x-text="contactTriggerIssue(question)"></p>
                                    <button type="button" class="se-text-button" :id="'editor-contact-remove-' + question.key" x-show="!supportsContact(question)" @click="toggleQuestionContact(question, false)">Noņemt kontaktus šim jautājumam</button>
                                </div>
                            </template>
                        </div>
                    </template>
                </fieldset>
                <template x-if="commonContactQuestion">
                    <div class="se-common-contact-canvas">
                        <h4>Kontaktlauki visiem atzīmētajiem jautājumiem</h4>
                        <div class="se-contact-legacy" x-show="contactConfigsDiffer">
                            <p id="editor-contact-legacy-help" class="se-helper">Šajā aptaujā jautājumiem ir atšķirīgi kontaktlauki vai saziņas teksti. Tie ir saglabāti. Izvēlieties, kura jautājuma laukus un tekstus lietot visiem, lai tos rediģētu vienuviet. Atbildes nosacījumi katram jautājumam paliks savi.</p>
                            <label class="se-identity-field" for="editor-contact-source"><span>Paraugs kopīgajiem laukiem un tekstiem</span><select id="editor-contact-source" :value="commonContactQuestion.key" @change="setCommonContactSource($event.target.value)" aria-describedby="editor-contact-legacy-help"><template x-for="question in contactQuestions" :key="question.key"><option :value="question.key" :selected="question.key === commonContactQuestion.key" x-text="number(question) + '. ' + (question.title || 'Jautājums bez teksta')"></option></template></select></label>
                            <button id="editor-contact-unify" type="button" class="se-save-button" @click="unifyContactFields()" :disabled="allowsIdentifiedResponses">Lietot vienādus laukus</button>
                        </div>
                        <p id="editor-contact-edit-help-shared" class="se-helper" x-text="contactConfigsDiffer ? 'Pašlaik redzami izvēlētā jautājuma lauki. Lai tos mainītu visiem, vispirms lietojiet vienādus laukus.' : 'Pieskarieties lauka nosaukumam, lai to labotu. Atlasītajam laukam var mainīt veidu un obligātumu.'"></p>
                        <div class="se-contact-fields">
                            <template x-for="(field, fieldIndex) in commonContactFields" :key="field.key">
                                <div class="se-contact-field" :class="{'is-selected': selectedCommonContactField === field.key}">
                                    <div class="se-contact-label-line">
                                        <span class="se-contact-label-measure" aria-hidden="true" x-text="field.label || 'Lauka nosaukums'"></span>
                                        <input class="se-inline se-contact-label" data-contact-label :id="'editor-contact-label-shared-' + field.key" type="text" :readonly="!canEditCommonContacts" :value="field.label" @focus="selectCommonContactField(field.key)" @click="selectCommonContactField(field.key)" @input="updateCommonContactField(field.key, 'label', $event.target.value)" @keydown.enter.prevent maxlength="160" placeholder="Lauka nosaukums" :aria-label="'Kopīgā kontaktlauka nosaukums: ' + (fieldIndex + 1)" :aria-describedby="'editor-contact-edit-help-shared editor-contact-field-error-shared-' + field.key" :aria-invalid="Boolean(commonContactError('', field.key))">
                                        <span class="se-required-star" x-show="field.required" aria-hidden="true">*</span>
                                        <span class="se-contact-edit-mark" aria-hidden="true">✎</span>
                                    </div>
                                    <div class="se-contact-answer" :class="{'is-select': contactFieldType(field) === 'role'}" aria-hidden="true"><span x-text="contactFieldType(field) === 'role' ? 'Izvēlieties lomu' : contactFieldType(field) === 'email' ? 'E-pasta adrese' : contactFieldType(field) === 'tel' ? 'Tālruņa numurs' : 'Īsa atbilde'"></span><span x-show="contactFieldType(field) === 'role'">⌄</span></div>
                                    <div class="se-contact-field-tools" role="group" :aria-label="'Lauka iestatījumi: ' + (field.label || 'Lauks ' + (fieldIndex + 1))" x-show="selectedCommonContactField === field.key" x-cloak>
                                        <label class="se-contact-type"><span class="se-sr-only" x-text="'Lauka veids: ' + (field.label || 'Lauks ' + (fieldIndex + 1))"></span><select :id="'editor-contact-type-shared-' + field.key" :disabled="!canEditCommonContacts || !canChangeContactFieldType(field)" :value="contactFieldType(field)" @change="updateCommonContactField(field.key, 'type', $event.target.value)" :aria-describedby="'editor-contact-field-error-shared-' + field.key" :aria-invalid="Boolean(commonContactError('type', field.key))"><option value="text">Īss teksts</option><option value="email">E-pasts</option><option value="tel">Tālrunis</option><option value="role">Loma</option></select></label>
                                        <label class="se-checkbox-row se-contact-required"><input :id="'editor-contact-required-shared-' + field.key" type="checkbox" :disabled="!canEditCommonContacts" :checked="field.required" @change="updateCommonContactField(field.key, 'required', $event.target.checked)" :aria-label="'Obligāts: ' + (field.label || 'Lauks ' + (fieldIndex + 1))" :aria-describedby="'editor-contact-field-error-shared-' + field.key" :aria-invalid="Boolean(commonContactError('required', field.key))"><span>Obligāts</span></label>
                                        <button type="button" class="se-icon-button se-remove" :disabled="!canEditCommonContacts" @click="removeCommonContactField(field.key)" :aria-label="'Noņemt kontaktinformācijas lauku: ' + (field.label || 'Lauks ' + (fieldIndex + 1))" title="Noņemt lauku">×</button>
                                        <p class="se-helper se-contact-role-help" x-show="contactFieldType(field) === 'role'">Izvēles: mamma, tētis vai cits aizbildnis. Lomu vecāki norāda paši.</p>
                                    </div>
                                    <p class="se-error" :id="'editor-contact-field-error-shared-' + field.key" x-show="commonContactError('', field.key)" x-text="commonContactError('', field.key)"></p>
                                </div>
                            </template>
                        </div>
                        <p class="se-helper" x-show="!commonContactFields.length">Pievienojiet lauku, ko vecāki varēs aizpildīt.</p>
                        <div class="se-contact-add-area" @click.outside="closeCommonContactPicker($el.contains(document.activeElement))" @keydown.escape.stop.prevent="closeCommonContactPicker()" @focusout="if (!$el.contains($event.relatedTarget)) closeCommonContactPicker(false)">
                            <button id="editor-contact-add-field-shared" type="button" class="se-contact-add-field" @click="contactPicker === 'shared' ? closeCommonContactPicker() : openCommonContactPicker()" :disabled="!canEditCommonContacts || commonContactFields.length >= 12" :aria-expanded="contactPicker === 'shared'" aria-controls="editor-contact-picker-shared" aria-describedby="editor-contact-limit-shared">+ Pievienot lauku</button>
                            <div id="editor-contact-picker-shared" class="se-contact-picker" role="group" aria-label="Izvēlieties kopīgo kontaktlauku" x-show="contactPicker === 'shared'" x-cloak>
                                <template x-for="preset in [{key:'name',label:'Vārds un uzvārds'},{key:'phone',label:'Tālrunis'},{key:'email',label:'E-pasts'},{key:'custom',label:'Cits teksta lauks'},{key:'role',label:'Loma'}]" :key="preset.key">
                                    <button type="button" @click="addCommonContactField(preset.key)" :disabled="!canEditCommonContacts || commonContactFields.length >= 12 || (preset.key !== 'custom' && hasCommonContactField(preset.key))"><span x-text="preset.label"></span><span class="se-helper" x-show="preset.key !== 'custom' && hasCommonContactField(preset.key)">Pievienots</span></button>
                                </template>
                                <button type="button" class="se-contact-picker-cancel" @click="closeCommonContactPicker()">Atcelt</button>
                            </div>
                        </div>
                        <p id="editor-contact-limit-shared" class="se-helper se-contact-limit" x-show="commonContactFields.length >= 12">Pievienoti visi 12 lauki. Lai pievienotu citu, noņemiet kādu esošo.</p>
                        <p class="se-error" x-show="commonContactError('fields')" x-text="commonContactError('fields')"></p>
                        <div class="se-contact-settings">
                            <button id="editor-contact-settings-toggle-shared" type="button" class="se-contact-settings-toggle" @click="commonContactSettings = !commonContactSettings; resizeAll()" :aria-expanded="commonContactSettings" aria-controls="editor-contact-settings-shared"><span>Teksti vecākiem</span><span aria-hidden="true" x-text="commonContactSettings ? '−' : '+'"></span></button>
                            <div id="editor-contact-settings-shared" x-show="commonContactSettings" x-cloak>
                                <div class="se-identity-field se-contact-setting">
                                    <label for="editor-contact-prompt-shared">Izvēles teksts vecākiem</label>
                                    <input id="editor-contact-prompt-shared" type="text" :readonly="!canEditCommonContacts" :value="commonContactQuestion.contact.prompt" @input="updateCommonContact('prompt', $event.target.value)" maxlength="160" :aria-invalid="Boolean(commonContactError('prompt'))" aria-describedby="editor-contact-prompt-error-shared">
                                    <p id="editor-contact-prompt-error-shared" class="se-error" x-show="commonContactError('prompt')" x-text="commonContactError('prompt')"></p>
                                </div>
                                <div class="se-identity-field se-contact-setting">
                                    <label for="editor-contact-purpose-shared">Kam izmantosiet kontaktinformāciju?</label>
                                    <textarea id="editor-contact-purpose-shared" rows="3" :readonly="!canEditCommonContacts" :value="commonContactQuestion.contact.purpose" @input="updateCommonContact('purpose', $event.target.value); resize($el)" maxlength="1000" :aria-invalid="Boolean(commonContactError('purpose'))" aria-describedby="editor-contact-purpose-error-shared"></textarea>
                                    <p id="editor-contact-purpose-error-shared" class="se-error" x-show="commonContactError('purpose')" x-text="commonContactError('purpose')"></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </template>
                <ul class="se-contact-error-list" x-show="contactValidationErrors.length" aria-label="Kontaktinformācijas kļūdas">
                    <template x-for="error in contactValidationErrors" :key="error.path"><li><button type="button" class="se-text-button se-error" @click="focusContactError(error.question, error.path)" x-text="number(error.question) + '. jautājums: ' + error.message"></button></li></template>
                </ul>
            </section>
        </template>

        <template x-if="(preview || disabled) && previewShared.activities.length">
            <section class="se-identification se-shared-contacts" aria-labelledby="preview-shared-contacts-title">
                <h3 id="preview-shared-contacts-title">Kontaktinformācija aktivitātēm</h3>
                <p id="preview-shared-contacts-help" class="se-helper">Pēc noklusējuma atbildat anonīmi. Kontaktus varat atstāt pēc izvēles. Tie būs saistīti tikai ar zemāk norādīto aktivitāšu izvēlēm; pārējās aptaujas atbildes paliek anonīmas.</p>
                <label class="se-checkbox-row se-contact-optin" for="preview-shared-contact-consent">
                    <input id="preview-shared-contact-consent" type="checkbox" :checked="previewShared.consent" @change="setSharedPreviewConsent($event.target.checked)" form="survey-editor-preview-only" aria-describedby="preview-shared-contacts-help" aria-controls="preview-shared-contact-details" :aria-expanded="previewShared.consent">
                    <span id="preview-shared-contact-prompt" x-text="previewSharedCommonPrompt || 'Atstāt kontaktinformāciju'"></span>
                </label>
                <template x-if="previewShared.consent">
                    <div id="preview-shared-contact-details">
                        <p id="preview-shared-common-purpose" class="se-helper" x-show="previewSharedCommonPurpose" x-text="previewSharedCommonPurpose"></p>
                        <p id="preview-shared-activities-label" class="se-helper">Kontaktinformācija paredzēta šīm aktivitātēm:</p>
                        <ul class="se-shared-contact-activities" aria-labelledby="preview-shared-activities-label">
                            <template x-for="(activity, activityIndex) in previewShared.activities" :key="activity.key">
                                <li class="se-shared-contact-activity">
                                    <p class="se-shared-contact-title" :id="'preview-shared-title-' + activityIndex" x-text="activity.title || 'Jautājums bez teksta'"></p>
                                    <p class="se-helper" x-show="!previewSharedCommonPrompt && activity.prompt" x-text="activity.prompt"></p>
                                    <p class="se-helper" x-show="!previewSharedCommonPurpose" :id="'preview-shared-purpose-' + activityIndex" x-text="activity.purpose"></p>
                                </li>
                            </template>
                        </ul>
                        <div class="se-identity-fields">
                            <template x-for="(field, fieldIndex) in previewShared.fields" :key="field.id">
                                <div class="se-identity-field">
                                    <label :for="'preview-shared-field-' + fieldIndex"><span x-text="field.displayLabel || field.label"></span><span class="se-required-star" x-show="field.required" aria-hidden="true"> *</span><span class="se-sr-only" x-show="field.required"> (Obligāts, ja atstāj kontaktinformāciju)</span></label>
                                    <template x-if="contactFieldType(field) !== 'role'"><input :id="'preview-shared-field-' + fieldIndex" :type="contactFieldType(field)" :value="field.value" @input="setSharedPreviewValue(field.id, $event.target.value)" :maxlength="field.maxLength" autocomplete="off" form="survey-editor-preview-only" @keydown.enter.prevent :aria-required="field.required" aria-describedby="preview-shared-contacts-help"></template>
                                    <template x-if="contactFieldType(field) === 'role'"><select :id="'preview-shared-field-' + fieldIndex" :value="field.value" @change="setSharedPreviewValue(field.id, $event.target.value)" form="survey-editor-preview-only" :aria-required="field.required" aria-describedby="preview-shared-contacts-help"><option value="">Izvēlieties lomu</option><option value="mother">Mamma</option><option value="father">Tētis</option><option value="guardian">Cits aizbildnis</option></select></template>
                                </div>
                            </template>
                        </div>
                        <p class="se-helper" x-show="!previewShared.fields.length">Nav konfigurētu kontaktlauku. Pārbaudiet kontaktinformācijas iestatījumus.</p>
                    </div>
                </template>
            </section>
        </template>

        <p class="se-preview-note" x-show="preview">Šis ir priekšskatījums. Šeit ievadītā informācija un atbildes netiek saglabātas vai iesniegtas.<span x-show="!disabled"> Pārslēdzieties uz “Rediģēt”, lai turpinātu veidot aptauju.</span></p>
    </div>
</div>
