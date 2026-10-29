import "./shared-question-contacts.js";

export default function surveyEditor({
    state,
    disabled = false,
    saveAction = "save",
    existing = false,
}) {
    return {
        state,
        disabled,
        preview: disabled,
        dirty: false,
        active: null,
        more: {},
        errors: {},
        notice: "",
        pendingDelete: null,
        dragging: null,
        previewAnswers: {},
        previewContacts: {},
        previewSharedController: globalThis.MikausisSharedContacts.create(),
        previewShared: { consent: false, activities: [], fields: [] },
        selectedContactFields: {},
        contactPicker: null,
        contactSettings: {},
        contactSettingsIssues: {},
        commonContactSourceKey: null,
        selectedCommonContactField: null,
        commonContactSettings: false,
        previewRespondent: { anonymous: true, values: {} },
        saving: false,
        existing,
        get allowsIdentifiedResponses() {
            return this.state.allow_identified_responses === true;
        },
        get previewSharedCommonPrompt() {
            const activities = this.previewShared.activities;
            return activities.length &&
                activities.every(
                    (activity) => activity.prompt === activities[0].prompt,
                )
                ? activities[0].prompt
                : "";
        },
        get previewSharedCommonPurpose() {
            const activities = this.previewShared.activities;
            return activities.length &&
                activities.every(
                    (activity) => activity.purpose === activities[0].purpose,
                )
                ? activities[0].purpose
                : "";
        },
        get hasQuestionContacts() {
            return this.state.questions.some((question) =>
                Boolean(question.contact),
            );
        },
        get respondentFields() {
            if (!this.allowsIdentifiedResponses) return [];
            return Array.isArray(this.state.respondent_fields)
                ? this.state.respondent_fields
                : this.defaultRespondentFields();
        },
        get previewIdentified() {
            return (
                this.allowsIdentifiedResponses &&
                !this.previewRespondent.anonymous
            );
        },
        defaultRespondentFields() {
            return [
                {
                    key: "name",
                    label: "Vārds un uzvārds",
                    type: "text",
                    required: true,
                },
                {
                    key: "role",
                    label: "Loma",
                    type: "role",
                    required: true,
                },
            ];
        },
        addRespondentBlock() {
            if (
                this.disabled ||
                this.allowsIdentifiedResponses ||
                this.hasQuestionContacts
            )
                return;
            this.state.respondent_fields = this.defaultRespondentFields();
            this.state.allow_identified_responses = true;
            this.setPreviewAnonymous(true);
            this.focusRespondentField("name");
        },
        removeRespondentBlock() {
            if (this.disabled) return;
            this.state.allow_identified_responses = false;
            this.state.respondent_fields = [];
            this.setPreviewAnonymous(true);
            this.pendingDelete = null;
            this.focusRespondentField(null);
        },
        focusRespondentField(key) {
            this.$nextTick(() => {
                const input = key
                    ? document.getElementById("editor-respondent-label-" + key)
                    : this.$root.querySelector("[data-add-respondent]");
                input?.focus();
            });
        },
        editableRespondentFields() {
            if (!Array.isArray(this.state.respondent_fields))
                this.state.respondent_fields = this.respondentFields.map(
                    (field) => ({ ...field }),
                );
            return this.state.respondent_fields;
        },
        hasRespondentField(key) {
            return this.respondentFields.some((field) => field.key === key);
        },
        addRespondentField(preset) {
            if (
                this.disabled ||
                !this.allowsIdentifiedResponses ||
                this.respondentFields.length >= 12 ||
                (preset !== "custom" && this.hasRespondentField(preset))
            )
                return;
            const field = this.respondentFieldPreset(preset);
            if (!field) return;
            this.editableRespondentFields().push(field);
            this.focusRespondentField(field.key);
        },
        respondentFieldPreset(preset) {
            if (preset === "custom")
                return {
                    key: this.id("detail"),
                    label: "Papildu informācija",
                    type: "text",
                    required: false,
                };
            const presets = {
                name: this.defaultRespondentFields()[0],
                role: this.defaultRespondentFields()[1],
                email: {
                    key: "email",
                    label: "E-pasts",
                    type: "email",
                    required: false,
                },
                phone: {
                    key: "phone",
                    label: "Tālrunis",
                    type: "tel",
                    required: false,
                },
            };
            return presets[preset];
        },
        updateRespondentField(key, property, value) {
            if (
                this.disabled ||
                !this.allowsIdentifiedResponses ||
                !["label", "required"].includes(property)
            )
                return;
            const field = this.editableRespondentFields().find(
                (item) => item.key === key,
            );
            if (field)
                field[property] =
                    property === "required" ? Boolean(value) : value;
        },
        removeRespondentField(key) {
            if (this.disabled || !this.allowsIdentifiedResponses) return;
            this.state.respondent_fields = this.respondentFields.filter(
                (field) => field.key !== key,
            );
            if (!this.state.respondent_fields.length)
                this.removeRespondentBlock();
            else {
                this.syncPreviewRespondentFields();
                this.focusRespondentField(this.state.respondent_fields[0].key);
            }
        },
        respondentFieldType(field) {
            return (
                {
                    text: "Īsa teksta atbilde",
                    email: "E-pasta adrese",
                    tel: "Tālruņa numurs",
                    role: "Izvēle: mamma, tētis vai cits aizbildnis",
                }[field.type] || "Īsa teksta atbilde"
            );
        },
        respondentFieldError(index) {
            const prefix = "data.builder.respondent_fields." + index;
            return Object.entries(this.errors)
                .filter(
                    ([key]) => key === prefix || key.startsWith(prefix + "."),
                )
                .flatMap(([, value]) => value)
                .join(" ");
        },
        syncPreviewRespondentFields() {
            const keys = new Set(
                this.respondentFields.map((field) => field.key),
            );
            this.previewRespondent.values = Object.fromEntries(
                Object.entries(this.previewRespondent.values).filter(([key]) =>
                    keys.has(key),
                ),
            );
        },
        setPreviewAnonymous(anonymous) {
            this.previewRespondent.anonymous =
                Boolean(anonymous) || !this.allowsIdentifiedResponses;
            if (this.previewRespondent.anonymous)
                this.previewRespondent.values = {};
        },
        get contactQuestions() {
            return this.state.questions.filter((question) => question.contact);
        },
        get commonContactQuestion() {
            return (
                this.contactQuestions.find(
                    (question) => question.key === this.commonContactSourceKey,
                ) ||
                this.contactQuestions[0] ||
                null
            );
        },
        get commonContactFields() {
            return this.commonContactQuestion
                ? this.contactFields(this.commonContactQuestion)
                : [];
        },
        contactConfig(question) {
            return {
                prompt: question.contact.prompt,
                purpose: question.contact.purpose,
                fields: this.contactFields(question),
            };
        },
        get contactConfigsDiffer() {
            const configs = this.contactQuestions.map((question) =>
                JSON.stringify(this.contactConfig(question)),
            );
            return new Set(configs).size > 1;
        },
        get canEditCommonContacts() {
            return Boolean(
                !this.disabled &&
                    !this.allowsIdentifiedResponses &&
                    this.commonContactQuestion &&
                    !this.contactConfigsDiffer,
            );
        },
        get contactValidationErrors() {
            return Object.entries(this.errors).flatMap(([path, messages]) => {
                const match = path.match(
                    /^data\.builder\.questions\.(\d+)\.contact(?:\.|$)/,
                );
                const question =
                    match && this.state.questions[Number(match[1])];
                return question
                    ? [
                          {
                              path,
                              question,
                              message: [].concat(messages).join(" "),
                          },
                      ]
                    : [];
            });
        },
        commonContactError(property, fieldKey = null) {
            const questions = this.contactConfigsDiffer
                ? [this.commonContactQuestion].filter(Boolean)
                : this.contactQuestions;
            const messages = questions.flatMap((question) => {
                const index =
                    fieldKey === null
                        ? -1
                        : this.contactFields(question).findIndex(
                              (field) => field.key === fieldKey,
                          );
                if (fieldKey !== null && index < 0) return [];
                const suffix =
                    fieldKey === null
                        ? property
                        : "fields." + index + (property ? "." + property : "");
                const prefix =
                    "data.builder.questions." +
                    (this.number(question) - 1) +
                    ".contact" +
                    (suffix ? "." + suffix : "");
                const error =
                    fieldKey === null
                        ? [].concat(this.errors[prefix] || []).join(" ")
                        : this.fieldError(question, "contact." + suffix);
                return error ? [error] : [];
            });
            return [...new Set(messages)].join(" ");
        },
        setCommonContactSource(key) {
            this.commonContactSourceKey = key;
            this.selectedCommonContactField = null;
            this.contactPicker = null;
            this.resizeAll();
        },
        copyCommonContactConfig(source) {
            // Copy schema only. Respondent values and consent never enter builder state.
            const config = JSON.parse(
                JSON.stringify(this.contactConfig(source)),
            );
            for (const question of this.contactQuestions) {
                question.contact = {
                    ...JSON.parse(JSON.stringify(config)),
                    trigger_values: [
                        ...(question.contact.trigger_values || []),
                    ],
                };
            }
            this.syncPreviewContacts();
        },
        unifyContactFields() {
            if (
                this.disabled ||
                this.allowsIdentifiedResponses ||
                !this.commonContactQuestion
            )
                return;
            this.copyCommonContactConfig(this.commonContactQuestion);
            this.notice =
                "Visiem atzīmētajiem jautājumiem tagad ir vienādi kontaktlauki un saziņas teksts. Atbildes nosacījumi saglabāti.";
            this.selectedCommonContactField =
                this.commonContactFields[0]?.key || null;
            this.focusCommonContactField(this.selectedCommonContactField);
        },
        toggleQuestionContact(question, checked) {
            if (this.disabled) return;
            if (!checked) {
                this.removeContactBlock(question);
                this.syncContactEditor();
                return;
            }
            if (
                question.contact ||
                this.allowsIdentifiedResponses ||
                !this.supportsContact(question)
            )
                return;
            const source = this.commonContactQuestion;
            const config = source
                ? JSON.parse(JSON.stringify(this.contactConfig(source)))
                : {
                      prompt: "Atstāt kontaktinformāciju",
                      purpose:
                          "Kontaktinformācija paredzēta izvēlēto aktivitāšu organizēšanai. Tā netiek piesaistīta citām aptaujas atbildēm.",
                      fields: [
                          this.respondentFieldPreset("name"),
                          {
                              ...this.respondentFieldPreset("email"),
                              required: true,
                          },
                      ],
                  };
            question.contact = {
                ...config,
                trigger_values: this.options(question)
                    .slice(0, 1)
                    .map((option) => option.value),
            };
            this.clearPreviewContact(question);
            this.syncContactEditor();
        },
        selectCommonContactField(key) {
            if (this.disabled || !this.hasCommonContactField(key)) return;
            this.selectedCommonContactField = key;
        },
        hasCommonContactField(key) {
            return this.commonContactFields.some((field) => field.key === key);
        },
        updateCommonContactField(key, property, value) {
            if (!this.canEditCommonContacts) return;
            const source = this.commonContactQuestion;
            if (
                property === "type" &&
                ["text", "email", "tel", "role"].includes(value)
            ) {
                for (const question of this.contactQuestions) {
                    const field = this.contactFields(question).find(
                        (item) => item.key === key,
                    );
                    if (field && field.type !== value)
                        delete this.previewContacts[question.key]?.values[key];
                }
            }
            this.updateContactField(source, key, property, value);
            this.copyCommonContactConfig(source);
        },
        addCommonContactField(preset) {
            if (
                !this.canEditCommonContacts ||
                this.commonContactFields.length >= 12 ||
                (preset !== "custom" && this.hasCommonContactField(preset))
            )
                return;
            const field = this.respondentFieldPreset(preset);
            if (!field) return;
            const source = this.commonContactQuestion;
            source.contact.fields = [...this.commonContactFields, field];
            this.copyCommonContactConfig(source);
            this.closeCommonContactPicker(false);
            this.selectedCommonContactField = field.key;
            this.focusCommonContactField(field.key);
        },
        removeCommonContactField(key) {
            if (!this.canEditCommonContacts) return;
            const index = this.commonContactFields.findIndex(
                (field) => field.key === key,
            );
            if (index < 0) return;
            const source = this.commonContactQuestion;
            source.contact.fields = this.commonContactFields.filter(
                (field) => field.key !== key,
            );
            this.copyCommonContactConfig(source);
            const next =
                this.commonContactFields[
                    Math.min(index, this.commonContactFields.length - 1)
                ];
            this.selectedCommonContactField = next?.key || null;
            this.focusCommonContactField(next?.key);
        },
        updateCommonContact(property, value) {
            if (!this.canEditCommonContacts) return;
            const source = this.commonContactQuestion;
            this.updateContact(source, property, value);
            this.copyCommonContactConfig(source);
        },
        openCommonContactPicker() {
            if (
                !this.canEditCommonContacts ||
                this.commonContactFields.length >= 12
            )
                return;
            this.contactPicker = "shared";
            this.$nextTick(() =>
                document
                    .getElementById("editor-contact-picker-shared")
                    ?.querySelector("button:not(:disabled)")
                    ?.focus(),
            );
        },
        closeCommonContactPicker(restoreFocus = true) {
            if (this.contactPicker !== "shared") return;
            this.contactPicker = null;
            if (restoreFocus) this.focusCommonContactField();
        },
        focusCommonContactField(key) {
            this.$nextTick(() =>
                document
                    .getElementById(
                        key
                            ? "editor-contact-label-shared-" + key
                            : "editor-contact-add-field-shared",
                    )
                    ?.focus(),
            );
        },
        supportsContact(question) {
            return ["single", "multiple"].includes(question.type);
        },
        contactFields(question) {
            return Array.isArray(question.contact?.fields)
                ? question.contact.fields
                : [];
        },
        addContactBlock(question) {
            if (
                this.disabled ||
                this.allowsIdentifiedResponses ||
                question.contact ||
                !this.supportsContact(question)
            )
                return;
            question.contact = {
                prompt: "Atstāt kontaktinformāciju",
                purpose:
                    "Kontaktinformācija paredzēta šī jautājuma aktivitātes organizēšanai. Tā netiek piesaistīta citām aptaujas atbildēm.",
                trigger_values: this.options(question)
                    .slice(0, 1)
                    .map((option) => option.value),
                fields: [
                    this.respondentFieldPreset("name"),
                    { ...this.respondentFieldPreset("email"), required: true },
                ],
            };
            this.clearPreviewContact(question);
            this.selectContactField(question, "name");
            this.focusContactField(question, "name");
        },
        removeContactBlock(question) {
            if (this.disabled) return;
            const issue = this.contactIssue(question);
            delete question.contact;
            delete this.selectedContactFields[question.key];
            delete this.contactSettings[question.key];
            delete this.contactSettingsIssues[question.key];
            if (this.contactPicker === question.key) this.contactPicker = null;
            this.clearPreviewContact(question);
            this.pendingDelete = null;
            if (this.notice === issue) this.notice = "";
            this.$nextTick(() =>
                document
                    .getElementById("editor-contact-question-" + question.key)
                    ?.focus(),
            );
        },
        updateContact(question, property, value) {
            if (
                this.disabled ||
                !question.contact ||
                !["prompt", "purpose"].includes(property)
            )
                return;
            question.contact[property] = value;
        },
        hasContactField(question, key) {
            return this.contactFields(question).some(
                (field) => field.key === key,
            );
        },
        selectContactField(question, key) {
            if (this.disabled || !this.hasContactField(question, key)) return;
            this.selectedContactFields[question.key] = key;
        },
        openContactPicker(question) {
            if (
                this.disabled ||
                !question.contact ||
                this.contactFields(question).length >= 12
            )
                return;
            this.contactPicker = question.key;
            this.$nextTick(() =>
                document
                    .getElementById("editor-contact-picker-" + question.key)
                    ?.querySelector("button:not(:disabled)")
                    ?.focus(),
            );
        },
        closeContactPicker(question, restoreFocus = true) {
            if (this.contactPicker !== question.key) return;
            this.contactPicker = null;
            if (restoreFocus)
                this.$nextTick(() =>
                    document
                        .getElementById(
                            "editor-contact-add-field-" + question.key,
                        )
                        ?.focus(),
                );
        },
        contactConditionSummary(question) {
            const triggers = question.contact?.trigger_values || [];
            if (!triggers.length)
                return "Izvēlieties atbildi, pie kuras rādīt bloku.";
            const labels = triggers.map((value) => {
                const index = this.options(question).findIndex(
                    (option) => option.value === value,
                );
                return index < 0
                    ? "Trūkstoša atbilde"
                    : "“" +
                          (this.options(question)[index].label ||
                              "Atbildes variants " + (index + 1)) +
                          "”";
            });
            return "Rādīt, ja izvēlēta: " + labels.join(" vai ") + ".";
        },
        contactSettingsOpen(question) {
            return Boolean(
                this.contactSettings[question.key] ??
                    (question.contact &&
                        (this.allowsIdentifiedResponses ||
                            !this.supportsContact(question) ||
                            this.invalidContactTriggers(question).length ||
                            !question.contact.trigger_values?.length)),
            );
        },
        syncContactEditor() {
            for (const question of this.state.questions) {
                const selected = this.selectedContactFields[question.key];
                if (selected && !this.hasContactField(question, selected))
                    delete this.selectedContactFields[question.key];
                const issue = this.contactIssue(question);
                if (
                    issue &&
                    issue !== this.contactSettingsIssues[question.key] &&
                    this.contactFields(question).length
                )
                    this.contactSettings[question.key] = true;
                this.contactSettingsIssues[question.key] = issue;
            }
            if (!this.hasCommonContactField(this.selectedCommonContactField))
                this.selectedCommonContactField = null;
            if (this.contactPicker === "shared") {
                if (
                    !this.canEditCommonContacts ||
                    this.commonContactFields.length >= 12
                )
                    this.contactPicker = null;
            } else if (
                this.contactPicker &&
                !this.state.questions.some(
                    (question) =>
                        question.key === this.contactPicker &&
                        question.contact &&
                        this.contactFields(question).length < 12,
                )
            )
                this.contactPicker = null;
        },
        toggleContactSettings(question) {
            this.contactSettings[question.key] =
                !this.contactSettingsOpen(question);
            this.contactSettingsIssues[question.key] =
                this.contactIssue(question);
            if (this.pendingDelete === "contact-" + question.key)
                this.pendingDelete = null;
            this.resizeAll();
        },
        addContactField(question, preset) {
            if (
                this.disabled ||
                !question.contact ||
                this.contactFields(question).length >= 12 ||
                (preset !== "custom" && this.hasContactField(question, preset))
            )
                return;
            const field = this.respondentFieldPreset(preset);
            if (!field) return;
            if (!Array.isArray(question.contact.fields))
                question.contact.fields = [];
            question.contact.fields.push(field);
            this.closeContactPicker(question, false);
            this.selectContactField(question, field.key);
            this.focusContactField(question, field.key);
        },
        updateContactField(question, key, property, value) {
            if (
                this.disabled ||
                !question.contact ||
                !["label", "required", "type"].includes(property) ||
                (property === "type" &&
                    !["text", "email", "tel", "role"].includes(value))
            )
                return;
            const field = this.contactFields(question).find(
                (item) => item.key === key,
            );
            if (field) {
                if (property === "type" && field.type !== value)
                    delete this.previewContacts[question.key]?.values[key];
                field[property] =
                    property === "required" ? Boolean(value) : value;
            }
        },
        removeContactField(question, key) {
            if (this.disabled || !question.contact) return;
            const index = this.contactFields(question).findIndex(
                (field) => field.key === key,
            );
            if (index < 0) return;
            question.contact.fields = this.contactFields(question).filter(
                (field) => field.key !== key,
            );
            this.syncPreviewContacts();
            const next =
                question.contact.fields[
                    Math.min(index, question.contact.fields.length - 1)
                ];
            if (next) this.selectContactField(question, next.key);
            else delete this.selectedContactFields[question.key];
            this.focusContactField(question, next?.key);
        },
        focusContactField(question, key) {
            this.$nextTick(() =>
                document
                    .getElementById(
                        key
                            ? "editor-contact-label-" + question.key + "-" + key
                            : "editor-contact-add-field-" + question.key,
                    )
                    ?.focus(),
            );
        },
        invalidContactTriggers(question) {
            const valid = new Set(
                this.options(question).map((option) => option.value),
            );
            return (question.contact?.trigger_values || []).filter(
                (value) => !valid.has(value),
            );
        },
        toggleContactTrigger(question, value, checked) {
            if (this.disabled || !question.contact) return;
            if (
                checked &&
                !this.options(question).some((option) => option.value === value)
            )
                return;
            const selected = question.contact.trigger_values || [];
            question.contact.trigger_values = checked
                ? [...new Set([...selected, value])]
                : selected.filter((item) => item !== value);
            this.clearPreviewContact(question);
        },
        contactIssue(question) {
            if (!question.contact) return "";
            if (this.allowsIdentifiedResponses)
                return "Šajā aptaujā jau ir respondenta datu bloks. Noņemiet to vai šī jautājuma kontaktinformācijas bloku.";
            if (!this.supportsContact(question))
                return "Kontaktinformāciju var pievienot tikai izvēles jautājumam. Mainiet atbildes veidu vai noņemiet kontaktinformācijas bloku.";
            if (this.invalidContactTriggers(question).length)
                return "Kontaktinformācijas blokam izvēlēta izdzēsta atbilde. Noņemiet trūkstošo izvēli un atzīmējiet vajadzīgo atbildi.";
            if (!question.contact.trigger_values?.length)
                return "Atzīmējiet vismaz vienu atbildi, pie kuras piedāvāt atstāt kontaktinformāciju.";
            if (!this.contactFields(question).length)
                return "Pievienojiet vismaz vienu kontaktinformācijas lauku vai noņemiet bloku.";
            return "";
        },
        contactTriggerIssue(question) {
            if (!question.contact || !this.supportsContact(question)) return "";
            if (this.invalidContactTriggers(question).length)
                return "Izvēlēta izdzēsta atbilde. Noņemiet trūkstošo atzīmi un izvēlieties esošu atbildi.";
            if (!question.contact.trigger_values?.length)
                return "Atzīmējiet vismaz vienu atbildi, pie kuras piedāvāt atstāt kontaktus.";
            return "";
        },
        clearPreviewContact(question) {
            delete this.previewContacts[question.key];
        },
        previewContactAvailable(question) {
            return Boolean(
                question.contact &&
                    !this.contactIssue(question) &&
                    this.previewVisible(question) &&
                    (this.previewAnswers[question.key] || []).some((value) =>
                        question.contact.trigger_values.includes(value),
                    ),
            );
        },
        previewContactOptedIn(question) {
            return (
                this.previewContactAvailable(question) &&
                this.previewContacts[question.key]?.optedIn === true &&
                JSON.stringify(
                    this.previewContacts[question.key].triggerValues,
                ) === JSON.stringify(question.contact.trigger_values)
            );
        },
        setPreviewContactOptIn(question, optedIn) {
            this.clearPreviewContact(question);
            if (optedIn && this.previewContactAvailable(question))
                this.previewContacts[question.key] = {
                    optedIn: true,
                    triggerValues: [...question.contact.trigger_values],
                    values: {},
                };
        },
        setPreviewContactValue(question, key, value) {
            if (
                !this.previewContactOptedIn(question) ||
                !this.hasContactField(question, key)
            )
                return;
            this.previewContacts[question.key].values[key] = value;
        },
        previewContactValue(question, key) {
            return this.previewContacts[question.key]?.values[key] || "";
        },
        syncPreviewContacts() {
            for (const key of Object.keys(this.previewContacts)) {
                const question = this.state.questions.find(
                    (item) => item.key === key,
                );
                if (!question || !this.previewContactOptedIn(question)) {
                    delete this.previewContacts[key];
                    continue;
                }
                const keys = new Set(
                    this.contactFields(question).map((field) => field.key),
                );
                this.previewContacts[key].values = Object.fromEntries(
                    Object.entries(this.previewContacts[key].values).filter(
                        ([fieldKey]) => keys.has(fieldKey),
                    ),
                );
            }
            this.syncSharedPreviewContacts();
        },
        syncSharedPreviewContacts() {
            const definitions = this.state.questions
                .filter(
                    (question) =>
                        question.contact && !this.contactIssue(question),
                )
                .map((question) => ({
                    key: question.key,
                    title: question.title,
                    prompt: question.contact.prompt,
                    purpose: question.contact.purpose,
                    triggerValues: question.contact.trigger_values,
                    fields: this.contactFields(question),
                }));
            this.previewSharedController.syncDefinitions(definitions);
            this.previewSharedController.syncSelections(
                this.state.questions.map((question) => ({
                    key: question.key,
                    visible: this.previewVisible(question),
                    values: this.previewAnswers[question.key] || [],
                })),
            );
            this.readSharedPreviewContacts();
        },
        readSharedPreviewContacts() {
            this.previewShared = {
                consent: this.previewSharedController.globalConsent,
                activities: this.previewSharedController
                    .activities()
                    .filter((activity) => activity.active),
                fields: this.previewSharedController.fields(),
            };
        },
        setSharedPreviewConsent(consent) {
            this.syncSharedPreviewContacts();
            this.previewSharedController.setGlobalConsent(consent);
            this.readSharedPreviewContacts();
        },
        setSharedPreviewValue(id, value) {
            this.previewSharedController.setValue(id, value);
            this.readSharedPreviewContacts();
        },
        async save() {
            if (this.saving || this.disabled) return;
            this.saving = true;
            try {
                this.state.questions.forEach((question) => {
                    if (!Array.isArray(question.options)) return;
                    question.options = question.options.filter(
                        (option) =>
                            option.value === "other" || option.label?.trim(),
                    );
                });
                this.syncPreviewContacts();
                await this.$wire[saveAction]();
            } finally {
                this.saving = false;
            }
        },
        init() {
            this.resizeListener = () => this.resizeAll();
            window.addEventListener("resize", this.resizeListener);
            document.fonts?.ready.then(() => this.resizeAll());
            this.$nextTick(() => {
                this.$watch("state", () => {
                    this.dirty = true;
                    this.syncPreviewContacts();
                    this.syncContactEditor();
                });
                this.$watch("state.allow_identified_responses", () => {
                    if (!this.allowsIdentifiedResponses)
                        this.setPreviewAnonymous(true);
                });
                this.$watch("state.respondent_fields", () => {
                    this.syncPreviewRespondentFields();
                });
                this.resizeAll();
            });
        },
        destroy() {
            window.removeEventListener("resize", this.resizeListener);
        },
        id(prefix) {
            const crypto = globalThis.crypto;
            if (typeof crypto?.randomUUID === "function")
                return prefix + "_" + crypto.randomUUID().replaceAll("-", "");
            const bytes = new Uint8Array(16);
            if (typeof crypto?.getRandomValues === "function")
                crypto.getRandomValues(bytes);
            else {
                // Editor schema keys only; never use this fallback for secret tokens
                // or public contact/submission identifiers.
                for (let index = 0; index < bytes.length; index++)
                    bytes[index] = Math.floor(Math.random() * 256);
            }
            bytes[6] = (bytes[6] & 0x0f) | 0x40;
            bytes[8] = (bytes[8] & 0x3f) | 0x80;
            return (
                prefix +
                "_" +
                Array.from(bytes, (byte) =>
                    byte.toString(16).padStart(2, "0"),
                ).join("")
            );
        },
        options(question) {
            return (question.options || []).filter(
                (option) => option.value !== "other",
            );
        },
        displayOptions(question) {
            return question.allow_other &&
                !question.options.some((option) => option.value === "other")
                ? [
                      ...question.options,
                      { value: "other", label: "Cits variants" },
                  ]
                : question.options;
        },
        sectionQuestions(key) {
            return this.state.questions.filter(
                (question) => question.section === key,
            );
        },
        orderBySections() {
            this.state.questions = this.state.sections.flatMap((section) =>
                this.sectionQuestions(section.key),
            );
            this.checkRules();
        },
        checkRules() {
            this.state.questions.forEach((question) => {
                if (this.ruleIssue(question)) this.more[question.key] = true;
            });
        },
        number(question) {
            return (
                this.state.questions.findIndex(
                    (item) => item.key === question.key,
                ) + 1
            );
        },
        resize(input) {
            if (
                !input ||
                input.tagName !== "TEXTAREA" ||
                !input.getClientRects().length
            )
                return;
            input.style.height = "auto";
            input.style.height = Math.max(input.scrollHeight, 44) + "px";
        },
        resizeAll() {
            this.$nextTick(() =>
                this.$root
                    .querySelectorAll("textarea")
                    .forEach((input) => this.resize(input)),
            );
        },
        focusQuestion(key) {
            this.active = key;
            this.$nextTick(() => {
                const card = document.getElementById("editor-question-" + key);
                card?.querySelector("[data-question-title]")?.focus();
                card?.scrollIntoView({ block: "center", behavior: "smooth" });
                this.resizeAll();
            });
        },
        addQuestion(section, after = null) {
            if (this.disabled) return;
            if (this.state.questions.length >= 100) {
                this.notice =
                    "Vienā aptaujā var pievienot līdz 100 jautājumiem.";
                return;
            }
            const question = {
                key: this.id("q"),
                section,
                type: "single",
                title: "",
                description: "",
                required: false,
                allow_other: false,
                options: [
                    { value: this.id("o"), label: "" },
                    { value: this.id("o"), label: "" },
                ],
                exclusive_values: [],
                condition: null,
            };
            const afterIndex = after
                ? this.state.questions.findIndex((item) => item.key === after)
                : this.state.questions
                      .map((item) => item.section)
                      .lastIndexOf(section);
            this.state.questions.splice(
                afterIndex < 0 ? this.state.questions.length : afterIndex + 1,
                0,
                question,
            );
            this.orderBySections();
            this.focusQuestion(question.key);
        },
        addSection() {
            if (this.disabled) return;
            if (this.state.sections.length >= 40) {
                this.notice = "Vienā aptaujā var pievienot līdz 40 sadaļām.";
                return;
            }
            const section = {
                key: this.id("s"),
                title: "Jauna sadaļa",
                description: "",
            };
            this.state.sections.push(section);
            this.addQuestion(section.key);
        },
        duplicate(question) {
            if (this.disabled || this.state.questions.length >= 100) return;
            const copy = JSON.parse(JSON.stringify(question));
            copy.key = this.id("q");
            this.state.questions.splice(this.number(question), 0, copy);
            this.focusQuestion(copy.key);
        },
        move(question, direction) {
            if (this.disabled) return;
            const index = this.state.questions.findIndex(
                (item) => item.key === question.key,
            );
            const siblings = this.sectionQuestions(question.section);
            const target =
                siblings[
                    siblings.findIndex((item) => item.key === question.key) +
                        direction
                ];
            if (!target) return;
            const targetIndex = this.state.questions.findIndex(
                (item) => item.key === target.key,
            );
            this.state.questions.splice(index, 1);
            this.state.questions.splice(targetIndex, 0, question);
            this.active = question.key;
            this.checkRules();
        },
        drop(question) {
            const source = this.state.questions.find(
                (item) => item.key === this.dragging,
            );
            this.dragging = null;
            if (
                this.disabled ||
                !source ||
                source.key === question.key ||
                source.section !== question.section
            )
                return;
            this.state.questions.splice(
                this.state.questions.indexOf(source),
                1,
            );
            this.state.questions.splice(
                this.state.questions.indexOf(question),
                0,
                source,
            );
            this.checkRules();
        },
        removeQuestion(question) {
            if (this.disabled) return;
            this.state.questions = this.state.questions.filter(
                (item) => item.key !== question.key,
            );
            this.pendingDelete = null;
            this.active = null;
            this.clearPreviewContact(question);
            delete this.selectedContactFields[question.key];
            delete this.contactSettings[question.key];
            delete this.contactSettingsIssues[question.key];
            if (this.contactPicker === question.key) this.contactPicker = null;
            this.syncPreviewContacts();
            this.checkRules();
        },
        removeSection(section) {
            if (this.disabled || this.state.sections.length <= 1) return;
            const target = this.state.sections.find(
                (item) => item.key !== section.key,
            );
            this.state.questions.forEach((question) => {
                if (question.section === section.key)
                    question.section = target.key;
            });
            this.state.sections = this.state.sections.filter(
                (item) => item.key !== section.key,
            );
            this.orderBySections();
            this.pendingDelete = null;
            this.notice =
                "Sadaļa noņemta. Tās jautājumi pārvietoti uz sadaļu “" +
                target.title +
                "”.";
        },
        addOption(question, after = null) {
            if (this.disabled || question.options.length >= 100) return;
            const option = { value: this.id("o"), label: "" };
            const index = after
                ? question.options.findIndex((item) => item.value === after) + 1
                : question.options.findIndex((item) => item.value === "other");
            question.options.splice(
                index < 0 ? question.options.length : index,
                0,
                option,
            );
            this.$nextTick(() =>
                document
                    .getElementById(
                        "editor-option-" + question.key + "-" + option.value,
                    )
                    ?.focus(),
            );
        },
        removeOption(question, option) {
            if (this.disabled) return;
            question.options = question.options.filter(
                (item) => item.value !== option.value,
            );
            question.exclusive_values = (
                question.exclusive_values || []
            ).filter((value) => value !== option.value);
            this.checkRules();
            this.syncPreviewContacts();
        },
        toggleOther(question, enabled) {
            if (this.disabled) return;
            question.allow_other = enabled;
            if (
                enabled &&
                !question.options.some((item) => item.value === "other")
            )
                question.options.push({
                    value: "other",
                    label: "Cits variants",
                });
            if (!enabled) {
                question.options = question.options.filter(
                    (item) => item.value !== "other",
                );
                question.exclusive_values = (
                    question.exclusive_values || []
                ).filter((value) => value !== "other");
                this.checkRules();
            }
        },
        changeType(question, type) {
            if (this.disabled) return;
            const previousContactIssue = this.contactIssue(question);
            question.type = type;
            if (type !== "text" && !this.options(question).length)
                question.options.push({ value: this.id("o"), label: "" });
            if (type !== "multiple") question.exclusive_values = [];
            this.checkRules();
            this.clearPreviewContact(question);
            this.syncPreviewContacts();
            if (question.contact && !this.supportsContact(question))
                this.notice = this.contactIssue(question);
            else if (this.notice === previousContactIssue) this.notice = "";
        },
        toggleExclusive(question, value, checked) {
            const selected = question.exclusive_values || [];
            question.exclusive_values = checked
                ? [...new Set([...selected, value])]
                : selected.filter((item) => item !== value);
        },
        sources(question) {
            return this.state.questions
                .slice(0, this.number(question) - 1)
                .filter((item) => item.type !== "text");
        },
        source(question) {
            return this.state.questions.find(
                (item) => item.key === question.condition?.question,
            );
        },
        setSource(question, key) {
            question.condition = key ? { question: key, values: [] } : null;
        },
        toggleConditionValue(question, value, checked) {
            if (!question.condition) return;
            const selected = question.condition.values || [];
            question.condition.values = checked
                ? [...new Set([...selected, value])]
                : selected.filter((item) => item !== value);
        },
        ruleIssue(question) {
            if (!question.condition?.question) return "";
            const source = this.sources(question).find(
                (item) => item.key === question.condition.question,
            );
            if (!source)
                return "Nosacījuma jautājums ir izdzēsts vai pārvietots tālāk. Izvēlieties agrāku jautājumu vai noņemiet nosacījumu.";
            if (!question.condition.values?.length)
                return "Izvēlieties atbildi, pie kuras parādīt šo jautājumu.";
            if (
                question.condition.values.some(
                    (value) =>
                        !source.options.some((item) => item.value === value),
                )
            )
                return "Nosacījumā izvēlēta izdzēsta atbilde. Izvēlieties atbildi vēlreiz.";
            return "";
        },
        fieldError(question, field) {
            const prefix =
                "data.builder.questions." +
                (this.number(question) - 1) +
                (field ? "." + field : "");
            return Object.entries(this.errors)
                .filter(
                    ([key]) => key === prefix || key.startsWith(prefix + "."),
                )
                .flatMap(([, value]) => value)
                .join(" ");
        },
        receiveErrors(errors) {
            this.saving = false;
            this.errors = errors || {};
            this.preview = false;
            this.contactPicker = null;
            this.state.questions.forEach((question) => {
                const prefix =
                    "data.builder.questions." +
                    (this.number(question) - 1) +
                    ".contact";
                const paths = Object.keys(this.errors).filter(
                    (path) => path === prefix || path.startsWith(prefix + "."),
                );
                if (!paths.length || !question.contact) return;
                const fieldPath = paths.find((path) =>
                    /\.contact\.fields\.\d+/.test(path),
                );
                const index = fieldPath?.match(/\.contact\.fields\.(\d+)/)?.[1];
                const field = this.contactFields(question)[Number(index)];
                if (field) this.selectContactField(question, field.key);
                if (paths.some((path) => !path.startsWith(prefix + ".fields")))
                    this.contactSettings[question.key] = true;
            });
            const firstPath = Object.keys(this.errors).find((key) =>
                key.startsWith("data.builder.questions."),
            );
            const match = firstPath?.match(/questions\.(\d+)/);
            if (match && this.state.questions[Number(match[1])]) {
                const question = this.state.questions[Number(match[1])];
                this.more[question.key] = true;
                this.focusQuestion(question.key);
                const prefix =
                    "data.builder.questions." + match[1] + ".contact";
                if (
                    question.contact &&
                    (firstPath === prefix || firstPath.startsWith(prefix + "."))
                )
                    this.focusContactError(question, firstPath);
            } else if (
                Object.keys(this.errors).some(
                    (key) =>
                        key.startsWith("data.builder.respondent_fields") ||
                        key === "data.builder.allow_identified_responses",
                )
            ) {
                this.$nextTick(() =>
                    this.$root
                        .querySelector(
                            "[data-respondent-label], [data-add-respondent]",
                        )
                        ?.focus(),
                );
            } else
                this.$nextTick(() =>
                    this.$root.querySelector("[data-survey-title]")?.focus(),
                );
        },
        focusContactError(question, path) {
            this.preview = false;
            this.contactPicker = null;
            this.commonContactSourceKey = question.key;
            const match = path.match(/\.contact\.fields\.(\d+)(?:\.(\w+))?/);
            const field =
                match && this.contactFields(question)[Number(match[1])];
            if (field) {
                this.selectContactField(question, field.key);
                this.selectCommonContactField(field.key);
            }
            this.contactSettings[question.key] = true;
            if (!path.includes(".trigger_values"))
                this.commonContactSettings = true;
            const locked = !this.canEditCommonContacts;
            const suffix = "shared" + (field ? "-" + field.key : "");
            let id = field
                ? "editor-contact-" +
                  (!locked && match[2] === "type"
                      ? "type-"
                      : !locked && match[2] === "required"
                        ? "required-"
                        : "label-") +
                  suffix
                : path.includes(".contact.fields")
                  ? locked
                      ? this.contactConfigsDiffer
                          ? "editor-contact-unify"
                          : "editor-contact-question-" + question.key
                      : "editor-contact-add-field-shared"
                  : "editor-contact-" +
                    (path.endsWith(".prompt")
                        ? "prompt-"
                        : path.endsWith(".purpose")
                          ? "purpose-"
                          : path.includes(".trigger_values")
                            ? "triggers-"
                            : "settings-toggle-") +
                    (path.includes(".trigger_values")
                        ? question.key
                        : "shared");
            if (
                path.includes(".trigger_values") &&
                !this.supportsContact(question)
            )
                id = "editor-contact-remove-" + question.key;
            this.notice =
                this.number(question) +
                ". jautājuma kontaktinformācija: " +
                [].concat(this.errors[path] || []).join(" ") +
                (this.contactConfigsDiffer && !path.includes(".trigger_values")
                    ? " Lai labotu kopīgos laukus, izvēlieties “Lietot vienādus laukus”."
                    : "");
            this.$nextTick(() => {
                const target = document.getElementById(id);
                (target?.matches("fieldset")
                    ? target.querySelector("input:not(:disabled)") || target
                    : target
                )?.focus();
                this.resizeAll();
            });
        },
        saved() {
            this.saving = false;
            this.existing = true;
            this.dirty = false;
            this.errors = {};
            this.notice = "";
            this.$nextTick(() => {
                this.dirty = false;
            });
        },
        togglePreview(value) {
            this.preview = this.disabled || value;
            this.previewContacts = {};
            this.previewSharedController.setGlobalConsent(false);
            this.syncSharedPreviewContacts();
            this.contactPicker = null;
            this.resizeAll();
        },
        previewVisible(question) {
            if (!question.condition) return true;
            const source = this.source(question);
            return Boolean(
                source &&
                    this.previewVisibleSource(
                        source,
                        new Set([question.key]),
                    ) &&
                    (this.previewAnswers[source.key] || []).some((value) =>
                        question.condition.values.includes(value),
                    ),
            );
        },
        previewVisibleSource(question, seen) {
            if (seen.has(question.key)) return false;
            seen.add(question.key);
            if (!question.condition) return true;
            const source = this.source(question);
            return Boolean(
                source &&
                    this.previewVisibleSource(source, seen) &&
                    (this.previewAnswers[source.key] || []).some((value) =>
                        question.condition.values.includes(value),
                    ),
            );
        },
        answerPreview(question, value, checked) {
            const previousTriggers = (
                this.previewAnswers[question.key] || []
            ).filter((item) =>
                question.contact?.trigger_values?.includes(item),
            );
            if (question.type === "single")
                this.previewAnswers[question.key] = [value];
            else {
                let values = [
                    ...(this.previewAnswers[question.key] || []),
                ].filter((item) => item !== value);
                if (checked)
                    values = question.exclusive_values?.includes(value)
                        ? [value]
                        : [
                              ...values.filter(
                                  (item) =>
                                      !question.exclusive_values?.includes(
                                          item,
                                      ),
                              ),
                              value,
                          ];
                this.previewAnswers[question.key] = values;
            }
            const nextTriggers = (
                this.previewAnswers[question.key] || []
            ).filter((item) =>
                question.contact?.trigger_values?.includes(item),
            );
            if (
                JSON.stringify(previousTriggers) !==
                JSON.stringify(nextTriggers)
            )
                this.clearPreviewContact(question);
            this.syncPreviewContacts();
        },
    };
}
