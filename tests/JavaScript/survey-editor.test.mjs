import assert from "node:assert/strict";
import test from "node:test";
import surveyEditor from "../../public/js/survey-editor.js";

function editor(questions = [], config = {}) {
    const instance = surveyEditor({
        state: {
            allow_identified_responses: false,
            respondent_fields: [],
            sections: [
                { key: "first", title: "Pirmā" },
                { key: "second", title: "Otrā" },
            ],
            questions,
        },
        ...config,
    });
    instance.focusQuestion = () => {};
    instance.focusRespondentField = () => {};
    instance.focusContactField = () => {};
    instance.$nextTick = () => {};
    return instance;
}

function question(key, section) {
    return {
        key,
        section,
        type: "single",
        title: key,
        options: [{ value: "yes", label: "Jā" }],
        condition: null,
    };
}

function initializeEditor(instance, context) {
    const globals = ["window", "document"].map((key) => [
        key,
        Object.getOwnPropertyDescriptor(globalThis, key),
    ]);
    globalThis.window = { addEventListener() {}, removeEventListener() {} };
    globalThis.document = {};
    context.after(() => {
        for (const [key, descriptor] of globals) {
            if (descriptor) Object.defineProperty(globalThis, key, descriptor);
            else delete globalThis[key];
        }
    });
    const watchers = new Map();
    const ticks = [];
    const flushTicks = () => {
        while (ticks.length) ticks.shift()();
    };
    instance.$watch = (path, callback) => watchers.set(path, callback);
    instance.$nextTick = (callback) => ticks.push(callback);
    instance.resizeAll = () => {};
    instance.init();
    flushTicks();
    return { watchers, flushTicks };
}

function mockCrypto(context, value) {
    const descriptor = Object.getOwnPropertyDescriptor(globalThis, "crypto");
    if (value === undefined) delete globalThis.crypto;
    else
        Object.defineProperty(globalThis, "crypto", {
            configurable: true,
            value,
        });
    context.after(() => {
        if (descriptor) Object.defineProperty(globalThis, "crypto", descriptor);
        else delete globalThis.crypto;
    });
}

function assertEditorId(value, prefix) {
    assert.match(
        value,
        new RegExp(
            "^" + prefix + "_[a-f0-9]{12}4[a-f0-9]{3}[89ab][a-f0-9]{15}$",
        ),
    );
}

test("editor ids prefer native randomUUID and preserve the prefix and compact UUID", (context) => {
    let calls = 0;
    const crypto = {
        randomUUID() {
            assert.equal(this, crypto);
            calls++;
            return "00112233-4455-4677-8899-aabbccddeeff";
        },
        getRandomValues() {
            assert.fail("Native UUIDs do not need the byte fallback");
        },
    };
    mockCrypto(context, crypto);
    const instance = editor();
    for (const prefix of ["q", "o", "s", "detail"]) {
        const id = instance.id(prefix);
        assert.equal(id, prefix + "_00112233445546778899aabbccddeeff");
        assertEditorId(id, prefix);
    }
    assert.equal(calls, 4);
});

test("HTTP ids use getRandomValues when randomUUID is absent and set RFC4122 v4 bits", (context) => {
    const fills = [0x00, 0xff, 0x55, 0xaa];
    const crypto = {
        getRandomValues(bytes) {
            assert.equal(this, crypto);
            assert.ok(bytes instanceof Uint8Array);
            assert.equal(bytes.length, 16);
            bytes.fill(fills.shift());
            return bytes;
        },
    };
    mockCrypto(context, crypto);
    context.mock.method(Math, "random", () =>
        assert.fail("HTTP editor ids must use the available crypto entropy"),
    );
    assert.equal("randomUUID" in globalThis.crypto, false);
    const instance = editor();
    for (const expected of [
        "00000000000040008000000000000000",
        "ffffffffffff4fffbfffffffffffffff",
        "55555555555545559555555555555555",
        "aaaaaaaaaaaa4aaaaaaaaaaaaaaaaaaa",
    ]) {
        const id = instance.id("q");
        assert.equal(id, "q_" + expected);
        assertEditorId(id, "q");
    }
    assert.equal(fills.length, 0);
});

test("built-in contact and respondent presets create no unused custom ids", (context) => {
    mockCrypto(context, {
        getRandomValues() {
            assert.fail("Built-in presets must not request random ids");
        },
    });
    const item = question("existing-question", "first");
    const instance = editor([item]);
    const presets = [
        ["name", "text", true],
        ["role", "role", true],
        ["email", "email", false],
        ["phone", "tel", false],
    ];
    for (const [key, type, required] of presets) {
        const first = instance.respondentFieldPreset(key);
        const second = instance.respondentFieldPreset(key);
        assert.deepEqual(first, second);
        assert.notEqual(first, second);
        assert.equal(first.key, key);
        assert.equal(first.type, type);
        assert.equal(first.required, required);
    }
    assert.equal(instance.respondentFieldPreset("unknown"), undefined);
    instance.addContactBlock(item);
    for (const [key] of presets) instance.addContactField(item, key);
    assert.deepEqual(
        item.contact.fields.map((field) => field.key),
        ["name", "email", "role", "phone"],
    );
    assert.deepEqual(item.contact.trigger_values, ["yes"]);
    assert.equal(item.contact.fields[1].required, true);

    const respondent = editor();
    respondent.addRespondentBlock();
    for (const [key] of presets) respondent.addRespondentField(key);
    assert.deepEqual(
        respondent.respondentFields.map((field) => field.key),
        ["name", "role", "email", "phone"],
    );
});

