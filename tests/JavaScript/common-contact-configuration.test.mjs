import assert from "node:assert/strict";
import test from "node:test";
import surveyEditor from "../../public/js/survey-editor.js";

function editor(config = {}) {
    const instance = surveyEditor({
        state: {
            title: "Aptauja",
            allow_identified_responses: false,
            respondent_fields: [],
            sections: [{ key: "all", title: "Jautājumi" }],
            questions: ["garden", "concert", "notes"].map((key, index) => ({
                key,
                section: "all",
                title: key,
                type: index === 2 ? "text" : "single",
                options: [
                    { value: "other", label: "Cits variants" },
                    { value: "yes_" + key, label: "Piedalīšos" },
                    { value: "later_" + key, label: "Vēlāk" },
                ],
                condition: null,
            })),
        },
        ...config,
    });
    instance.$nextTick = () => {};
    instance.resizeAll = () => {};
    instance.focusQuestion = () => {};
    return instance;
}

function selectBoth(instance) {
    for (const question of instance.state.questions.slice(0, 2))
        instance.toggleQuestionContact(question, true);
    return instance.state.questions.slice(0, 2);
}

function focusHarness(instance, context) {
    const descriptor = Object.getOwnPropertyDescriptor(globalThis, "document");
    const focused = [];
    const ticks = [];
    instance.$nextTick = (callback) => ticks.push(callback);
    globalThis.document = {
        getElementById: (id) => ({
            matches: () => id.startsWith("editor-contact-triggers-"),
            querySelector: () => ({ focus: () => focused.push(id + ":input") }),
            focus: () => focused.push(id),
        }),
    };
    context.after(() => {
        if (descriptor)
            Object.defineProperty(globalThis, "document", descriptor);
        else delete globalThis.document;
    });
    return {
        focused,
        flush() {
            while (ticks.length) ticks.shift()();
        },
    };
}

test("question selection shares one independent schema and defaults each trigger to its first normal answer", () => {
    const instance = editor();
    const [first, second] = selectBoth(instance);
    assert.deepEqual(first.contact.trigger_values, ["yes_garden"]);
    assert.deepEqual(second.contact.trigger_values, ["yes_concert"]);
    assert.deepEqual(
        first.contact.fields.map((field) => field.key),
        ["name", "email"],
    );
    assert.deepEqual(first.contact.fields, second.contact.fields);
    assert.notEqual(first.contact.fields, second.contact.fields);
    assert.notEqual(first.contact.fields[0], second.contact.fields[0]);
    assert.equal(instance.contactConfigsDiffer, false);
    instance.toggleQuestionContact(instance.state.questions[2], true);
    assert.equal(instance.state.questions[2].contact, undefined);
    const snapshot = structuredClone(instance.state);
    instance.toggleQuestionContact(first, true);
    assert.deepEqual(instance.state, snapshot);
});

test("common field and text edits clone stable keys only into checked questions and preserve individual triggers", () => {
    const instance = editor();
    const [first, second] = selectBoth(instance);
    instance.toggleContactTrigger(second, "later_concert", true);
    const triggers = [first, second].map((question) => [
        ...question.contact.trigger_values,
    ]);
    instance.addCommonContactField("phone");
    instance.addCommonContactField("role");
    instance.addCommonContactField("custom");
    const custom = instance.commonContactFields.at(-1).key;
    instance.updateCommonContactField(
        custom,
        "label",
        "Ērtākais saziņas laiks",
    );
    instance.updateCommonContactField("phone", "required", true);
    instance.updateCommonContactField(custom, "type", "tel");
    instance.updateCommonContact(
        "prompt",
        "Vēlos piedalīties un atstāt kontaktus",
    );
    instance.updateCommonContact(
        "purpose",
        "Saziņai par izvēlētajām aktivitātēm.",
    );
    assert.equal(instance.contactConfigsDiffer, false);
    assert.deepEqual(first.contact.fields, second.contact.fields);
    assert.equal(second.contact.fields.at(-1).key, custom);
    assert.equal(second.contact.fields.at(-1).type, "tel");
    assert.equal(
        second.contact.fields.find((field) => field.key === "phone").required,
        true,
    );
    assert.equal(second.contact.prompt, first.contact.prompt);
    assert.deepEqual(
        [first, second].map((question) => question.contact.trigger_values),
        triggers,
    );
    assert.equal(instance.state.questions[2].contact, undefined);
    assert.equal(instance.state.contact, undefined);
    instance.updateCommonContactField("phone", "type", "file");
    assert.equal(
        instance.commonContactFields.find((field) => field.key === "phone")
            .type,
        "tel",
    );
});

