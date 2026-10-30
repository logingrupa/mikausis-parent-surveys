import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import vm from "node:vm";
import "../../public/js/survey-draft-state.js";

const { create } = globalThis.MikausisSurveyDraftState;
const ttl = 60 * 60 * 1000;
const maxBytes = 256 * 1024;
const cacheKey = (scope = "survey") => "mikausis.survey-draft:" + scope;
const questions = [
    { key: "comment", type: "text", options: [], allowOther: false },
    {
        key: "choice",
        type: "single",
        options: [{ value: "yes" }, { value: "no" }],
        allowOther: true,
    },
    {
        key: "activities",
        type: "multiple",
        options: [{ value: "music" }, { value: "sport" }],
        allowOther: true,
    },
];

function memoryStorage() {
    const values = new Map();
    return {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: (key) => values.delete(key),
    };
}

function setup(extra = {}) {
    const options = {
        scope: "survey",
        definition: "a".repeat(64),
        questions,
        storage: memoryStorage(),
        now: () => 1000,
        ...extra,
    };
    return { options, draft: create(options), storage: options.storage };
}

const payload = (extra = {}) => ({
    answers: {
        comment: "  An unfinished thought  ",
        choice: "other",
        activities: ["music", "other"],
    },
    otherAnswers: { choice: "Another choice", activities: "Another activity" },
    step: 1,
    role: "mother",
    resumeToken: "encrypted-server-proof-1=",
    ...extra,
});

test("refresh restores only the agreed payload shape and expiry", () => {
    const { draft, options } = setup();
    const input = payload();
    assert.equal(draft.read(), null);
    assert.equal(draft.save(input), true);
    assert.deepEqual(create(options).read(), {
        ...input,
        expiresAt: 1000 + ttl,
    });
    input.answers.activities.push("sport");
    input.otherAnswers.choice = "Changed outside the helper";
    const result = draft.read();
    result.answers.activities.push("sport");
    result.otherAnswers.choice = "Changed returned snapshot";
    result.resumeToken = "changed-proof";
    assert.deepEqual(draft.read(), { ...payload(), expiresAt: 1000 + ttl });
});

test("identity, contact, consent, URLs and unknown answers never enter storage", () => {
    const { draft, storage } = setup();
    const privateFields = {
        submission_id: "12345678-1234-4123-8123-123456789abc",
        respondent_name: "Private parent",
        respondent: { email: "identified@example.test" },
        contacts: { email: "contact@example.test", consent: true },
        anonymous: false,
        consent: true,
        _token: "private-csrf-token",
        endpoint: "/private/submit",
        url: "https://example.test/private-family-token",
        familyToken: "private-family-token",
    };
    const input = payload({
        ...privateFields,
        answers: { ...payload().answers, ...privateFields },
        otherAnswers: { ...payload().otherAnswers, ...privateFields },
    });
    assert.equal(draft.save(input), true);
    const raw = storage.getItem(cacheKey());
    const record = JSON.parse(raw);
    assert.deepEqual(Object.keys(record).sort(), [
        "answers",
        "createdAt",
        "definition",
        "expiresAt",
        "otherAnswers",
        "resumeToken",
        "role",
        "scope",
        "step",
        "version",
    ]);
    assert.deepEqual(record.answers, payload().answers);
    assert.deepEqual(record.otherAnswers, payload().otherAnswers);
    for (const key of Object.keys(privateFields))
        assert.equal(raw.includes(JSON.stringify(key) + ":"), false, key);
    for (const secret of [
        "Private parent",
        "identified@example.test",
        "contact@example.test",
        privateFields.submission_id,
        "private-csrf-token",
        "/private/submit",
        "private-family-token",
    ])
        assert.equal(raw.includes(secret), false, secret);
});

test("text and other text preserve unfinished whitespace up to 2000 Unicode characters", () => {
    const { draft } = setup();
    const text = "😀".repeat(2000);
    assert.equal(
        draft.save(
            payload({
                answers: { comment: text, choice: "other" },
                otherAnswers: { choice: text },
            }),
        ),
        true,
    );
    assert.equal(draft.read().answers.comment, text);
    assert.equal(draft.read().otherAnswers.choice, text);
    draft.save(
        payload({
            answers: { comment: text + "x", choice: "other" },
            otherAnswers: { choice: text + "x" },
        }),
    );
    assert.deepEqual(draft.read().answers, { choice: "other" });
    assert.deepEqual(draft.read().otherAnswers, {});
    draft.save(
        payload({
            answers: { comment: "", choice: "other", activities: [] },
            otherAnswers: { choice: "  " },
        }),
    );
    assert.deepEqual(draft.read().answers, {
        comment: "",
        choice: "other",
        activities: [],
    });
    assert.deepEqual(draft.read().otherAnswers, { choice: "  " });
});

