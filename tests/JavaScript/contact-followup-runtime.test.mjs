import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import vm from "node:vm";
import "../../public/js/contact-followup-state.js";
import { Element } from "./helpers/public-runtime.mjs";
import {
    cacheKey,
    contactId,
    definitionHash,
    definitions,
    memoryStorage,
    response,
} from "./helpers/contact-followup.mjs";

const sources = [
    "shared-question-contacts.js",
    "contact-followup-state.js",
    "contact-collector.js",
    "contact-followup.js",
].map((filename) => [
    filename,
    readFileSync(
        new URL("../../public/js/" + filename, import.meta.url),
        "utf8",
    ),
]);
const saved = () => response(200, { saved: true });
const rejected = () =>
    response(422, {
        errors: { "fields.email": ["Pārbaudiet kontaktinformāciju."] },
    });
function seed(
    storage,
    selections = [
        { key: "a", values: ["yes"] },
        { key: "b", values: ["maybe", "yes"] },
    ],
) {
    const cache = globalThis.MikausisContactFollowupState.create({
        scope: "survey",
        definition: definitionHash,
        definitions,
        storage,
    });
    cache.capture(selections);
    return cache;
}

function runtime({
    storage = memoryStorage(),
    replies = [],
    current = definitions,
    hash = definitionHash,
} = {}) {
    const document = new Element("document", null);
    document.ownerDocument = document;
    document.createElement = (tag) => new Element(tag, document);
    const add = (parent, tag, props = {}) => {
        const node = document.createElement(tag);
        Object.assign(node, props);
        parent.append(node);
        return node;
    };
    const form = add(document, "form", {
        id: "contact-followup-form",
        hidden: true,
        dataset: { contactScope: "survey", contactDefinition: hash },
    });
    add(form, "input", { type: "hidden", name: "_token", value: "csrf-test" });
    const intro = add(form, "div", {
        hidden: true,
        dataset: { followupIntro: "" },
    });
    const titles = add(intro, "ul", { dataset: { followupQuestions: "" } });
    const errors = add(form, "div", {
        hidden: true,
        dataset: { followupErrors: "" },
    });
    errors.setAttribute("role", "alert");
    const panel = add(form, "section", { dataset: { sharedContacts: "" } });
    const label = add(panel, "label");
    const consent = add(label, "input", {
        type: "checkbox",
        dataset: { sharedContactConsent: "" },
    });
    add(label, "span", { id: "shared-contact-prompt" });
    const details = add(panel, "div", {
        hidden: true,
        dataset: { sharedContactDetails: "" },
    });
    add(details, "p", { dataset: { sharedContactPurpose: "" } });
    const activities = add(details, "ul", {
        hidden: true,
        dataset: { sharedContactActivities: "" },
    });
    const fields = add(details, "div", {
        dataset: { sharedContactFields: "" },
    });
    const sharedStatus = add(details, "p", {
        dataset: { sharedContactStatus: "" },
    });
    const submit = add(form, "button", {
        id: "followup-submit",
        type: "submit",
        hidden: true,
        textContent: "Saglabāt kontaktinformāciju",
    });
    const status = add(document, "div", {
        hidden: true,
        dataset: { followupStatus: "" },
    });
    add(status, "strong", { textContent: "Kontaktinformācija saglabāta." });
    add(status, "p", {
        textContent:
            "Kontaktinformācija ir saistīta tikai ar šo aktivitāšu izvēlēm; pārējās aptaujas atbildes paliek anonīmas.",
    });
    status.scrollIntoView = (options) => {
        status.scrollOptions = options;
    };
    add(document, "script", {
        id: "contact-followup-definitions",
        textContent: JSON.stringify(current),
    });
    const queue = [...replies];
    const calls = [];
    const context = vm.createContext({
        document,
        sessionStorage: storage,
        fetch: (url, options) => {
            calls.push({ url, ...options });
            assert.ok(queue.length, "Unexpected fetch: " + url);
            const reply = queue.shift();
            return typeof reply === "function"
                ? reply()
                : Promise.resolve(reply);
        },
    });
    context.window = context;
    for (const [filename, source] of sources)
        vm.runInContext(source, context, { filename });
    return {
        document,
        form,
        intro,
        titles,
        errors,
        consent,
        details,
        activities,
        fields,
        sharedStatus,
        submit,
        status,
        calls,
        post: () => form.fire("submit"),
        async optIn() {
            consent.checked = true;
            await consent.fire("change");
        },
        async fill(value = "parent@example.test") {
            const input = fields.querySelector("input");
            assert.ok(input);
            input.value = value;
            await input.fire("input");
            return input;
        },
    };
}

