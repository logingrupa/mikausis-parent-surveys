import assert from "node:assert/strict";
import test from "node:test";
import "../../public/js/shared-question-contacts.js";

const { create } = globalThis.MikausisSharedContacts;
const field = (
    key = "email",
    label = "E-pasts",
    type = "email",
    extra = {},
) => ({ key, label, type, required: false, ...extra });
const definition = (key, fields = [field()], extra = {}) => ({
    key,
    title: "Aktivitāte " + key,
    prompt: "Sazinieties ar mani",
    purpose: "Organizēšanai",
    triggerValues: ["yes", "maybe"],
    fields,
    ...extra,
});
const selection = (key, values = ["yes"], visible = true) => ({
    key,
    values,
    visible,
});
function opted(definitions = [definition("a"), definition("b")]) {
    const state = create(definitions);
    state.syncSelections(definitions.map(({ key }) => selection(key)));
    state.setGlobalConsent(true);
    return state;
}
const activity = (state, key) =>
    state.activities().find((row) => row.key === key);
const fill = (state, value = "parent@example.test") =>
    state.setValue(state.fields()[0].id, value);
const prepare = (state, key = "a") =>
    state.prepare(key, { contactId: "server-id-" + key, multiple: true });

test("answers and typing cannot imply consent; explicit global opt-in includes only current triggers", () => {
    const state = create([definition("a"), definition("b"), definition("c")]);
    state.syncSelections([
        selection("a"),
        selection("b", ["unrelated"]),
        selection("c", ["yes"], false),
    ]);
    assert.equal(state.globalConsent, false);
    assert.equal(state.setIncluded, undefined);
    assert.ok(
        state.activities().every((row) => !Object.hasOwn(row, "needsConsent")),
    );
    assert.deepEqual(state.fields(), []);
    assert.equal(state.setValue('["e-pasts","email"]', "secret"), false);
    assert.equal(prepare(state), null);
    state.setGlobalConsent(true);
    assert.equal(activity(state, "a").included, true);
    assert.equal(activity(state, "b").included, false);
    assert.equal(activity(state, "c").included, false);
});

for (const [name, before, after] of [
    ["trigger replacement", [selection("b")], [selection("b", ["maybe"])]],
    ["new eligible activity", [selection("b", [])], [selection("b")]],
    ["ineligible activity", [selection("b")], [selection("b", ["unrelated"])]],
    ["hidden activity", [selection("b")], [selection("b", ["yes"], false)]],
    ["revealed activity", [selection("b", ["yes"], false)], [selection("b")]],
    ["removed selection", [selection("b")], []],
]) {
    test(
        name + " resets global consent and all unsent PII until a fresh opt-in",
        () => {
            const state = create([definition("a"), definition("b")]);
            state.syncSelections([selection("a"), ...before]);
            state.setGlobalConsent(true);
            fill(state);
            const id = state.fields()[0].id;
            state.syncSelections([selection("a"), ...after]);
            assert.equal(state.globalConsent, false);
            assert.ok(state.activities().every((row) => !row.included));
            assert.deepEqual(state.fields(), []);
            assert.equal(state.value(id), "");
            assert.equal(prepare(state), null);
            state.setGlobalConsent(true);
            assert.ok(
                state.activities().every((row) => row.included === row.active),
            );
            assert.ok(state.fields().every((field) => field.value === ""));
        },
    );
}

test("unrelated choices and reordered trigger sets preserve consent", () => {
    const state = create([definition("a"), definition("b")]);
    state.syncSelections([selection("a", ["yes", "maybe"]), selection("b")]);
    state.setGlobalConsent(true);
    fill(state);
    state.syncSelections([
        selection("b"),
        selection("a", ["maybe", "unrelated", "yes"]),
    ]);
    state.syncDefinitions([
        definition("b", [field()], { triggerValues: ["maybe", "yes"] }),
        definition("a", [field()], { triggerValues: ["maybe", "yes", "yes"] }),
    ]);
    assert.equal(state.globalConsent, true);
    assert.equal(activity(state, "a").included, true);
    assert.equal(state.fields()[0].value, "parent@example.test");
    assert.deepEqual(prepare(state).answer, ["yes", "maybe"]);
});