test("incorrect answer types, unknown options, duplicates and sparse selections are filtered", () => {
    const badAnswers = [
        { comment: 42, choice: ["yes"], activities: "music" },
        {
            comment: { value: "private" },
            choice: "unknown",
            activities: ["bad"],
        },
        { comment: null, choice: true, activities: ["music", "music"] },
        { activities: ["music", 1] },
        { activities: ["music", undefined] },
        { activities: Array(1) },
        { activities: ["music", "sport", "other", "bad"] },
        { activities: { 0: "music", length: 1 } },
    ];
    for (const answers of badAnswers) {
        const { draft } = setup();
        assert.equal(draft.save(payload({ answers })), true);
        assert.deepEqual(draft.read().answers, {});
        assert.deepEqual(draft.read().otherAnswers, {});
    }
});

test("other text requires a valid selected other option", () => {
    const { draft } = setup();
    draft.save(
        payload({
            answers: { comment: "text", choice: "yes", activities: ["music"] },
            otherAnswers: {
                comment: "Private orphan",
                choice: "Private orphan",
                activities: "Private orphan",
            },
        }),
    );
    assert.deepEqual(draft.read().otherAnswers, {});
    const explicit = setup({
        questions: [
            {
                key: "choice",
                type: "single",
                options: [{ value: "other" }],
                allowOther: false,
            },
        ],
    });
    explicit.draft.save(payload());
    assert.deepEqual(explicit.draft.read().otherAnswers, {
        choice: "Another choice",
    });
    const disabled = setup({
        questions: [{ ...questions[1], allowOther: false }],
    });
    disabled.draft.save(payload());
    assert.deepEqual(disabled.draft.read().answers, {});
    assert.deepEqual(disabled.draft.read().otherAnswers, {});
});

test("valid backend prototype-like question keys round-trip without prototype pollution", () => {
    const keys = ["constructor", "prototype", "toString", "hasOwnProperty"];
    const { draft } = setup({
        questions: keys.map((key) => ({ key, type: "text" })),
    });
    const answers = Object.fromEntries(keys.map((key) => [key, key + " text"]));
    Object.defineProperty(answers, "__proto__", {
        enumerable: true,
        value: { polluted: true },
    });
    assert.equal(draft.save(payload({ answers })), true);
    assert.deepEqual(
        draft.read().answers,
        Object.fromEntries(keys.map((key) => [key, key + " text"])),
    );
    assert.equal(Object.hasOwn(draft.read().answers, "__proto__"), false);
    assert.equal(Object.prototype.polluted, undefined);
});

test("inherited payload values and inherited answers are ignored", () => {
    const { draft } = setup();
    const answers = Object.create({ choice: "yes", comment: "Inherited text" });
    answers.activities = ["other"];
    const otherAnswers = Object.create({ activities: "Inherited other text" });
    const input = Object.assign(Object.create({ role: "father" }), {
        answers,
        otherAnswers,
        step: 0,
        resumeToken: "own-proof",
    });
    assert.equal(draft.save(input), true);
    assert.deepEqual(draft.read(), {
        answers: { activities: ["other"] },
        otherAnswers: {},
        step: 0,
        resumeToken: "own-proof",
        expiresAt: 1000 + ttl,
    });
    draft.clear();
    assert.equal(draft.save(Object.create(payload())), false);
});

test("expiry starts on first save and edits, reads and reloads never extend it", () => {
    let time = 1000;
    const { draft, options, storage } = setup({ now: () => time });
    time = 5000;
    assert.equal(draft.save(payload()), true);
    const expiresAt = time + ttl;
    time = expiresAt - 1;
    const reloaded = create(options);
    assert.equal(reloaded.read().expiresAt, expiresAt);
    reloaded.save(payload({ step: 2, resumeToken: "fresh-get-proof" }));
    assert.equal(draft.read().expiresAt, expiresAt);
    assert.equal(JSON.parse(storage.getItem(cacheKey())).createdAt, 5000);
    time = expiresAt;
    assert.equal(draft.read(), null);
    assert.equal(storage.getItem(cacheKey()), null);
    assert.equal(reloaded.read(), null);
});