for (const source of ["HTTP crypto", "no crypto"]) {
    test(`${source} supports editor creation and duplication without rewriting existing keys`, (context) => {
        let calls = 0;
        if (source === "HTTP crypto") {
            mockCrypto(context, {
                getRandomValues(bytes) {
                    bytes.fill(++calls);
                    return bytes;
                },
            });
            context.mock.method(Math, "random", () =>
                assert.fail("Available Web Crypto must supply the randomness"),
            );
        } else {
            mockCrypto(context, undefined);
            assert.equal("crypto" in globalThis, false);
            context.mock.method(Math, "random", () => (++calls % 256) / 256);
        }
        const existing = question("saved-question-key", "first");
        const original = structuredClone(existing);
        const instance = editor([existing]);
        instance.addContactBlock(existing);
        instance.addContactField(existing, "custom");
        instance.addContactField(existing, "custom");
        const customKeys = existing.contact.fields.slice(2).map((field) => {
            assertEditorId(field.key, "detail");
            return field.key;
        });
        assert.notEqual(customKeys[0], customKeys[1]);
        const custom = existing.contact.fields.at(-1);
        instance.updateContactField(existing, custom.key, "label", "Saziņa");
        instance.updateContactField(existing, custom.key, "type", "tel");
        instance.duplicate(existing);
        const copy = instance.state.questions[1];
        assertEditorId(copy.key, "q");
        assert.deepEqual(copy.options, original.options);
        assert.deepEqual(copy.contact, existing.contact);
        assert.notEqual(copy.contact.fields, existing.contact.fields);
        assert.deepEqual(existing.options, original.options);
        assert.equal(existing.key, original.key);
        assert.deepEqual(existing.contact.trigger_values, ["yes"]);
        assert.deepEqual(
            existing.contact.fields.slice(2).map((field) => field.key),
            customKeys,
        );

        instance.addQuestion("first", existing.key);
        const added = instance.state.questions[1];
        assertEditorId(added.key, "q");
        instance.addOption(added);
        for (const option of added.options) assertEditorId(option.value, "o");
        instance.addSection();
        const section = instance.state.sections.at(-1);
        assertEditorId(section.key, "s");
        const sectionQuestion = instance.sectionQuestions(section.key)[0];
        assertEditorId(sectionQuestion.key, "q");
        for (const option of sectionQuestion.options)
            assertEditorId(option.value, "o");
        const text = question("saved-text-key", "first");
        text.type = "text";
        text.options = [];
        instance.state.questions.push(text);
        instance.changeType(text, "single");
        assertEditorId(text.options[0].value, "o");

        const respondent = editor();
        respondent.addRespondentBlock();
        respondent.addRespondentField("custom");
        const detail = respondent.respondentFields.at(-1);
        assertEditorId(detail.key, "detail");
        const key = detail.key;
        respondent.updateRespondentField(key, "label", "Papildinājums");
        assert.equal(detail.key, key);
        const generated = [
            ...customKeys,
            copy.key,
            added.key,
            ...added.options.map((option) => option.value),
            section.key,
            sectionQuestion.key,
            ...sectionQuestion.options.map((option) => option.value),
            text.options[0].value,
            detail.key,
        ];
        assert.equal(new Set(generated).size, generated.length);
    });
}

test("adding to an empty earlier section preserves visible numbering and condition source order", () => {
    const later = question("later", "second");
    const instance = editor([later]);
    instance.addQuestion("first");
    const earlier = instance.state.questions[0];
    assert.equal(earlier.section, "first");
    assert.equal(instance.number(earlier), 1);
    assert.equal(instance.number(later), 2);
    assert.deepEqual(instance.sources(earlier), []);
    assert.deepEqual(
        instance.sources(later).map((source) => source.key),
        [earlier.key],
    );
});

test("moving a condition source behind its dependent marks the rule for repair", () => {
    const source = question("source", "first");
    const dependent = {
        ...question("dependent", "second"),
        condition: { question: "source", values: ["yes"] },
    };
    const instance = editor([source, dependent]);
    source.section = "second";
    instance.move(source, 1);
    assert.ok(instance.ruleIssue(dependent));
    assert.equal(instance.more.dependent, true);
});

test("removing Other removes its exclusive flag and exposes broken dependent conditions", () => {
    const source = {
        ...question("source", "first"),
        allow_other: true,
        options: [{ value: "other", label: "Cits variants" }],
        exclusive_values: ["other"],
    };
    const dependent = {
        ...question("dependent", "second"),
        condition: { question: "source", values: ["other"] },
    };
    const instance = editor([source, dependent]);
    instance.toggleOther(source, false);
    assert.deepEqual(source.exclusive_values, []);
    assert.ok(instance.ruleIssue(dependent));
    assert.equal(instance.more.dependent, true);
});

test("reading configured or legacy respondent fields never rewrites survey JSON", () => {
    const cases = [
        {},
        { allow_identified_responses: false, respondent_fields: [] },
        { allow_identified_responses: true, respondent_fields: null },
        {
            allow_identified_responses: true,
            respondent_fields: [
                {
                    key: "email",
                    label: "Saziņas adrese",
                    type: "email",
                    required: false,
                },
            ],
        },
    ];
    for (const schema of cases) {
        const state = {
            ...schema,
            title: "Vecāku aptauja",
            sections: [{ key: "first", title: "Pirmā" }],
            questions: [question("source", "first")],
        };
        const original = JSON.stringify(state);
        const instance = editor([], { state });
        const expected = schema.allow_identified_responses
            ? schema.respondent_fields || instance.defaultRespondentFields()
            : [];

        assert.deepEqual(instance.respondentFields, expected);
        assert.equal(instance.previewIdentified, false);
        assert.deepEqual(instance.previewRespondent, {
            anonymous: true,
            values: {},
        });
        assert.equal(JSON.stringify(state), original);
    }
});

test("adding and removing the respondent block preserves questions and section order", () => {
    const source = {
        ...question("source", "first"),
        allow_other: true,
        options: [
            { value: "yes", label: "Jā" },
            { value: "other", label: "Cits variants" },
        ],
        exclusive_values: ["other"],
        extra: { retained: true },
    };
    const dependent = {
        ...question("dependent", "second"),
        condition: { question: "source", values: ["yes"] },
    };
    const instance = editor([source, dependent]);
    const original = structuredClone(instance.state);

    instance.addRespondentBlock();

    assert.equal(instance.state.allow_identified_responses, true);
    assert.deepEqual(instance.respondentFields, [
        {
            key: "name",
            label: "Vārds un uzvārds",
            type: "text",
            required: true,
        },
        { key: "role", label: "Loma", type: "role", required: true },
    ]);
    assert.equal(instance.previewRespondent.anonymous, true);
    instance.addRespondentBlock();
    assert.equal(instance.respondentFields.length, 2);
    instance.setPreviewAnonymous(false);
    instance.previewRespondent.values = {
        name: "Pārbaudes Vārds",
        role: "father",
    };
    instance.removeRespondentBlock();

    assert.deepEqual(instance.state, original);
    assert.deepEqual(instance.previewRespondent, {
        anonymous: true,
        values: {},
    });
    assert.equal(instance.previewIdentified, false);
    instance.addRespondentBlock();
    assert.equal(instance.previewRespondent.anonymous, true);
});