test("normalized label and type merge independent keys, and scatter whitelists each question", () => {
    const state = opted([
        definition("a", [
            field("detail_a", "  Vecāka   E-PASTS  "),
            field("phone", "Tālrunis", "tel"),
        ]),
        definition("b", [
            field("detail_b", "vecāka e-pasts"),
            field("name", "Bērna vārds", "text"),
        ]),
    ]);
    assert.equal(state.fields().length, 3);
    assert.deepEqual(state.fields()[0].sources, [
        { questionKey: "a", fieldKey: "detail_a" },
        { questionKey: "b", fieldKey: "detail_b" },
    ]);
    fill(state, "  parent@example.test  ");
    assert.deepEqual(state.scatter("a"), {
        detail_a: "parent@example.test",
        phone: "",
    });
    assert.deepEqual(state.scatter("b"), {
        detail_b: "parent@example.test",
        name: "",
    });
    assert.deepEqual(state.scatter("unknown"), {});
});

test("same key with different labels and same label with different types never merge", () => {
    const state = opted([
        definition("a", [field("name", "Vecāka vārds", "text")]),
        definition("b", [
            field("name", "Bērna vārds", "text"),
            field("phone", "Vecāka vārds", "tel"),
        ]),
    ]);
    assert.equal(state.fields().length, 3);
    for (const value of state.fields()) state.setValue(value.id, value.label);
    assert.equal(state.scatter("a").name, "Vecāka vārds");
    assert.equal(state.scatter("b").name, "Bērna vārds");
});

test("required is the union; maxlength uses minimum, bounded by server limits", () => {
    const state = opted([
        definition("a", [
            field("email", "E-pasts", "email", { maxLength: 999 }),
        ]),
        definition("b", [
            field("custom", "e-pasts", "email", {
                required: true,
                maxLength: 80,
            }),
        ]),
    ]);
    assert.equal(state.fields()[0].required, true);
    assert.equal(state.fields()[0].maxLength, 80);
    assert.ok(state.validate().some((error) => error.code === "required"));
    state.syncSelections([selection("a"), selection("b", [])]);
    state.setGlobalConsent(true);
    assert.equal(state.fields()[0].required, false);
    assert.equal(state.fields()[0].maxLength, 254);
    fill(state, "a".repeat(250) + "@x.test");
    assert.ok(state.validate().some((error) => error.code === "maxLength"));
});

test("role, email, optional empty and per-question at-least-one requirements are validated", () => {
    const state = opted([
        definition("a", [field("role", "Loma", "role", { required: true })]),
        definition("b", [field()]),
    ]);
    assert.deepEqual(
        new Set(state.validate().map((error) => error.code)),
        new Set(["required", "empty"]),
    );
    state.setValue(state.fields()[0].id, "invalid");
    state.setValue(state.fields()[1].id, "not-email");
    assert.deepEqual(
        new Set(state.validate().map((error) => error.code)),
        new Set(["role", "email"]),
    );
    state.setValue(state.fields()[0].id, "guardian");
    state.setValue(state.fields()[1].id, "parent@example.test");
    assert.deepEqual(state.validate(), []);
});

test("global off clears all PII; fresh opt-in starts empty with all current activities", () => {
    const state = opted([
        definition("a", [field(), field("phone", "Tālrunis", "tel")]),
        definition("b"),
    ]);
    const fields = state.fields();
    for (const item of fields) state.setValue(item.id, "private");
    state.setGlobalConsent(false);
    assert.deepEqual(state.fields(), []);
    assert.ok(state.activities().every((row) => !row.included));
    for (const item of fields) assert.equal(state.value(item.id), "");
    state.setGlobalConsent(true);
    assert.ok(state.activities().every((row) => row.included));
    assert.ok(state.fields().every((item) => item.value === ""));
});

test("removing an eligible question clears its unique PII and all shared unsent values", () => {
    const state = opted([
        definition("a", [field(), field("name", "Bērna vārds", "text")]),
        definition("b"),
    ]);
    fill(state);
    const unique = state.fields()[1].id;
    state.setValue(unique, "Child");
    state.syncSelections([selection("a", []), selection("b")]);
    assert.equal(state.value(unique), "");
    assert.deepEqual(state.fields(), []);
    state.setGlobalConsent(true);
    assert.equal(state.fields()[0].value, "");
    assert.deepEqual(state.scatter("a"), {});
    assert.equal(state.value(unique), "");
});