test("resume proof stays immutable across saves, refreshed GET proofs and helper instances", () => {
    const { draft, options } = setup();
    draft.save(payload());
    const reloaded = create(options);
    reloaded.save(payload({ resumeToken: "new-page-proof", role: "father" }));
    draft.save({ answers: { choice: "no" }, step: 100 });
    assert.equal(draft.read().resumeToken, payload().resumeToken);
    assert.deepEqual(draft.read().answers, { choice: "no" });
    assert.equal(Object.hasOwn(draft.read(), "role"), false);
    assert.equal(reloaded.read().resumeToken, payload().resumeToken);
});

test("expiry or clear permits a fresh token and a new one-hour draft", () => {
    let time = 1000;
    const { draft } = setup({ now: () => time });
    draft.save(payload());
    time += ttl;
    assert.equal(
        draft.save(payload({ resumeToken: "new-expired-draft-proof" })),
        true,
    );
    assert.equal(draft.read().resumeToken, "new-expired-draft-proof");
    assert.equal(draft.read().expiresAt, time + ttl);
    draft.clear();
    time += 100;
    draft.save(payload({ resumeToken: "new-cleared-draft-proof" }));
    assert.equal(draft.read().resumeToken, "new-cleared-draft-proof");
    assert.equal(draft.read().expiresAt, time + ttl);
});

test("survey and family scopes stay isolated and definition changes clear only their scope", () => {
    const { draft, options, storage } = setup();
    draft.save(payload());
    const family = create({ ...options, scope: "b".repeat(64) });
    assert.equal(family.read(), null);
    assert.ok(storage.getItem(cacheKey()));
    family.save(payload({ resumeToken: "family-proof" }));
    assert.equal(draft.read().resumeToken, payload().resumeToken);
    assert.equal(family.read().resumeToken, "family-proof");
    assert.equal(
        create({ ...options, definition: "c".repeat(64) }).read(),
        null,
    );
    assert.equal(storage.getItem(cacheKey()), null);
    assert.equal(family.read().resumeToken, "family-proof");
});

test("clear with no rendered questions removes only the matching completed draft", () => {
    const { draft, options, storage } = setup();
    draft.save(payload());
    create({ ...options, scope: "another-survey" }).save(payload());
    assert.doesNotThrow(() => create({ ...options, questions: [] }).clear());
    assert.equal(storage.getItem(cacheKey()), null);
    assert.ok(storage.getItem(cacheKey("another-survey")));
    assert.equal(draft.read(), null);
});

test("a stored scope mismatch is removed instead of restoring another family's answers", () => {
    const { draft, options, storage } = setup();
    draft.save(payload());
    storage.setItem(cacheKey("another"), storage.getItem(cacheKey()));
    assert.equal(create({ ...options, scope: "another" }).read(), null);
    assert.equal(storage.getItem(cacheKey("another")), null);
    assert.ok(draft.read());
});

test("malformed, oversized and non-object JSON is removed and can be replaced on save", () => {
    for (const raw of [
        "{",
        "null",
        "[]",
        "true",
        "42",
        " ".repeat(maxBytes + 1),
    ]) {
        const { draft, storage } = setup();
        storage.setItem(cacheKey(), raw);
        assert.equal(draft.read(), null);
        assert.equal(storage.getItem(cacheKey()), null);
        storage.setItem(cacheKey(), raw);
        assert.equal(draft.save(payload()), true);
        assert.ok(draft.read());
    }
});

test("stored schema, timestamp, answer, role and token corruption fails closed", () => {
    const mutations = [
        (record) => delete record.answers,
        (record) => (record.version = 2),
        (record) => (record.contact = { email: "private@example.test" }),
        (record) => (record.definition = "b".repeat(64)),
        (record) => (record.createdAt = 1001),
        (record) => (record.createdAt = -1),
        (record) => (record.createdAt = 1.5),
        (record) => (record.expiresAt += 1),
        (record) => (record.expiresAt = 1000),
        (record) => (record.expiresAt = "3601000"),
        (record) => (record.step = -2),
        (record) => (record.step = 101),
        (record) => (record.step = 1.5),
        (record) => (record.role = "identified-parent"),
        (record) => (record.resumeToken = ""),
        (record) => (record.resumeToken = "proof\n"),
        (record) =>
            (record.resumeToken = "12345678-1234-4123-8123-123456789abc"),
        (record) => (record.answers = []),
        (record) => (record.answers.unknown = "private text"),
        (record) => (record.answers.comment = "x".repeat(2001)),
        (record) => (record.answers.choice = "unknown"),
        (record) => (record.answers.activities = ["music", "music"]),
        (record) => (record.otherAnswers = null),
        (record) => (record.otherAnswers.comment = "orphan text"),
        (record) => (record.otherAnswers.choice = "x".repeat(2001)),
        (record) =>
            Object.defineProperty(record.answers, "__proto__", {
                enumerable: true,
                value: { polluted: true },
            }),
    ];
    for (const mutate of mutations) {
        const { draft, storage } = setup();
        draft.save(payload());
        const record = JSON.parse(storage.getItem(cacheKey()));
        mutate(record);
        storage.setItem(cacheKey(), JSON.stringify(record));
        assert.equal(draft.read(), null);
        assert.equal(storage.getItem(cacheKey()), null);
    }
    assert.equal(Object.prototype.polluted, undefined);
});