test("presets and custom fields keep unique stable keys, labels, types and required flags", () => {
    const instance = editor();
    instance.addRespondentBlock();
    for (const preset of [
        "email",
        "phone",
        "name",
        "role",
        "email",
        "phone",
        "custom",
        "custom",
    ])
        instance.addRespondentField(preset);

    assert.equal(instance.respondentFields.length, 6);
    assert.deepEqual(instance.respondentFields.slice(2, 4), [
        { key: "email", label: "E-pasts", type: "email", required: false },
        { key: "phone", label: "Tālrunis", type: "tel", required: false },
    ]);
    const [first, second] = instance.respondentFields.slice(4);
    assert.match(first.key, /^detail_[a-f0-9]{32}$/);
    assert.notEqual(first.key, second.key);
    const stableKey = first.key;
    instance.updateRespondentField(first.key, "label", "Bērna grupa");
    instance.updateRespondentField(first.key, "required", true);
    instance.updateRespondentField(first.key, "type", "email");
    instance.updateRespondentField(first.key, "key", "changed");
    assert.deepEqual(first, {
        key: stableKey,
        label: "Bērna grupa",
        type: "text",
        required: true,
    });
});

test("respondent field additions stop at 12 and cannot add to an absent block", () => {
    const instance = editor();
    instance.addRespondentField("email");
    assert.deepEqual(instance.respondentFields, []);
    instance.addRespondentBlock();
    for (let index = 0; index < 20; index++)
        instance.addRespondentField("custom");
    assert.equal(instance.respondentFields.length, 12);
    assert.equal(
        new Set(instance.respondentFields.map((field) => field.key)).size,
        12,
    );
});

test("editing a legacy fallback materializes its schema only after an explicit field change", () => {
    const instance = editor();
    instance.state.allow_identified_responses = true;
    instance.state.respondent_fields = null;
    assert.equal(instance.respondentFields.length, 2);
    assert.equal(instance.state.respondent_fields, null);

    instance.updateRespondentField("name", "label", "Jūsu pilnais vārds");
    instance.updateRespondentField("role", "required", false);

    assert.deepEqual(instance.state.respondent_fields, [
        {
            key: "name",
            label: "Jūsu pilnais vārds",
            type: "text",
            required: true,
        },
        { key: "role", label: "Loma", type: "role", required: false },
    ]);
});

test("removing fields discards their preview values and removing the last field removes the block", () => {
    const instance = editor();
    instance.addRespondentBlock();
    instance.setPreviewAnonymous(false);
    instance.previewRespondent.values = {
        name: "Pārbaudes Vārds",
        role: "mother",
    };

    instance.removeRespondentField("name");

    assert.deepEqual(instance.previewRespondent.values, { role: "mother" });
    assert.deepEqual(
        instance.respondentFields.map((field) => field.key),
        ["role"],
    );
    instance.removeRespondentField("role");
    assert.equal(instance.state.allow_identified_responses, false);
    assert.deepEqual(instance.state.respondent_fields, []);
    assert.deepEqual(instance.previewRespondent, {
        anonymous: true,
        values: {},
    });
});

test("generic preview details and answers never enter the saved builder", async () => {
    const instance = editor([question("source", "first")]);
    instance.addRespondentBlock();
    instance.addRespondentField("email");
    instance.addRespondentField("custom");
    const custom = instance.respondentFields.at(-1);
    const original = structuredClone(instance.state);
    let savedState;
    instance.$wire = {
        save: async () => {
            savedState = structuredClone(instance.state);
        },
    };
    instance.setPreviewAnonymous(false);
    instance.previewRespondent.values = {
        name: "Pārbaudes Vārds",
        role: "guardian",
        email: "preview@example.test",
        [custom.key]: "Pārbaudes grupa",
    };
    instance.answerPreview(instance.state.questions[0], "yes", true);

    assert.equal(instance.previewIdentified, true);
    assert.equal(instance.dirty, false);
    await instance.save();
    assert.deepEqual(savedState, original);
    assert.deepEqual(instance.state, original);
    assert.equal(instance.saving, false);
});

test("choosing anonymity clears every preview value while preserving preview answers and save state", () => {
    const instance = editor([question("source", "first")]);
    instance.addRespondentBlock();
    instance.addRespondentField("email");
    instance.addRespondentField("phone");
    instance.addRespondentField("custom");
    instance.setPreviewAnonymous(false);
    instance.previewRespondent.values = Object.fromEntries(
        instance.respondentFields.map((field) => [
            field.key,
            "Pārbaudes vērtība",
        ]),
    );
    instance.answerPreview(instance.state.questions[0], "yes", true);
    const original = structuredClone(instance.state);

    instance.setPreviewAnonymous(true);

    assert.equal(instance.previewIdentified, false);
    assert.deepEqual(instance.previewRespondent, {
        anonymous: true,
        values: {},
    });
    assert.deepEqual(instance.previewAnswers, { source: ["yes"] });
    assert.deepEqual(instance.state, original);
    assert.equal(instance.dirty, false);
    instance.setPreviewAnonymous(false);
    assert.equal(instance.previewIdentified, true);
    assert.deepEqual(instance.previewRespondent.values, {});
});

test("an anonymous-only survey cannot reveal preview identity", () => {
    const instance = editor();
    instance.previewRespondent.values = { name: "Pārbaudes Vārds" };
    instance.setPreviewAnonymous(false);
    assert.equal(instance.previewIdentified, false);
    assert.deepEqual(instance.previewRespondent, {
        anonymous: true,
        values: {},
    });
    assert.equal(instance.dirty, false);
});

test("entangled schema changes clear removed preview data without rewriting incoming state", (context) => {
    const instance = editor();
    instance.addRespondentBlock();
    instance.addRespondentField("email");
    const { watchers } = initializeEditor(instance, context);
    instance.setPreviewAnonymous(false);
    instance.previewRespondent.values = {
        name: "Pārbaudes Vārds",
        role: "father",
        email: "preview@example.test",
    };
    instance.state.respondent_fields = instance.respondentFields.filter(
        (field) => field.key !== "email",
    );
    const incomingState = structuredClone(instance.state);

    watchers.get("state.respondent_fields")();

    assert.deepEqual(instance.previewRespondent.values, {
        name: "Pārbaudes Vārds",
        role: "father",
    });
    assert.deepEqual(instance.state, incomingState);
    instance.state = { ...incomingState, allow_identified_responses: false };
    const disabledState = structuredClone(instance.state);
    watchers.get("state.allow_identified_responses")();
    assert.equal(instance.previewIdentified, false);
    assert.deepEqual(instance.previewRespondent, {
        anonymous: true,
        values: {},
    });
    assert.deepEqual(instance.state, disabledState);
});