test("loading, inspecting, previewing and saving differing legacy contacts never unifies them or changes their keys", async () => {
    const instance = editor();
    const [first, second] = selectBoth(instance);
    second.contact.fields = [
        {
            key: "legacy_phone",
            label: "Mobilais tālrunis",
            type: "tel",
            required: false,
        },
    ];
    second.contact.prompt = "Sazinieties ar mani";
    second.contact.purpose = "Tikai koncertam";
    const original = structuredClone(instance.state);
    assert.equal(instance.contactConfigsDiffer, true);
    assert.equal(instance.canEditCommonContacts, false);
    instance.setCommonContactSource(second.key);
    instance.selectCommonContactField("legacy_phone");
    instance.syncContactEditor();
    instance.togglePreview(true);
    instance.togglePreview(false);
    instance.updateCommonContactField(
        "legacy_phone",
        "label",
        "Neatļauta izmaiņa",
    );
    instance.updateCommonContact("purpose", "Neatļauta izmaiņa");
    instance.addCommonContactField("name");
    instance.removeCommonContactField("legacy_phone");
    instance.openCommonContactPicker();
    assert.equal(instance.contactPicker, null);
    let saved;
    instance.$wire = {
        save: async () => {
            saved = structuredClone(instance.state);
        },
    };
    await instance.save();
    assert.deepEqual(instance.state, original);
    assert.deepEqual(saved, original);
    first.title = "Jauns jautājuma teksts";
    instance.syncContactEditor();
    assert.deepEqual(second.contact, original.questions[1].contact);
    assert.deepEqual(first.contact, original.questions[0].contact);
});

test("explicit legacy unification uses the chosen source and preserves every question's own answer audience", () => {
    const instance = editor();
    const [first, second] = selectBoth(instance);
    second.contact.fields[0].key = "legacy_name";
    second.contact.fields[0].label = "Kā jūs uzrunāt?";
    second.contact.purpose = "Saziņai par koncertu";
    instance.toggleContactTrigger(second, "later_concert", true);
    const expected = structuredClone(second.contact);
    const firstTriggers = [...first.contact.trigger_values];
    instance.setCommonContactSource(second.key);
    instance.unifyContactFields();
    assert.equal(instance.contactConfigsDiffer, false);
    assert.equal(instance.canEditCommonContacts, true);
    assert.deepEqual(first.contact, {
        ...expected,
        trigger_values: firstTriggers,
    });
    assert.deepEqual(second.contact, expected);
    assert.notEqual(first.contact.fields[0], second.contact.fields[0]);
    instance.updateCommonContactField("legacy_name", "label", "Vārds");
    assert.equal(first.contact.fields[0].label, "Vārds");
    assert.equal(second.contact.fields[0].key, "legacy_name");
});

test("the shared canvas caps fields at twelve, removes from all selections, and can rebuild an empty canvas", () => {
    const instance = editor();
    const [first, second] = selectBoth(instance);
    instance.addCommonContactField("name");
    assert.equal(instance.commonContactFields.length, 2);
    while (instance.commonContactFields.length < 12)
        instance.addCommonContactField("custom");
    const keys = instance.commonContactFields.map((field) => field.key);
    assert.equal(new Set(keys).size, 12);
    instance.addCommonContactField("custom");
    instance.openCommonContactPicker();
    assert.deepEqual(
        instance.commonContactFields.map((field) => field.key),
        keys,
    );
    assert.equal(instance.contactPicker, null);
    for (const key of keys) instance.removeCommonContactField(key);
    assert.deepEqual(first.contact.fields, []);
    assert.deepEqual(second.contact.fields, []);
    assert.match(instance.contactIssue(first), /Pievienojiet vismaz vienu/);
    instance.addCommonContactField("phone");
    assert.equal(instance.selectedCommonContactField, "phone");
    assert.deepEqual(first.contact.fields, second.contact.fields);
    assert.equal(instance.contactIssue(first), "");
});