test("a direct thank-you GET hides the follow-up form and does not fetch", () => {
    const fixture = runtime();
    assert.equal(fixture.form.hidden, true);
    assert.equal(fixture.intro.hidden, true);
    assert.equal(fixture.submit.hidden, true);
    assert.equal(fixture.calls.length, 0);
});

test("contact followup waits while the next-parent restart is pending", async () => {
    const storage = memoryStorage();
    seed(storage, [{ key: "a", values: ["yes"] }]);
    const fixture = runtime({ storage, replies: [saved()] });
    await fixture.optIn();
    const field = await fixture.fill();
    const restart = fixture.document.createElement("form");
    restart.dataset.surveyRestart = "";
    restart.setAttribute("aria-busy", "true");
    fixture.document.append(restart);
    await fixture.post();
    assert.equal(fixture.calls.length, 0);
    assert.equal(field.value, "parent@example.test");
    restart.removeAttribute("aria-busy");
    await fixture.post();
    assert.equal(fixture.calls.length, 1);
    assert.equal(fixture.status.hidden, false);
});

test("only eligible available current titles precede a single unchecked opt-in", async () => {
    const storage = memoryStorage();
    const cache = seed(
        storage,
        definitions.map(({ key }) => ({ key, values: ["yes"] })),
    );
    cache.mark(["b"], "saved");
    cache.mark(["c"], "uncertain");
    const fixture = runtime({ storage });
    assert.equal(fixture.form.hidden, false);
    assert.equal(fixture.intro.hidden, false);
    assert.deepEqual(
        fixture.titles.children.map((item) => item.textContent),
        ["Aktivitāte a"],
    );
    assert.ok(
        fixture.form.children.indexOf(fixture.intro) <
            fixture.form.children.indexOf(fixture.consent.closest("section")),
    );
    assert.equal(
        fixture.form.querySelectorAll('input[type="checkbox"]').length,
        1,
    );
    assert.equal(fixture.consent.checked, false);
    assert.equal(fixture.submit.hidden, true);
    assert.equal(fixture.fields.children.length, 0);
    assert.equal(fixture.activities.hidden, true);
    await fixture.post();
    assert.equal(fixture.calls.length, 0);
    await fixture.optIn();
    assert.equal(fixture.submit.hidden, false);
    await fixture.fill();
    fixture.consent.checked = false;
    await fixture.consent.fire("change");
    await fixture.optIn();
    assert.equal(fixture.fields.querySelector("input").value, "");
});