test("saving respondent settings settles cleanly and subsequent field changes become unsaved", async (context) => {
    const instance = editor([question("source", "first")]);
    const { watchers, flushTicks } = initializeEditor(instance, context);
    instance.addRespondentBlock();
    watchers.get("state")();
    assert.equal(instance.dirty, true);
    instance.$wire = {
        save: async () => {
            instance.state = structuredClone(instance.state);
            instance.saved();
            watchers.get("state")();
            watchers.get("state.allow_identified_responses")();
            watchers.get("state.respondent_fields")();
        },
    };

    await instance.save();
    flushTicks();

    assert.equal(instance.dirty, false);
    assert.equal(instance.existing, true);
    instance.setPreviewAnonymous(false);
    instance.previewRespondent.values.name = "Pārbaudes Vārds";
    assert.equal(instance.dirty, false);
    instance.updateRespondentField("name", "required", false);
    watchers.get("state")();
    assert.equal(instance.dirty, true);
});

test("disabled schemas stay frozen while their respondent previews remain interactive", async () => {
    for (const enabled of [true, false]) {
        const instance = editor([question("source", "first")]);
        if (enabled) instance.addRespondentBlock();
        instance.disabled = true;
        instance.preview = true;
        const original = structuredClone(instance.state);
        instance.$wire = {
            save: async () => assert.fail("Published surveys must not save"),
        };

        instance.addRespondentBlock();
        instance.removeRespondentBlock();
        instance.addRespondentField("email");
        instance.updateRespondentField("name", "label", "Izmainīts nosaukums");
        instance.updateRespondentField("name", "required", false);
        instance.removeRespondentField("name");
        instance.setPreviewAnonymous(false);

        assert.equal(instance.allowsIdentifiedResponses, enabled);
        assert.equal(instance.previewIdentified, enabled);
        if (enabled) {
            instance.previewRespondent.values = {
                name: "Pārbaudes Vārds",
                role: "mother",
            };
            instance.setPreviewAnonymous(true);
            assert.deepEqual(instance.previewRespondent.values, {});
        }
        await instance.save();
        assert.deepEqual(instance.state, original);
        assert.equal(instance.dirty, false);
    }
});

test("respondent validation errors return to the configurable block", (context) => {
    const instance = editor();
    instance.addRespondentBlock();
    const { flushTicks } = initializeEditor(instance, context);
    const error = "Norādiet lauka nosaukumu.";
    const errors = { "data.builder.respondent_fields.0.label": [error] };
    let focused = false;
    instance.$root = {
        querySelector: (selector) => {
            assert.equal(
                selector,
                "[data-respondent-label], [data-add-respondent]",
            );
            return {
                focus: () => {
                    focused = true;
                },
            };
        },
    };
    instance.preview = true;

    instance.receiveErrors(errors);
    flushTicks();

    assert.equal(instance.preview, false);
    assert.equal(instance.respondentFieldError(0), error);
    assert.equal(instance.respondentFieldError(1), "");
    assert.equal(focused, true);
});

test("contact inspection and preview preserve absent, null, configured and invalid definitions", (context) => {
    const absent = question("absent", "first");
    const empty = { ...question("empty", "first"), contact: null };
    const configured = question("configured", "first");
    const instance = editor([absent, empty, configured]);
    instance.addContactBlock(configured);
    const invalid = structuredClone(configured);
    invalid.key = "invalid";
    invalid.type = "text";
    invalid.contact.trigger_values = ["removed", "other"];
    instance.state.questions.push(invalid);
    const original = JSON.stringify(instance.state);
    initializeEditor(instance, context);

    for (const item of instance.state.questions) {
        instance.contactFields(item);
        instance.contactIssue(item);
        instance.invalidContactTriggers(item);
        instance.previewContactAvailable(item);
        instance.previewContactOptedIn(item);
    }
    instance.togglePreview(true);
    instance.togglePreview(false);

    assert.equal(JSON.stringify(instance.state), original);
    assert.equal(Object.hasOwn(absent, "contact"), false);
    assert.equal(empty.contact, null);
    assert.equal(instance.dirty, false);
    assert.deepEqual(instance.previewContacts, {});
});

test("adding contact uses only the first normal option and the required name/email defaults", () => {
    const item = question("contact", "first");
    item.options.unshift({ value: "other", label: "Cits" });
    item.options.push({ value: "no", label: "Nē" });
    const instance = editor([item]);
    const originalOptions = structuredClone(item.options);
    instance.addContactBlock(item);

    assert.deepEqual(item.contact, {
        prompt: "Atstāt kontaktinformāciju",
        purpose:
            "Kontaktinformācija paredzēta šī jautājuma aktivitātes organizēšanai. Tā netiek piesaistīta citām aptaujas atbildēm.",
        trigger_values: ["yes"],
        fields: [
            {
                key: "name",
                label: "Vārds un uzvārds",
                type: "text",
                required: true,
            },
            { key: "email", label: "E-pasts", type: "email", required: true },
        ],
    });
    assert.deepEqual(item.options, originalOptions);
    const original = structuredClone(instance.state);
    instance.addContactBlock(item);
    assert.deepEqual(instance.state, original);
    assert.equal(instance.previewContactAvailable(item), false);
    assert.equal(instance.previewContactOptedIn(item), false);
    assert.deepEqual(instance.previewContacts, {});
});

test("global respondent and question contact blocks reject additions in both directions", () => {
    const item = question("contact", "first");
    const instance = editor([item]);
    instance.addRespondentBlock();
    let original = structuredClone(instance.state);
    instance.addContactBlock(item);
    assert.deepEqual(instance.state, original);
    assert.equal(instance.hasQuestionContacts, false);
    instance.removeRespondentBlock();
    instance.addContactBlock(item);
    original = structuredClone(instance.state);
    instance.addRespondentBlock();
    assert.deepEqual(instance.state, original);
    assert.equal(instance.hasQuestionContacts, true);
    instance.state.allow_identified_responses = true;
    original = structuredClone(instance.state);
    assert.match(instance.contactIssue(item), /respondenta datu bloks/);
    assert.equal(instance.previewContactAvailable(item), false);
    assert.deepEqual(instance.state, original);
    instance.state.allow_identified_responses = false;
    instance.removeContactBlock(item);
    assert.equal(Object.hasOwn(item, "contact"), false);
    instance.addRespondentBlock();
    assert.equal(instance.allowsIdentifiedResponses, true);
});