test("question removal and reordering preserve checked identities, common fields and triggers", () => {
    const instance = editor();
    const [first, second] = selectBoth(instance);
    instance.addCommonContactField("phone");
    const expected = structuredClone(first.contact);
    instance.move(second, -1);
    assert.equal(instance.state.questions[0].key, second.key);
    assert.equal(instance.number(first), 2);
    assert.deepEqual(first.contact, expected);
    instance.setCommonContactSource(first.key);
    instance.removeQuestion(first);
    instance.syncContactEditor();
    assert.equal(instance.commonContactQuestion.key, second.key);
    assert.equal(instance.commonContactFields.at(-1).key, "phone");
    instance.toggleQuestionContact(second, false);
    assert.equal(instance.hasQuestionContacts, false);
    assert.equal(instance.commonContactQuestion, null);
    assert.equal(instance.selectedCommonContactField, null);
});

test("deleted triggers remain repairable without silently widening the audience, and text questions retain an explicit removal path", () => {
    const instance = editor();
    const [first, second] = selectBoth(instance);
    const originalFields = structuredClone(first.contact.fields);
    instance.removeOption(
        first,
        first.options.find((option) => option.value === "yes_garden"),
    );
    instance.syncContactEditor();
    assert.deepEqual(first.contact.trigger_values, ["yes_garden"]);
    assert.equal(instance.contactSettingsOpen(first), true);
    assert.match(instance.contactConditionSummary(first), /Trūkstoša atbilde/);
    instance.toggleContactTrigger(first, "yes_garden", false);
    instance.toggleContactTrigger(first, "later_garden", true);
    assert.equal(instance.contactIssue(first), "");
    instance.changeType(second, "text");
    assert.match(instance.contactIssue(second), /tikai izvēles/);
    assert.deepEqual(first.contact.fields, originalFields);
    instance.toggleQuestionContact(second, false);
    assert.equal(second.contact, undefined);
    assert.deepEqual(first.contact.fields, originalFields);
});

test("global respondent mode rejects contact additions and common edits, while both modes retain working removal paths", () => {
    const instance = editor();
    const first = instance.state.questions[0];
    instance.addRespondentBlock();
    instance.toggleQuestionContact(first, true);
    assert.equal(first.contact, undefined);
    instance.removeRespondentBlock();
    instance.toggleQuestionContact(first, true);
    instance.addRespondentBlock();
    assert.equal(instance.allowsIdentifiedResponses, false);
    instance.state.allow_identified_responses = true;
    const original = structuredClone(first.contact);
    instance.updateCommonContactField("name", "label", "Mainīts");
    instance.unifyContactFields();
    assert.deepEqual(first.contact, original);
    instance.toggleQuestionContact(first, false);
    assert.equal(instance.hasQuestionContacts, false);
    assert.equal(instance.allowsIdentifiedResponses, true);
});

test("shared picker keyboard focus and add/remove selection use the single canvas", (context) => {
    const instance = editor();
    selectBoth(instance);
    const harness = focusHarness(instance, context);
    instance.openCommonContactPicker();
    harness.flush();
    assert.equal(harness.focused.at(-1), "editor-contact-picker-shared:input");
    instance.closeCommonContactPicker();
    harness.flush();
    assert.equal(harness.focused.at(-1), "editor-contact-add-field-shared");
    instance.openCommonContactPicker();
    harness.flush();
    instance.addCommonContactField("phone");
    harness.flush();
    assert.equal(harness.focused.at(-1), "editor-contact-label-shared-phone");
    assert.equal(instance.contactPicker, null);
    instance.removeCommonContactField("phone");
    harness.flush();
    assert.equal(harness.focused.at(-1), "editor-contact-label-shared-email");
});

test("numeric contact errors select the correct shared field across multiple questions and expand individual triggers", (context) => {
    const instance = editor();
    const [first, second] = selectBoth(instance);
    const harness = focusHarness(instance, context);
    instance.preview = true;
    instance.receiveErrors({
        "data.builder.questions.1.contact.fields.1.type": [
            "Nepareizs e-pasta veids.",
        ],
        "data.builder.questions.0.contact.fields.0.label": [
            "Norādiet vārda nosaukumu.",
        ],
        "data.builder.questions.0.contact.trigger_values.0": [
            "Izvēlieties atbildi.",
        ],
    });
    harness.flush();
    assert.equal(instance.preview, false);
    assert.equal(instance.commonContactQuestion, second);
    assert.equal(instance.selectedCommonContactField, "email");
    assert.equal(harness.focused.at(-1), "editor-contact-type-shared-email");
    assert.equal(
        instance.commonContactError("type", "email"),
        "Nepareizs e-pasta veids.",
    );
    assert.equal(
        instance.commonContactError("label", "name"),
        "Norādiet vārda nosaukumu.",
    );
    assert.equal(instance.commonContactError("fields"), "");
    assert.match(instance.notice, /2\. jautājuma/);
    assert.equal(instance.contactValidationErrors.length, 3);
    instance.focusContactError(
        first,
        "data.builder.questions.0.contact.trigger_values.0",
    );
    harness.flush();
    assert.equal(instance.contactSettingsOpen(first), true);
    assert.equal(
        harness.focused.at(-1),
        "editor-contact-triggers-garden:input",
    );
    instance.focusContactError(
        first,
        "data.builder.questions.0.contact.fields.0.label",
    );
    harness.flush();
    assert.equal(instance.selectedCommonContactField, "name");
    assert.equal(harness.focused.at(-1), "editor-contact-label-shared-name");
});