test("valid steps and family roles round-trip while invalid roles are omitted", () => {
    for (const step of [-1, 0, 100]) {
        for (const role of [
            undefined,
            "mother",
            "father",
            "guardian",
            "unknown",
        ]) {
            const { draft } = setup();
            assert.equal(draft.save(payload({ step, role })), true);
            assert.equal(draft.read().step, step);
            if (["mother", "father", "guardian"].includes(role))
                assert.equal(draft.read().role, role);
            else assert.equal(Object.hasOwn(draft.read(), "role"), false);
        }
    }
});

test("invalid steps fail safely and remove the previous stale draft", () => {
    for (const step of [undefined, null, "1", -2, 101, 1.5, NaN, Infinity]) {
        const { draft, storage } = setup();
        draft.save(payload());
        assert.equal(draft.save(payload({ step })), false);
        assert.equal(storage.getItem(cacheKey()), null);
    }
});

test("resume proof is required, printable ASCII, at most 4096 characters and not a raw UUID", () => {
    for (const resumeToken of [
        undefined,
        null,
        1,
        "",
        "proof with spaces",
        "proof\n",
        "proof\u0000",
        "proof\u007f",
        "proof😀",
        "x".repeat(4097),
        "12345678-1234-4123-8123-123456789abc",
    ]) {
        const { draft, storage } = setup();
        assert.equal(draft.save(payload({ resumeToken })), false);
        assert.equal(storage.getItem(cacheKey()), null);
    }
    const { draft } = setup();
    const resumeToken = "x".repeat(4096);
    assert.equal(draft.save(payload({ resumeToken })), true);
    assert.equal(draft.read().resumeToken, resumeToken);
});

test("question and option metadata is bounded and follows the backend key format", () => {
    const invalid = [
        null,
        {},
        Array(101).fill(questions[0]),
        [null],
        [questions[0], questions[0]],
        [{ key: "__proto__", type: "text" }],
        [{ key: "1key", type: "text" }],
        [{ key: "q".repeat(81), type: "text" }],
        [{ key: "q", type: "email" }],
        [{ key: "q", type: "single", allowOther: "true" }],
        [{ key: "q", type: "single", options: {} }],
        [
            {
                key: "q",
                type: "single",
                options: Array(101).fill({ value: "x" }),
            },
        ],
        [{ key: "q", type: "single", options: [null] }],
        [{ key: "q", type: "single", options: [{ value: 1 }] }],
        [{ key: "q", type: "single", options: [{ value: "" }] }],
        [{ key: "q", type: "single", options: [{ value: "x".repeat(101) }] }],
        [
            {
                key: "q",
                type: "single",
                options: [{ value: "x" }, { value: "x" }],
            },
        ],
    ];
    for (const definitions of invalid) {
        const { draft } = setup({ questions: definitions });
        assert.equal(draft.save(payload()), false);
        assert.equal(draft.read(), null);
    }
    const key = "q".repeat(80);
    const options = Array.from({ length: 100 }, (_, index) => ({
        value: String(index).padEnd(100, "x"),
    }));
    const { draft } = setup({
        questions: [{ key, type: "multiple", options, allowOther: true }],
    });
    const answers = { [key]: [...options.map(({ value }) => value), "other"] };
    assert.equal(draft.save(payload({ answers })), true);
    assert.deepEqual(draft.read().answers, answers);
});

test("invalid scopes and hashes cannot read or clear another scope", () => {
    const { draft, options, storage } = setup();
    draft.save(payload());
    for (const scope of [
        undefined,
        null,
        "",
        "a/b",
        "https://example.test",
        "a".repeat(161),
    ]) {
        const invalid = create({ ...options, scope });
        assert.equal(invalid.save(payload()), false);
        assert.equal(invalid.read(), null);
        invalid.clear();
        assert.ok(storage.getItem(cacheKey()));
    }
    for (const definition of [
        undefined,
        null,
        "",
        "a".repeat(63),
        "z".repeat(64),
    ]) {
        const invalid = create({ ...options, scope: "another", definition });
        assert.equal(invalid.save(payload()), false);
        assert.equal(invalid.read(), null);
        assert.ok(storage.getItem(cacheKey()));
    }
});