test("contact presets, custom fields and edits keep keys stable and stop at twelve", () => {
    const item = question("contact", "first");
    const instance = editor([item]);
    instance.addContactField(item, "name");
    assert.equal(Object.hasOwn(item, "contact"), false);
    instance.addContactBlock(item);
    for (const preset of [
        "name",
        "email",
        "phone",
        "role",
        "phone",
        "custom",
        "custom",
    ])
        instance.addContactField(item, preset);
    assert.equal(instance.contactFields(item).length, 6);
    const custom = item.contact.fields.at(-1);
    const key = custom.key;
    assert.match(key, /^detail_[a-f0-9]{32}$/);
    instance.updateContactField(item, key, "label", "Kā varat palīdzēt?");
    instance.updateContactField(item, key, "required", true);
    instance.updateContactField(item, key, "key", "changed");
    instance.updateContactField(item, key, "type", "role");
    assert.deepEqual(custom, {
        key,
        type: "role",
        label: "Kā varat palīdzēt?",
        required: true,
    });
    instance.updateContact(item, "prompt", "Pieteikties talkai");
    instance.updateContact(item, "purpose", "Sazināsimies par talkas laiku.");
    instance.updateContact(item, "trigger_values", ["other"]);
    assert.equal(item.contact.prompt, "Pieteikties talkai");
    assert.equal(item.contact.purpose, "Sazināsimies par talkas laiku.");
    assert.deepEqual(item.contact.trigger_values, ["yes"]);
    for (let index = 0; index < 20; index++)
        instance.addContactField(item, "custom");
    assert.equal(item.contact.fields.length, 12);
    assert.equal(
        new Set(item.contact.fields.map((field) => field.key)).size,
        12,
    );
    for (const field of [...item.contact.fields])
        instance.removeContactField(item, field.key);
    assert.deepEqual(item.contact.fields, []);
    assert.match(
        instance.contactIssue(item),
        /vismaz vienu kontaktinformācijas lauku/,
    );
    instance.addContactField(item, "phone");
    assert.equal(instance.contactIssue(item), "");
});

test("trigger repair never widens the configured audience and does not accept Other", () => {
    const item = question("contact", "first");
    item.options.push({ value: "no", label: "Nē" });
    const instance = editor([item]);
    instance.addContactBlock(item);
    instance.toggleContactTrigger(item, "other", true);
    instance.toggleContactTrigger(item, "absent", true);
    assert.deepEqual(item.contact.trigger_values, ["yes"]);
    instance.removeOption(item, item.options[0]);
    assert.deepEqual(item.contact.trigger_values, ["yes"]);
    assert.deepEqual(instance.invalidContactTriggers(item), ["yes"]);
    assert.match(instance.contactIssue(item), /izdzēsta atbilde/);
    instance.toggleContactTrigger(item, "yes", false);
    assert.deepEqual(item.contact.trigger_values, []);
    assert.match(instance.contactIssue(item), /vismaz vienu atbildi/);
    instance.toggleContactTrigger(item, "no", true);
    assert.deepEqual(item.contact.trigger_values, ["no"]);
    assert.equal(instance.contactIssue(item), "");
});

test("a text type keeps contact configuration visibly invalid until repaired or removed", () => {
    const item = question("contact", "first");
    const text = { ...question("text", "first"), type: "text" };
    const instance = editor([item, text]);
    instance.addContactBlock(text);
    assert.equal(Object.hasOwn(text, "contact"), false);
    instance.addContactBlock(item);
    const original = structuredClone(item.contact);
    instance.answerPreview(item, "yes", true);
    instance.setPreviewContactOptIn(item, true);
    instance.setPreviewContactValue(item, "name", "Pārbaudes vārds");
    instance.changeType(item, "text");
    assert.deepEqual(item.contact, original);
    assert.match(instance.contactIssue(item), /tikai izvēles jautājumam/);
    assert.match(instance.notice, /Mainiet atbildes veidu/);
    assert.deepEqual(instance.previewContacts, {});
    instance.changeType(item, "multiple");
    assert.equal(instance.contactIssue(item), "");
    assert.equal(instance.notice, "");
    instance.removeContactBlock(item);
    assert.equal(Object.hasOwn(item, "contact"), false);
});

test("contact consent starts unchecked and clearing triggers or opting out permanently clears preview PII", () => {
    const item = {
        ...question("contact", "first"),
        type: "multiple",
        exclusive_values: ["no"],
    };
    item.options.push({ value: "no", label: "Nepiedalīšos" });
    const instance = editor([item]);
    instance.addContactBlock(item);
    instance.setPreviewContactOptIn(item, true);
    assert.deepEqual(instance.previewContacts, {});
    instance.answerPreview(item, "yes", true);
    assert.equal(instance.previewContactAvailable(item), true);
    assert.equal(instance.previewContactOptedIn(item), false);
    instance.setPreviewContactOptIn(item, true);
    instance.setPreviewContactValue(item, "name", "Pārbaudes vārds");
    instance.setPreviewContactValue(item, "email", "contact@example.test");
    instance.setPreviewContactValue(item, "invalid", "Must not be stored");
    assert.equal(instance.previewContactValue(item, "invalid"), "");
    instance.setPreviewContactOptIn(item, false);
    assert.deepEqual(instance.previewContacts, {});
    instance.setPreviewContactOptIn(item, true);
    assert.equal(instance.previewContactValue(item, "name"), "");
    instance.setPreviewContactValue(item, "name", "Pārbaudes vārds");
    instance.answerPreview(item, "no", true);
    assert.equal(instance.previewContactAvailable(item), false);
    assert.deepEqual(instance.previewContacts, {});
    instance.answerPreview(item, "yes", true);
    assert.equal(instance.previewContactOptedIn(item), false);
    instance.setPreviewContactOptIn(item, true);
    instance.setPreviewContactValue(item, "email", "contact@example.test");
    instance.toggleContactTrigger(item, "yes", false);
    assert.deepEqual(instance.previewContacts, {});
    instance.toggleContactTrigger(item, "yes", true);
    assert.equal(instance.previewContactOptedIn(item), false);
});