test("payloads preserve independent server IDs, only triggering answers and configured fields", () => {
    const state = opted();
    state.syncSelections([
        selection("a", ["yes", "unrelated"]),
        selection("b", ["yes", "maybe"]),
    ]);
    state.setGlobalConsent(true);
    fill(state);
    const a = state.prepare("a", { contactId: "uuid-a", multiple: false });
    const b = state.prepare("b", { contactId: "uuid-b", multiple: true });
    assert.deepEqual(a, {
        contact_id: "uuid-a",
        consent: true,
        answer: "yes",
        fields: { email: "parent@example.test" },
    });
    assert.deepEqual(b, {
        contact_id: "uuid-b",
        consent: true,
        answer: ["yes", "maybe"],
        fields: { email: "parent@example.test" },
    });
    assert.equal(state.locked, true);
});

test("pending snapshots are deeply immutable and freeze every editing route", () => {
    const state = opted();
    fill(state);
    const payload = prepare(state);
    const before = JSON.stringify(payload);
    assert.throws(() => {
        payload.fields.email = "changed";
    }, TypeError);
    assert.throws(() => {
        payload.answer.push("maybe");
    }, TypeError);
    assert.throws(() => {
        payload.contact_id = "changed";
    }, TypeError);
    assert.equal(state.setValue(state.fields()[0].id, "changed"), false);
    assert.equal(state.setGlobalConsent(false), false);
    assert.equal(state.syncSelections([]), false);
    assert.equal(state.syncDefinitions([]), false);
    state.settle("a", "ambiguous");
    assert.equal(prepare(state), payload);
    assert.equal(JSON.stringify(prepare(state)), before);
});

test("a first definite 422 permits correction; an ambiguous retry rejection cannot prove no prior write", () => {
    const state = opted();
    fill(state);
    prepare(state);
    assert.equal(state.settle("a", "rejected"), true);
    assert.equal(state.locked, false);
    fill(state, "corrected@example.test");
    const corrected = prepare(state);
    assert.equal(corrected.fields.email, "corrected@example.test");
    state.settle("a", "ambiguous");
    assert.equal(state.settle("a", "rejected"), false);
    assert.equal(state.locked, true);
    assert.equal(prepare(state), corrected);
    state.settle("a", "saved");
    assert.equal(state.locked, false);
});

test("partial success never resends or edits saved activities; rejected activities remain editable", () => {
    const state = opted();
    fill(state);
    const a = prepare(state, "a");
    const b = prepare(state, "b");
    state.settle("a", "saved");
    assert.equal(state.locked, true);
    state.settle("b", "rejected");
    assert.equal(state.locked, false);
    fill(state, "corrected@example.test");
    assert.equal(prepare(state, "a"), null);
    assert.deepEqual(state.scatter("a"), {});
    assert.equal(a.fields.email, "parent@example.test");
    assert.equal(b.fields.email, "parent@example.test");
    assert.equal(prepare(state, "b").fields.email, "corrected@example.test");
    state.settle("b", "saved");
    assert.deepEqual(state.fields(), []);
    assert.equal(state.value('["e-pasts","email"]'), "");
});

test("input definitions and returned snapshots cannot mutate helper state", () => {
    const definitions = [definition("a")];
    const state = opted(definitions);
    definitions[0].fields[0].label = "Changed";
    state.activities()[0].included = false;
    state.fields()[0].sources[0].fieldKey = "wrong";
    fill(state);
    assert.equal(state.fields()[0].label, "E-pasts");
    assert.deepEqual(state.scatter("a"), { email: "parent@example.test" });
});

test("relevant definition edits reset global consent and clear all unsent values", () => {
    const definitions = [definition("a"), definition("b")];
    const state = opted(definitions);
    fill(state);
    state.syncDefinitions([
        definition("a", [field()], { purpose: "Changed purpose" }),
        definition("b"),
        definition("c"),
    ]);
    state.syncSelections([selection("a"), selection("b"), selection("c")]);
    assert.equal(activity(state, "a").included, false);
    assert.equal(activity(state, "b").included, false);
    assert.equal(activity(state, "c").included, false);
    assert.equal(state.globalConsent, false);
    assert.deepEqual(state.fields(), []);
    state.setGlobalConsent(true);
    assert.ok(state.activities().every((row) => row.included));
    assert.ok(state.fields().every((field) => field.value === ""));
});