test("100 questions fit when small enough and the total limit counts UTF-8 bytes", () => {
    const definitions = Array.from({ length: 100 }, (_, index) => ({
        key: "q" + index,
        type: "text",
    }));
    const answers = Object.fromEntries(
        definitions.map(({ key }) => [key, "x".repeat(2000)]),
    );
    const { draft, storage } = setup({ questions: definitions });
    assert.equal(draft.save(payload({ answers })), true);
    const record = JSON.parse(storage.getItem(cacheKey()));
    assert.equal(Object.keys(draft.read().answers).length, 100);
    record.answers = Object.fromEntries(
        definitions.map(({ key }) => [key, "€".repeat(1000)]),
    );
    const oversized = JSON.stringify(record);
    assert.ok(oversized.length < maxBytes);
    assert.ok(Buffer.byteLength(oversized, "utf8") > maxBytes);
    storage.setItem(cacheKey(), oversized);
    assert.equal(draft.read(), null);
    assert.equal(storage.getItem(cacheKey()), null);
    assert.equal(draft.save(payload({ answers })), true);
    assert.equal(draft.save(payload({ answers: record.answers })), false);
    assert.equal(storage.getItem(cacheKey()), null);
});

test("quota failure clears old answers instead of restoring stale state", () => {
    const { draft, storage } = setup();
    draft.save(payload());
    storage.setItem = () => {
        throw new Error("QuotaExceededError");
    };
    assert.equal(
        draft.save(payload({ answers: { comment: "New answer" } })),
        false,
    );
    assert.equal(storage.getItem(cacheKey()), null);
    assert.equal(draft.read(), null);
});

test("unavailable, missing and throwing storage never interrupt the survey", () => {
    const throws = () => {
        throw new Error("Storage denied");
    };
    for (const storage of [
        null,
        undefined,
        {},
        { getItem: throws, setItem: throws, removeItem: throws },
        { getItem: () => "{", setItem: throws, removeItem: throws },
    ]) {
        const { draft } = setup({ storage });
        assert.equal(draft.read(), null);
        assert.equal(draft.save(payload()), false);
        assert.doesNotThrow(() => draft.clear());
    }
});

test("default sessionStorage works and a denied getter is caught without DOM access", () => {
    const source = readFileSync(
        new URL("../../public/js/survey-draft-state.js", import.meta.url),
        "utf8",
    );
    const storage = memoryStorage();
    const context = vm.createContext({ sessionStorage: storage, TextEncoder });
    vm.runInContext(source, context);
    const { options } = setup();
    delete options.storage;
    const draft = context.MikausisSurveyDraftState.create(options);
    assert.equal(draft.save(payload()), true);
    assert.ok(storage.getItem(cacheKey()));
    const denied = vm.createContext({ TextEncoder });
    Object.defineProperty(denied, "sessionStorage", {
        get() {
            throw new Error("SecurityError");
        },
    });
    vm.runInContext(source, denied);
    const unavailable = denied.MikausisSurveyDraftState.create(options);
    assert.equal(unavailable.read(), null);
    assert.equal(unavailable.save(payload()), false);
    assert.doesNotThrow(() => unavailable.clear());
});

test("invalid or throwing clocks fail safely and backward clocks discard future drafts", () => {
    for (const value of [
        NaN,
        Infinity,
        -1,
        1.5,
        "1000",
        Number.MAX_SAFE_INTEGER,
    ]) {
        const { draft } = setup({ now: () => value });
        assert.equal(draft.save(payload()), false);
        assert.equal(draft.read(), null);
    }
    let time = 1000;
    const { draft, options, storage } = setup({ now: () => time });
    draft.save(payload());
    time = 999;
    assert.equal(draft.read(), null);
    assert.equal(storage.getItem(cacheKey()), null);
    const broken = create({
        ...options,
        now: () => {
            throw new Error("Clock unavailable");
        },
    });
    assert.equal(broken.read(), null);
    assert.equal(broken.save(payload()), false);
});

test("definition metadata is copied and changed current options invalidate stored answers", () => {
    const metadata = structuredClone(questions);
    const { draft, options } = setup({ questions: metadata });
    metadata[1].options[0].value = "changed";
    metadata[1].allowOther = false;
    assert.equal(draft.save(payload({ answers: { choice: "yes" } })), true);
    assert.deepEqual(draft.read().answers, { choice: "yes" });
    assert.equal(create(options).read(), null);
});