test("hiding a dependency chain clears contacts in all hidden questions", () => {
    const source = question("source", "first");
    source.options.push({ value: "no", label: "Nē" });
    const middle = {
        ...question("middle", "first"),
        condition: { question: "source", values: ["yes"] },
    };
    const last = {
        ...question("last", "first"),
        condition: { question: "middle", values: ["yes"] },
    };
    const instance = editor([source, middle, last]);
    for (const item of [middle, last]) instance.addContactBlock(item);
    for (const item of [source, middle, last])
        instance.answerPreview(item, "yes", true);
    for (const item of [middle, last]) {
        instance.setPreviewContactOptIn(item, true);
        instance.setPreviewContactValue(item, "name", "Pārbaudes vārds");
    }
    assert.equal(Object.keys(instance.previewContacts).length, 2);
    instance.answerPreview(source, "no", true);
    assert.equal(instance.previewVisible(middle), false);
    assert.equal(instance.previewVisible(last), false);
    assert.deepEqual(instance.previewContacts, {});
    instance.answerPreview(source, "yes", true);
    assert.equal(instance.previewVisible(last), true);
    assert.equal(instance.previewContactOptedIn(middle), false);
    assert.equal(instance.previewContactOptedIn(last), false);
});

test("duplicate contact configuration is independent and never inherits preview consent or PII", () => {
    const item = question("contact", "first");
    const instance = editor([item]);
    instance.addContactBlock(item);
    instance.addContactField(item, "custom");
    instance.answerPreview(item, "yes", true);
    instance.setPreviewContactOptIn(item, true);
    instance.setPreviewContactValue(item, "name", "Pārbaudes vārds");
    instance.duplicate(item);
    const copy = instance.state.questions[1];
    assert.notEqual(copy.key, item.key);
    assert.deepEqual(copy.contact, item.contact);
    assert.notEqual(copy.contact.fields, item.contact.fields);
    assert.deepEqual(
        copy.contact.fields.map((field) => field.key),
        item.contact.fields.map((field) => field.key),
    );
    assert.equal(instance.previewContactOptedIn(copy), false);
    assert.equal(instance.previewContactValue(copy, "name"), "");
    instance.updateContactField(copy, "name", "label", "Jūsu vārds");
    instance.toggleContactTrigger(copy, "yes", false);
    assert.equal(item.contact.fields[0].label, "Vārds un uzvārds");
    assert.deepEqual(item.contact.trigger_values, ["yes"]);
    instance.removeQuestion(item);
    assert.deepEqual(instance.previewContacts, {});
    assert.equal(instance.hasQuestionContacts, true);
    instance.removeQuestion(copy);
    assert.equal(instance.hasQuestionContacts, false);
});

test("contact preview PII and opt-in never enter saved content or mark it dirty", async () => {
    const item = question("contact", "first");
    const other = question("other", "first");
    const instance = editor([item, other]);
    instance.addContactBlock(item);
    instance.addContactBlock(other);
    instance.addContactField(item, "role");
    const original = structuredClone(instance.state);
    instance.answerPreview(item, "yes", true);
    instance.answerPreview(other, "yes", true);
    instance.setPreviewContactOptIn(item, true);
    instance.setPreviewContactOptIn(other, true);
    instance.setPreviewContactValue(item, "name", "Only first contact");
    instance.setPreviewContactValue(item, "email", "first@example.test");
    instance.setPreviewContactValue(item, "role", "guardian");
    instance.setPreviewContactValue(other, "name", "Only second contact");
    assert.equal(
        instance.previewContactValue(item, "name"),
        "Only first contact",
    );
    assert.equal(
        instance.previewContactValue(other, "name"),
        "Only second contact",
    );
    let savedState;
    instance.$wire = {
        save: async () => {
            savedState = structuredClone(instance.state);
        },
    };
    await instance.save();
    assert.deepEqual(savedState, original);
    assert.deepEqual(instance.state, original);
    assert.equal(instance.dirty, false);
    instance.removeContactField(item, "email");
    assert.equal(instance.previewContactValue(item, "email"), "");
    assert.equal(
        instance.previewContactValue(item, "name"),
        "Only first contact",
    );
    instance.removeContactBlock(item);
    assert.equal(instance.previewContactValue(item, "name"), "");
    assert.equal(
        instance.previewContactValue(other, "name"),
        "Only second contact",
    );
});

test("saving blank option labels flags removed contact triggers without widening them", async () => {
    const item = question("contact", "first");
    item.options[0].label = " ";
    item.options.push({ value: "no", label: "Nē" });
    const instance = editor([item]);
    instance.addContactBlock(item);
    instance.answerPreview(item, "yes", true);
    instance.setPreviewContactOptIn(item, true);
    instance.setPreviewContactValue(item, "name", "Pārbaudes vārds");
    instance.$wire = {
        save: async () => {
            assert.match(instance.contactIssue(item), /izdzēsta atbilde/);
        },
    };
    await instance.save();
    assert.deepEqual(item.options, [{ value: "no", label: "Nē" }]);
    assert.deepEqual(item.contact.trigger_values, ["yes"]);
    assert.deepEqual(instance.previewContacts, {});
});

test("incoming schema changes clear contact values without rewriting state", (context) => {
    const item = question("contact", "first");
    const instance = editor([item]);
    instance.addContactBlock(item);
    const { watchers } = initializeEditor(instance, context);
    instance.answerPreview(item, "yes", true);
    instance.setPreviewContactOptIn(item, true);
    instance.setPreviewContactValue(item, "name", "Pārbaudes vārds");
    instance.setPreviewContactValue(item, "email", "contact@example.test");
    item.contact.fields = item.contact.fields.filter(
        (field) => field.key !== "email",
    );
    let incoming = structuredClone(instance.state);
    watchers.get("state")();
    assert.deepEqual(instance.state, incoming);
    assert.equal(instance.previewContactValue(item, "email"), "");
    assert.equal(instance.previewContactValue(item, "name"), "Pārbaudes vārds");
    item.options = [];
    incoming = structuredClone(instance.state);
    watchers.get("state")();
    assert.deepEqual(instance.state, incoming);
    assert.deepEqual(instance.previewContacts, {});
});