test("all prepared entries become uncertain before the first contact fetch; success hides reminder and reload", async () => {
    const storage = memoryStorage();
    seed(storage);
    let resolveFirst;
    const first = new Promise((resolve) => {
        resolveFirst = resolve;
    });
    const fixture = runtime({
        storage,
        current: definitions.map((item) => ({
            ...item,
            contactId: contactId(9),
        })),
        replies: [
            () => {
                assert.ok(
                    JSON.parse(storage.getItem(cacheKey())).entries.every(
                        ({ status }) => status === "uncertain",
                    ),
                );
                return first;
            },
            saved(),
        ],
    });
    await fixture.optIn();
    const input = await fixture.fill();
    const pending = fixture.post();
    await fixture.post();
    assert.equal(fixture.calls.length, 1);
    assert.equal(input.disabled, true);
    assert.equal(fixture.consent.disabled, true);
    resolveFirst(saved());
    await pending;
    assert.deepEqual(
        fixture.calls.map(({ url }) => url),
        ["/contacts/a", "/contacts/b"],
    );
    assert.deepEqual(
        fixture.calls.map(({ body }) => JSON.parse(body).contact_id),
        [contactId(1), contactId(2)],
    );
    assert.deepEqual(
        fixture.calls.map(({ body }) => JSON.parse(body).answer),
        ["yes", ["yes", "maybe"]],
    );
    assert.ok(
        fixture.calls.every(
            ({ body }) =>
                !body.includes("submission_id") && !body.includes("family_id"),
        ),
    );
    assert.equal(fixture.form.hidden, true);
    assert.equal(fixture.intro.hidden, true);
    assert.equal(
        fixture.status.querySelector("strong").textContent,
        "Kontaktinformācija saglabāta.",
    );
    assert.match(
        fixture.status.querySelector("p").textContent,
        /pārējās aptaujas atbildes paliek anonīmas\./u,
    );
    assert.equal(fixture.status.hidden, false);
    assert.equal(fixture.document.activeElement, fixture.status);
    assert.equal(fixture.status.scrollOptions.block, "center");
    assert.equal(
        storage.getItem(cacheKey()).includes("parent@example.test"),
        false,
    );
    assert.ok(
        JSON.parse(storage.getItem(cacheKey())).entries.every(
            ({ status }) => status === "saved",
        ),
    );
    assert.equal(runtime({ storage }).form.hidden, true);
});

test("partial success reload offers only the safely rejected activity with its cached ID", async () => {
    const storage = memoryStorage();
    seed(storage);
    const fixture = runtime({ storage, replies: [saved(), rejected()] });
    await fixture.optIn();
    await fixture.fill();
    await fixture.post();
    assert.equal(fixture.form.hidden, false);
    assert.equal(fixture.errors.hidden, false);
    assert.deepEqual(
        fixture.titles.children.map((item) => item.textContent),
        ["Aktivitāte b"],
    );
    assert.match(
        fixture.sharedStatus.textContent,
        /Saglabāto aktivitāšu skaits: 1/u,
    );
    assert.equal(fixture.fields.querySelector("input").disabled, false);
    const reloaded = runtime({ storage, replies: [saved()] });
    assert.equal(reloaded.consent.checked, false);
    assert.deepEqual(
        reloaded.titles.children.map((item) => item.textContent),
        ["Aktivitāte b"],
    );
    await reloaded.optIn();
    await reloaded.fill("corrected@example.test");
    await reloaded.post();
    assert.deepEqual(
        reloaded.calls.map(({ url }) => url),
        ["/contacts/b"],
    );
    assert.equal(JSON.parse(reloaded.calls[0].body).contact_id, contactId(2));
});

test("ambiguous contacts retain immutable retries in memory and never get a new form after reload", async () => {
    const storage = memoryStorage();
    seed(storage);
    const fixture = runtime({
        storage,
        replies: [
            () => Promise.reject(new TypeError("Offline")),
            rejected(),
            rejected(),
            saved(),
        ],
    });
    await fixture.optIn();
    const input = await fixture.fill();
    await fixture.post();
    const original = fixture.calls[0].body;
    assert.equal(input.disabled, true);
    assert.match(
        fixture.sharedStatus.textContent,
        /kontaktinformācijas labošana ir apturēta/u,
    );
    input.value = "attempted-change@example.test";
    await input.fire("input");
    await fixture.post();
    assert.equal(fixture.calls[2].body, original);
    assert.equal(input.disabled, true);
    const reloaded = runtime({ storage });
    assert.equal(reloaded.form.hidden, true);
    assert.equal(reloaded.calls.length, 0);
    assert.equal(
        storage.getItem(cacheKey()).includes("parent@example.test"),
        false,
    );
});