test("mixed legacy validation selects the owning schema and field without modifying it", (context) => {
    const instance = editor();
    const [first, second] = selectBoth(instance);
    second.contact.fields = [
        { key: "old_phone", label: "Telefons", type: "tel", required: false },
    ];
    const original = structuredClone(instance.state);
    const harness = focusHarness(instance, context);
    instance.receiveErrors({
        "data.builder.questions.1.contact.fields.0.type": [
            "Nepareizs lauka veids.",
        ],
        "data.builder.questions.0.contact.fields.1.label": [
            "Norādiet nosaukumu.",
        ],
    });
    harness.flush();
    assert.equal(instance.commonContactQuestion, second);
    assert.equal(instance.selectedCommonContactField, "old_phone");
    assert.equal(
        harness.focused.at(-1),
        "editor-contact-label-shared-old_phone",
    );
    assert.match(instance.notice, /Lietot vienādus laukus/);
    assert.deepEqual(instance.state, original);
    instance.focusContactError(
        first,
        "data.builder.questions.0.contact.fields.1.label",
    );
    harness.flush();
    assert.equal(instance.commonContactQuestion, first);
    assert.equal(instance.selectedCommonContactField, "email");
    assert.equal(harness.focused.at(-1), "editor-contact-label-shared-email");
    assert.deepEqual(instance.state, original);
});

test("trigger errors on text questions and conflicting respondent mode focus a working removal control", (context) => {
    const instance = editor();
    const [first] = selectBoth(instance);
    const harness = focusHarness(instance, context);
    instance.changeType(first, "text");
    const triggerPath = "data.builder.questions.0.contact.trigger_values";
    instance.receiveErrors({
        [triggerPath]: ["Izvēlieties izvēles jautājumu."],
    });
    harness.flush();
    assert.equal(harness.focused.at(-1), "editor-contact-remove-garden");
    instance.changeType(first, "single");
    instance.state.allow_identified_responses = true;
    const fieldsPath = "data.builder.questions.0.contact.fields";
    instance.receiveErrors({
        [fieldsPath]: ["Noņemiet vienu no datu blokiem."],
    });
    harness.flush();
    assert.equal(harness.focused.at(-1), "editor-contact-question-garden");
});

test("single end preview keeps consent optional, automatically fills all eligible activities and keeps values outside saved schema", async () => {
    const instance = editor();
    const [first, second] = selectBoth(instance);
    instance.addCommonContactField("phone");
    const original = structuredClone(instance.state);
    instance.answerPreview(first, "yes_garden", true);
    instance.answerPreview(second, "yes_concert", true);
    assert.equal(instance.previewShared.consent, false);
    assert.deepEqual(instance.previewShared.fields, []);
    instance.setSharedPreviewConsent(true);
    const phone = instance.previewShared.fields.find(
        (field) => field.type === "tel",
    );
    instance.setSharedPreviewValue(phone.id, "20000000");
    assert.equal(
        instance.previewSharedController.scatter(first.key).phone,
        "20000000",
    );
    assert.equal(
        instance.previewSharedController.scatter(second.key).phone,
        "20000000",
    );
    let saved;
    instance.$wire = {
        save: async () => {
            saved = structuredClone(instance.state);
        },
    };
    await instance.save();
    assert.deepEqual(saved, original);
    assert.equal(instance.dirty, false);
    instance.setSharedPreviewConsent(false);
    assert.deepEqual(instance.previewShared.fields, []);
    assert.deepEqual(instance.state, original);
});
