import assert from "node:assert/strict";
import test from "node:test";
import "../../public/js/contact-followup-state.js";
import {
    cacheKey,
    contactId,
    definitionHash,
    definitions,
    memoryStorage,
} from "./helpers/contact-followup.mjs";

const { create } = globalThis.MikausisContactFollowupState;
const setup = (extra = {}) => {
    const options = {
        scope: "survey",
        definition: definitionHash,
        definitions,
        storage: memoryStorage(),
        now: () => 1000,
        ...extra,
    };
    return { options, cache: create(options), storage: options.storage };
};
const selection = (key = "a", values = ["yes"], extra = {}) => ({
    key,
    values,
    ...extra,
});

test("only current eligible triggering values, contact IDs and status enter the scoped tab cache", () => {
    const { cache, storage } = setup();
    cache.capture([
        selection("a", ["yes", "unrelated", "Other private text"], {
            fields: { email: "private@example.test" },
        }),
        selection("b", ["maybe", "yes", "unrelated"]),
        selection("c", ["yes"], { visible: false }),
        selection("unknown", ["yes"]),
        { key: "family_id", values: ["family-private"] },
    ]);
    const record = JSON.parse(storage.getItem(cacheKey()));
    assert.deepEqual(Object.keys(record), [
        "version",
        "definition",
        "expiresAt",
        "entries",
    ]);
    assert.deepEqual(record.entries, [
        {
            key: "a",
            values: ["yes"],
            contactId: contactId(1),
            status: "available",
        },
        {
            key: "b",
            values: ["yes", "maybe"],
            contactId: contactId(2),
            status: "available",
        },
    ]);
    const raw = storage.getItem(cacheKey());
    for (const privateValue of [
        "unrelated",
        "Other private text",
        "private@example.test",
        "family-private",
        "endpoint",
        "fields",
    ])
        assert.equal(raw.includes(privateValue), false);
});

test("saved and uncertain states survive reload and saved entries never become available", () => {
    const { cache, options } = setup();
    cache.capture([selection("a"), selection("b")]);
    cache.mark(["a", "b"], "uncertain");
    cache.mark(["a"], "saved");
    cache.mark(["a"], "available");
    assert.deepEqual(
        create(options)
            .entries()
            .map(({ key, status }) => [key, status]),
        [
            ["a", "saved"],
            ["b", "uncertain"],
        ],
    );
});

test("a definite rejection remains available with its original contact ID and selected values", () => {
    const { cache, options } = setup();
    cache.capture([selection()]);
    cache.mark(["a"], "uncertain");
    cache.mark(["a"], "available");
    assert.deepEqual(create(options).entries(), [
        {
            key: "a",
            values: ["yes"],
            contactId: contactId(1),
            status: "available",
        },
    ]);
});

test("cache expires after one hour and status changes do not extend it", () => {
    let time = 1000;
    const { cache, options, storage } = setup({ now: () => time });
    cache.capture([selection()]);
    const expiry = JSON.parse(storage.getItem(cacheKey())).expiresAt;
    assert.equal(expiry, time + 60 * 60 * 1000);
    time = expiry - 1;
    assert.equal(create(options).entries().length, 1);
    cache.mark(["a"], "uncertain");
    assert.equal(JSON.parse(storage.getItem(cacheKey())).expiresAt, expiry);
    time = expiry;
    assert.deepEqual(create(options).entries(), []);
    assert.equal(storage.getItem(cacheKey()), null);
});

test("survey scope and current server definition hash must match", () => {
    const { cache, options, storage } = setup();
    cache.capture([selection()]);
    assert.deepEqual(create({ ...options, scope: "another" }).entries(), []);
    assert.ok(storage.getItem(cacheKey()));
    assert.deepEqual(
        create({ ...options, definition: "b".repeat(64) }).entries(),
        [],
    );
    assert.equal(storage.getItem(cacheKey()), null);
});

test("GET-generated contact IDs never replace the cached idempotency ID", () => {
    const { cache, options } = setup();
    cache.capture([selection()]);
    const reloaded = create({
        ...options,
        definitions: definitions.map((item) => ({
            ...item,
            contactId: contactId(9),
        })),
    });
    assert.equal(reloaded.entries()[0].contactId, contactId(1));
    reloaded.entries()[0].values.push("unrelated");
    assert.deepEqual(reloaded.entries()[0].values, ["yes"]);
});