for (const [name, changed] of [
    ["title", definition("a", [field()], { title: "Other activity" })],
    ["prompt", definition("a", [field()], { prompt: "Other prompt" })],
    ["purpose", definition("a", [field()], { purpose: "Other purpose" })],
    ["trigger scope", definition("a", [field()], { triggerValues: ["yes"] })],
    ["field key", definition("a", [field("new_email")])],
    ["field label", definition("a", [field("email", "Other e-mail")])],
    ["field type", definition("a", [field("custom_email", "E-pasts", "text")])],
    [
        "required field",
        definition("a", [
            field("email", "E-pasts", "email", { required: true }),
        ]),
    ],
    [
        "field limit",
        definition("a", [
            field("email", "E-pasts", "email", { maxLength: 80 }),
        ]),
    ],
    ["removed activity", null],
]) {
    test(
        "active definition change to " +
            name +
            " requires fresh global consent",
        () => {
            const state =
                name === "field type"
                    ? opted([
                          definition("a", [field("custom_email")]),
                          definition("b"),
                      ])
                    : opted();
            fill(state);
            state.syncDefinitions([
                ...(changed ? [changed] : []),
                definition("b"),
            ]);
            assert.equal(state.globalConsent, false);
            assert.deepEqual(state.fields(), []);
            assert.equal(state.value('["e-pasts","email"]'), "");
            state.setGlobalConsent(true);
            assert.ok(
                state.activities().every((row) => row.included === row.active),
            );
            assert.ok(state.fields().every((field) => field.value === ""));
        },
    );
}

test("definition changes confined to inactive rows preserve the current scope and values", () => {
    const state = create([definition("a"), definition("b")]);
    state.syncSelections([selection("a"), selection("b", [])]);
    state.setGlobalConsent(true);
    fill(state);
    state.syncDefinitions([
        definition("a"),
        definition("b", [field("different")], { purpose: "Different purpose" }),
        definition("c"),
    ]);
    state.syncSelections([selection("a"), selection("b", ["unrelated"])]);
    assert.equal(state.globalConsent, true);
    assert.equal(state.fields()[0].value, "parent@example.test");
    state.syncSelections([
        selection("a"),
        selection("b", ["unrelated"]),
        selection("c"),
    ]);
    assert.equal(state.globalConsent, false);
    assert.deepEqual(state.fields(), []);
});

test("saved identities remain skipped when definitions disappear and return", () => {
    const state = opted();
    fill(state);
    prepare(state, "a");
    state.settle("a", "saved");
    state.syncDefinitions([definition("b")]);
    state.syncDefinitions([definition("a"), definition("b")]);
    state.syncSelections([selection("a", ["maybe"]), selection("b")]);
    assert.equal(activity(state, "a").saved, true);
    assert.equal(prepare(state, "a"), null);
    assert.equal(state.globalConsent, true);
    assert.equal(state.fields()[0].value, "parent@example.test");
});

test("one ambiguous request keeps all edits locked while another initial rejection awaits correction", () => {
    const state = opted();
    fill(state);
    const a = prepare(state, "a");
    prepare(state, "b");
    state.settle("a", "ambiguous");
    state.settle("b", "rejected");
    const before = JSON.stringify(state.activities());
    assert.equal(state.setGlobalConsent(false), false);
    assert.equal(
        state.setValue(state.fields()[0].id, "changed@example.test"),
        false,
    );
    assert.equal(state.syncSelections([selection("b")]), false);
    assert.equal(state.syncDefinitions([definition("b")]), false);
    assert.equal(JSON.stringify(state.activities()), before);
    assert.equal(prepare(state, "a"), a);
    assert.equal(state.settle("a", "rejected"), false);
    state.settle("a", "saved");
    assert.equal(state.locked, false);
    fill(state, "corrected@example.test");
    assert.equal(prepare(state, "a"), null);
    assert.equal(prepare(state, "b").fields.email, "corrected@example.test");
});

test("prototype-like keys and labels remain plain whitelisted data", () => {
    const state = opted([
        definition("__proto__", [
            field("__proto__", "constructor", "text"),
            field("constructor", "__proto__", "text"),
        ]),
    ]);
    state.setValue(state.fields()[0].id, "one");
    state.setValue(state.fields()[1].id, "two");
    const values = state.scatter("__proto__");
    assert.deepEqual(Object.keys(values), ["__proto__", "constructor"]);
    assert.equal(values.__proto__, "one");
    assert.equal(values.constructor, "two");
    assert.equal(Object.getPrototypeOf(values), Object.prototype);
});