test("incoming trigger configuration changes reset consent and clear PII even when the answer still triggers", (context) => {
    const item = question("contact", "first");
    item.options.push({ value: "no", label: "Nē" });
    const instance = editor([item]);
    instance.addContactBlock(item);
    const { watchers } = initializeEditor(instance, context);
    instance.answerPreview(item, "yes", true);

    for (const triggers of [["yes", "no"], ["yes"]]) {
        instance.setPreviewContactOptIn(item, true);
        instance.setPreviewContactValue(item, "name", "Pārbaudes vārds");
        instance.setPreviewContactValue(item, "email", "contact@example.test");
        assert.equal(instance.previewContactOptedIn(item), true);
        item.contact.trigger_values = triggers;
        const incoming = structuredClone(instance.state);

        assert.equal(instance.previewContactAvailable(item), true);
        assert.equal(instance.previewContactOptedIn(item), false);
        watchers.get("state")();

        assert.deepEqual(instance.previewContacts, {});
        assert.deepEqual(instance.state, incoming);
        assert.equal(instance.previewContactValue(item, "name"), "");
        assert.equal(instance.previewContactValue(item, "email"), "");
    }
});

test("disabled contact schemas are frozen while opt-in and preview remain interactive", async () => {
    const item = question("contact", "first");
    const instance = editor([item]);
    instance.addContactBlock(item);
    instance.disabled = true;
    instance.preview = true;
    const original = structuredClone(instance.state);
    instance.$wire = {
        save: async () => assert.fail("Published content cannot save"),
    };
    instance.addContactBlock(item);
    instance.removeContactBlock(item);
    instance.updateContact(item, "prompt", "Izmainīts");
    instance.updateContact(item, "purpose", "Izmainīts");
    instance.addContactField(item, "role");
    instance.removeContactField(item, "name");
    instance.updateContactField(item, "name", "label", "Izmainīts");
    instance.updateContactField(item, "name", "required", false);
    instance.updateContactField(item, "name", "type", "email");
    instance.openContactPicker(item);
    instance.toggleContactTrigger(item, "yes", false);
    instance.removeOption(item, item.options[0]);
    instance.changeType(item, "text");
    instance.duplicate(item);
    instance.removeQuestion(item);
    instance.addRespondentBlock();
    instance.togglePreview(false);
    assert.equal(instance.preview, true);
    instance.answerPreview(item, "yes", true);
    assert.equal(instance.previewContactAvailable(item), true);
    assert.equal(instance.previewContactOptedIn(item), false);
    instance.setPreviewContactOptIn(item, true);
    instance.setPreviewContactValue(item, "name", "Pārbaudes vārds");
    assert.equal(instance.previewContactValue(item, "name"), "Pārbaudes vārds");
    instance.setPreviewContactOptIn(item, false);
    assert.deepEqual(instance.previewContacts, {});
    await instance.save();
    assert.deepEqual(instance.state, original);
    assert.equal(instance.dirty, false);
});

test("nested contact errors return to the owning question editor", () => {
    const first = question("first", "first");
    const item = question("contact", "first");
    const instance = editor([first, item]);
    instance.addContactBlock(item);
    let focused;
    instance.focusQuestion = (key) => {
        focused = key;
    };
    instance.preview = true;
    instance.receiveErrors({
        "data.builder.questions.1.contact.fields.0.label": [
            "Norādiet lauka nosaukumu.",
        ],
    });
    assert.equal(instance.preview, false);
    assert.equal(instance.more.contact, true);
    assert.equal(focused, "contact");
    assert.equal(
        instance.fieldError(item, "contact.fields.0"),
        "Norādiet lauka nosaukumu.",
    );
    assert.equal(instance.fieldError(first, "contact"), "");
});

test("contact selection, picker and settings are editor-only and do not change saved content", async (context) => {
    const first = question("contact", "first");
    const second = question("another", "first");
    const instance = editor([first, second]);
    instance.addContactBlock(first);
    instance.addContactBlock(second);
    initializeEditor(instance, context);
    const original = structuredClone(instance.state);
    instance.selectContactField(first, "email");
    instance.selectContactField(second, "name");
    instance.selectContactField(first, "not-a-field");
    assert.deepEqual(instance.selectedContactFields, {
        contact: "email",
        another: "name",
    });
    instance.openContactPicker(first);
    assert.equal(instance.contactPicker, "contact");
    instance.openContactPicker(second);
    assert.equal(instance.contactPicker, "another");
    instance.closeContactPicker(first);
    assert.equal(instance.contactPicker, "another");
    instance.closeContactPicker(second, false);
    instance.toggleContactSettings(first);
    assert.equal(instance.contactSettingsOpen(first), true);
    assert.equal(instance.contactSettingsOpen(second), false);
    instance.toggleContactSettings(first);
    assert.equal(instance.contactSettingsOpen(first), false);
    assert.equal(instance.dirty, false);
    let saved;
    instance.$wire = {
        save: async () => {
            saved = structuredClone(instance.state);
        },
    };
    await instance.save();
    assert.deepEqual(instance.state, original);
    assert.deepEqual(saved, original);
    instance.togglePreview(true);
    assert.equal(instance.contactPicker, null);
    assert.deepEqual(instance.state, original);
});

test("contact picker focuses an available preset and cancel restores the add button", (context) => {
    const item = question("contact", "first");
    const instance = editor([item]);
    instance.addContactBlock(item);
    const { flushTicks } = initializeEditor(instance, context);
    const focused = [];
    globalThis.document.getElementById = (id) => ({
        querySelector: (selector) => {
            assert.equal(selector, "button:not(:disabled)");
            return { focus: () => focused.push(id + ":preset") };
        },
        focus: () => focused.push(id),
    });
    instance.openContactPicker(item);
    flushTicks();
    assert.deepEqual(focused, ["editor-contact-picker-contact:preset"]);
    instance.closeContactPicker(item);
    flushTicks();
    assert.equal(focused.at(-1), "editor-contact-add-field-contact");
    instance.openContactPicker(item);
    flushTicks();
    focused.length = 0;
    instance.closeContactPicker(item, false);
    flushTicks();
    assert.deepEqual(focused, []);
});