test("malformed JSON and oversized storage are discarded", () => {
    for (const raw of ["null", "{", "[]", " ".repeat(65537)]) {
        const { options, storage } = setup();
        storage.setItem(cacheKey(), raw);
        assert.deepEqual(create(options).entries(), []);
        assert.equal(storage.getItem(cacheKey()), null);
    }
});

test("cache schema, keys, IDs, values, status and timestamps fail closed", () => {
    const mutations = [
        (record) => {
            record.version = 2;
        },
        (record) => {
            record.fields = { email: "private@example.test" };
        },
        (record) => {
            record.expiresAt = 1000 + 3600001;
        },
        (record) => {
            record.entries.push({ ...record.entries[0] });
        },
        (record) => {
            record.entries[0].key = "unknown";
        },
        (record) => {
            record.entries[0].values = ["unrelated"];
        },
        (record) => {
            record.entries[0].values = ["yes", "yes"];
        },
        (record) => {
            record.entries[0].values = ["yes", "maybe"];
        },
        (record) => {
            record.entries[0].contactId = "not-a-uuid";
        },
        (record) => {
            record.entries[0].status = "pending";
        },
        (record) => {
            record.entries[0].endpoint = "https://unsafe.test";
        },
        (record) => {
            record.entries = Array(201).fill(record.entries[0]);
        },
    ];
    for (const mutate of mutations) {
        const { cache, storage, options } = setup();
        cache.capture([selection()]);
        const record = JSON.parse(storage.getItem(cacheKey()));
        mutate(record);
        storage.setItem(cacheKey(), JSON.stringify(record));
        assert.deepEqual(create(options).entries(), []);
        assert.equal(storage.getItem(cacheKey()), null);
    }
});

test("current trigger definitions whitelist even a matching-hash cache", () => {
    const { cache, options } = setup();
    cache.capture([selection()]);
    assert.deepEqual(
        create({
            ...options,
            definitions: definitions.map((item) => ({
                ...item,
                triggerValues: ["maybe"],
            })),
        }).entries(),
        [],
    );
});

test("disabled storage never blocks capture or contact state transitions", () => {
    const unavailable = {
        getItem() {
            throw new Error("Storage unavailable");
        },
        setItem() {
            throw new Error("Storage unavailable");
        },
        removeItem() {
            throw new Error("Storage unavailable");
        },
    };
    const { cache } = setup({ storage: unavailable });
    assert.doesNotThrow(() => cache.capture([selection()]));
    assert.doesNotThrow(() => cache.mark(["a"], "uncertain"));
    assert.equal(cache.entries()[0].status, "uncertain");
});

test("a failed status write removes the old available record", () => {
    const { cache, storage } = setup();
    cache.capture([selection()]);
    storage.setItem = () => {
        throw new Error("Quota exceeded");
    };
    cache.mark(["a"], "uncertain");
    assert.equal(storage.getItem(cacheKey()), null);
    assert.equal(cache.entries()[0].status, "uncertain");
});

test("invalid scope, server hash and duplicate definitions never persist entries", () => {
    for (const extra of [
        { scope: "../survey" },
        { definition: "invalid" },
        { definitions: [definitions[0], definitions[0]] },
    ]) {
        const { cache, storage } = setup(extra);
        cache.capture([selection()]);
        assert.deepEqual(cache.entries(), []);
        assert.ok(
            [...storage.values.values()].every(
                (raw) => JSON.parse(raw).entries.length === 0,
            ),
        );
    }
});

test("starting another response clears the matching contact state and leaves other surveys intact", () => {
    const { cache, storage } = setup();
    cache.capture([selection()]);
    storage.setItem(cacheKey("other"), "other response");
    cache.clear();
    assert.equal(storage.getItem(cacheKey()), null);
    assert.deepEqual(cache.entries(), []);
    cache.mark(["a"], "saved");
    assert.equal(storage.getItem(cacheKey()), null);
    assert.equal(storage.getItem(cacheKey("other")), "other response");
});