test("a first 422 permits correction and the corrected request is uncertain again before fetch", async () => {
    const storage = memoryStorage();
    seed(storage, [{ key: "a", values: ["yes"] }]);
    const fixture = runtime({
        storage,
        replies: [
            rejected(),
            () => {
                assert.equal(
                    JSON.parse(storage.getItem(cacheKey())).entries[0].status,
                    "uncertain",
                );
                return Promise.resolve(saved());
            },
        ],
    });
    await fixture.optIn();
    await fixture.fill();
    await fixture.post();
    assert.equal(
        JSON.parse(storage.getItem(cacheKey())).entries[0].status,
        "available",
    );
    await fixture.fill("corrected@example.test");
    await fixture.post();
    assert.equal(
        JSON.parse(fixture.calls[1].body).fields.email,
        "corrected@example.test",
    );
});

test("stale definitions, expired records and unavailable storage keep the form hidden", () => {
    const storage = memoryStorage();
    seed(storage);
    assert.equal(runtime({ storage, hash: "b".repeat(64) }).form.hidden, true);
    seed(storage);
    const record = JSON.parse(storage.getItem(cacheKey()));
    record.expiresAt = Date.now() - 1;
    storage.setItem(cacheKey(), JSON.stringify(record));
    assert.equal(runtime({ storage }).form.hidden, true);
    assert.doesNotThrow(() =>
        runtime({
            storage: {
                getItem() {
                    throw new Error("Unavailable");
                },
                removeItem() {
                    throw new Error("Unavailable");
                },
            },
        }),
    );
});

test("activity titles and server errors stay plaintext, and storage cannot supply endpoints", async () => {
    const storage = memoryStorage();
    seed(storage, [{ key: "a", values: ["yes"] }]);
    const markup = "<img src=x onerror=alert(1)>";
    const fixture = runtime({
        storage,
        current: definitions.map((item) => ({ ...item, title: markup })),
        replies: [response(422, { errors: { "fields.email": [markup] } })],
    });
    assert.equal(fixture.titles.children[0].textContent, markup);
    assert.equal(fixture.titles.children[0].htmlWrites.length, 0);
    await fixture.optIn();
    await fixture.fill();
    await fixture.post();
    assert.equal(fixture.errors.querySelector("li").textContent, markup);
    assert.equal(fixture.calls[0].url, "/contacts/a");
    const record = JSON.parse(storage.getItem(cacheKey()));
    record.entries[0].endpoint = "https://unsafe.test";
    storage.setItem(cacheKey(), JSON.stringify(record));
    assert.equal(runtime({ storage }).form.hidden, true);
});

test("phone correction uses the approved error and keeps cache available until a valid request", async () => {
    const storage = memoryStorage();
    seed(storage, [{ key: "a", values: ["yes"] }]);
    const fixture = runtime({
        storage,
        current: definitions.map((item) => ({
            ...item,
            fields: [
                {
                    key: "phone",
                    label: "Tālrunis",
                    type: "text",
                    required: true,
                },
            ],
        })),
        replies: [saved()],
    });
    await fixture.optIn();
    const input = await fixture.fill("80000000");
    assert.equal(input.type, "tel");
    await fixture.post();
    assert.equal(fixture.calls.length, 0);
    assert.equal(
        fixture.errors.textContent,
        "Lūdzu, pārbaudiet tālruņa numuru. Piemērs: +371 20000000.",
    );
    assert.equal(input.getAttribute("aria-invalid"), "true");
    assert.equal(fixture.document.activeElement, input);
    assert.equal(
        JSON.parse(storage.getItem(cacheKey())).entries[0].status,
        "available",
    );
    await fixture.fill("+371 20000000");
    await fixture.post();
    assert.equal(
        JSON.parse(fixture.calls[0].body).fields.phone,
        "+371 20000000",
    );
});