test("adding selects the new contact label, removal selects its neighbour, and the last field leaves an editable empty block", () => {
    const item = question("contact", "first");
    const instance = editor([item]);
    const focused = [];
    instance.focusContactField = (_, key) => focused.push(key);
    instance.addContactBlock(item);
    instance.openContactPicker(item);
    instance.addContactField(item, "phone");
    assert.equal(instance.contactPicker, null);
    assert.equal(instance.selectedContactFields.contact, "phone");
    assert.equal(focused.at(-1), "phone");
    instance.removeContactField(item, "phone");
    assert.equal(instance.selectedContactFields.contact, "email");
    assert.equal(focused.at(-1), "email");
    instance.removeContactField(item, "email");
    assert.equal(instance.selectedContactFields.contact, "name");
    instance.removeContactField(item, "name");
    assert.equal(instance.selectedContactFields.contact, undefined);
    assert.equal(focused.at(-1), undefined);
    assert.deepEqual(item.contact.fields, []);
    assert.match(instance.contactIssue(item), /Pievienojiet vismaz vienu/);
    instance.openContactPicker(item);
    instance.addContactField(item, "custom");
    assert.equal(
        instance.selectedContactFields.contact,
        item.contact.fields[0].key,
    );
    assert.equal(instance.contactIssue(item), "");
    while (item.contact.fields.length < 12)
        instance.addContactField(item, "custom");
    instance.openContactPicker(item);
    assert.equal(instance.contactPicker, null);
    const selected = instance.selectedContactFields.contact;
    const keys = item.contact.fields.map((field) => field.key);
    instance.addContactField(item, "custom");
    assert.deepEqual(
        item.contact.fields.map((field) => field.key),
        keys,
    );
    assert.equal(instance.selectedContactFields.contact, selected);
});

test("contact type edits preserve identity and labels, reject unsupported types and clear only the changed preview answer", () => {
    const item = question("contact", "first");
    const instance = editor([item]);
    instance.addContactBlock(item);
    instance.answerPreview(item, "yes", true);
    instance.setPreviewContactOptIn(item, true);
    instance.setPreviewContactValue(item, "name", "Pārbaudes vārds");
    instance.setPreviewContactValue(item, "email", "test@example.test");
    instance.updateContactField(item, "name", "type", "text");
    assert.equal(instance.previewContactValue(item, "name"), "Pārbaudes vārds");
    for (const type of ["email", "tel", "role", "text"]) {
        instance.updateContactField(item, "name", "type", type);
        assert.deepEqual(item.contact.fields[0], {
            key: "name",
            label: "Vārds un uzvārds",
            type,
            required: true,
        });
        assert.equal(instance.previewContactValue(item, "name"), "");
        assert.equal(
            instance.previewContactValue(item, "email"),
            "test@example.test",
        );
        instance.setPreviewContactValue(item, "name", "Test value");
    }
    for (const invalid of ["file", "number", "", null])
        instance.updateContactField(item, "name", "type", invalid);
    assert.equal(item.contact.fields[0].type, "text");
    assert.equal(instance.previewContactValue(item, "name"), "Test value");
});

test("contact validation reveals and focuses the selected field or relevant block settings", (context) => {
    const item = question("contact", "first");
    const second = question("second", "first");
    const instance = editor([item, second]);
    instance.addContactBlock(item);
    instance.addContactBlock(second);
    const { flushTicks } = initializeEditor(instance, context);
    const focused = [];
    globalThis.document.getElementById = (id) => ({
        matches: (selector) =>
            selector === "fieldset" && id.includes("triggers-"),
        querySelector: () => ({ focus: () => focused.push(id + ":input") }),
        focus: () => focused.push(id),
    });
    for (const [suffix, target] of [
        ["fields.1.label", "label-shared-email"],
        ["fields.1.type", "type-shared-email"],
        ["fields.1.required", "required-shared-email"],
        ["fields", "add-field-shared"],
        ["purpose", "purpose-shared"],
        ["prompt", "prompt-shared"],
        ["trigger_values.0", "triggers-contact:input"],
        ["", "settings-toggle-shared"],
    ]) {
        instance.preview = true;
        instance.contactSettings.contact = false;
        instance.openContactPicker(item);
        flushTicks();
        instance.receiveErrors({
            ["data.builder.questions.0.contact" + (suffix ? "." + suffix : "")]:
                ["Pārbaudiet šo lauku."],
            "data.builder.questions.1.contact.fields.0.label": [
                "Norādiet nosaukumu.",
            ],
        });
        flushTicks();
        assert.equal(instance.preview, false);
        assert.equal(instance.contactPicker, null);
        assert.equal(focused.at(-1), "editor-contact-" + target);
        assert.equal(instance.selectedContactFields.second, "name");
        if (suffix.startsWith("fields.1"))
            assert.equal(instance.selectedContactFields.contact, "email");
        if (!suffix.startsWith("fields"))
            assert.equal(instance.contactSettingsOpen(item), true);
    }
});

test("deleted and incoming contact triggers reveal repairable settings without changing schema", (context) => {
    const item = question("contact", "first");
    item.options.push({ value: "no", label: "Nē" });
    const instance = editor([item]);
    instance.addContactBlock(item);
    const { watchers } = initializeEditor(instance, context);
    assert.equal(instance.contactSettingsOpen(item), false);
    assert.equal(
        instance.contactConditionSummary(item),
        "Rādīt, ja izvēlēta: “Jā”.",
    );
    instance.removeOption(item, item.options[0]);
    const original = structuredClone(instance.state);
    watchers.get("state")();
    assert.equal(instance.contactSettingsOpen(item), true);
    assert.match(instance.contactConditionSummary(item), /Trūkstoša atbilde/);
    assert.deepEqual(item.contact.trigger_values, ["yes"]);
    assert.deepEqual(instance.state, original);
    instance.toggleContactSettings(item);
    assert.equal(instance.contactSettingsOpen(item), false);
    watchers.get("state")();
    assert.equal(instance.contactSettingsOpen(item), false);
    item.contact.trigger_values = [];
    const incoming = structuredClone(instance.state);
    watchers.get("state")();
    assert.equal(instance.contactSettingsOpen(item), true);
    assert.match(instance.contactConditionSummary(item), /Izvēlieties atbildi/);
    assert.deepEqual(instance.state, incoming);
    instance.toggleContactTrigger(item, "no", true);
    assert.equal(
        instance.contactConditionSummary(item),
        "Rādīt, ja izvēlēta: “Nē”.",
    );
});