test("invalid unsupported field types are excluded and duplicate question keys fail closed", () => {
    const state = opted([
        definition("a", [field("unsafe", "Unsafe", "password"), field()]),
    ]);
    assert.equal(state.fields().length, 1);
    assert.throws(
        () => create([definition("a"), definition("a")]),
        /Duplicate/,
    );
});

test("one opt-in automatically distributes one entry to all five independent activity schemas", () => {
    const definitions = Array.from({ length: 5 }, (_, index) =>
        definition("activity-" + index, [
            field("detail_" + index, index % 2 ? "  E-PASTS " : "E-pasts"),
        ]),
    );
    const state = opted(definitions);
    assert.equal(state.fields().length, 1);
    fill(state);
    assert.equal(state.fields()[0].sources.length, 5);
    assert.ok(state.activities().every((row) => row.included));
    const requests = definitions
        .map(({ key }) => prepare(state, key))
        .filter(Boolean);
    assert.equal(requests.length, 5);
    assert.equal(
        new Set(requests.map((payload) => payload.contact_id)).size,
        5,
    );
    assert.ok(
        requests.every(
            (payload) =>
                Object.values(payload.fields)[0] === "parent@example.test",
        ),
    );
    assert.ok(
        requests.every((payload) => Object.keys(payload.fields).length === 1),
    );
});

test("text length counts Unicode characters as the server does", () => {
    const state = opted([
        definition("a", [field("name", "Vārds", "text", { maxLength: 3 })]),
    ]);
    fill(state, "Ā😀ē");
    assert.deepEqual(state.validate(), []);
    fill(state, "Ā😀ēī");
    assert.ok(state.validate().some((error) => error.code === "maxLength"));
});

test("same-label fields of different types get localized display hints without changing labels or payloads", () => {
    const state = opted([
        definition("a", [
            field("email", "Papildu saziņa", "email"),
            field("note", "Papildu saziņa", "text"),
            field("phone", "Papildu saziņa", "tel"),
            field("role", "Papildu saziņa", "role"),
        ]),
        definition("b", [field("other_email", "  PAPILDU   SAZIŅA ", "email")]),
    ]);
    assert.deepEqual(
        state.fields().map((item) => item.displayLabel),
        [
            "Papildu saziņa (e-pasts)",
            "Papildu saziņa (īss teksts)",
            "Papildu saziņa (tālrunis)",
            "Papildu saziņa (loma)",
        ],
    );
    assert.ok(state.fields().every((item) => item.label === "Papildu saziņa"));
    fill(state);
    assert.equal(state.scatter("a").email, "parent@example.test");
    assert.deepEqual(state.scatter("b"), {
        other_email: "parent@example.test",
    });
    state.syncSelections([selection("a", []), selection("b")]);
    state.setGlobalConsent(true);
    assert.equal(state.fields()[0].label, "PAPILDU   SAZIŅA");
    assert.equal(
        state.fields()[0].displayLabel,
        undefined,
        "No hint when only one type remains",
    );
});

test("built-in field keys keep canonical types without mutating the input definitions", () => {
    const fields = [
        field("name", "Vārds", "email"),
        field("email", "E-pasts", "text"),
        field("phone", "Tālrunis", "text"),
        field("role", "Loma", "tel"),
    ];
    const state = opted([definition("a", fields)]);
    assert.deepEqual(
        state.fields().map(({ type }) => type),
        ["text", "email", "tel", "role"],
    );
    assert.deepEqual(
        fields.map(({ type }) => type),
        ["email", "text", "text", "tel"],
    );
});

test("phone validation accepts Latvian local and international numbers with ASCII separators", () => {
    const state = opted([
        definition("a", [field("phone", "Tālrunis", "text")]),
    ]);
    for (const value of [
        "20000000",
        "60000000",
        "+371 20000000",
        "00371 (6000)-0000",
        "+44 20 7946 0958",
        "00442079460958",
        "+1234567",
        "+123456789012345",
    ]) {
        fill(state, value);
        assert.deepEqual(state.validate(), [], value);
    }
    for (const value of [
        "80000000",
        "37120000000",
        "+37180000000",
        "0037180000000",
        "++37120000000",
        "+371+20000000",
        "+0000000",
        "+123456",
        "+1234567890123456",
        "+371.20000000",
        "+371\t20000000",
        "+371\n20000000",
        "+371\u00a020000000",
        "not-a-phone",
    ]) {
        fill(state, value);
        assert.ok(
            state.validate().some(({ code }) => code === "phone"),
            value,
        );
    }
});
