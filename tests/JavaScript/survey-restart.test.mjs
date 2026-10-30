import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import vm from "node:vm";
import { Element, FakeFormData } from "./helpers/public-runtime.mjs";
import {
    definitionHash,
    definitions,
    memoryStorage,
    cacheKey,
} from "./helpers/contact-followup.mjs";

const sources = [
    "survey-session.js",
    "survey-draft-state.js",
    "contact-followup-state.js",
    "survey-restart.js",
].map((name) =>
    readFileSync(new URL("../../public/js/" + name, import.meta.url), "utf8"),
);
const response = (status, body) =>
    new Response(JSON.stringify(body), {
        status,
        headers: { "Content-Type": "application/json" },
    });
const csrf = (value) => response(200, { csrf_token: value.repeat(40) });
const flush = () => new Promise((resolve) => setImmediate(resolve));

function setup(
    replies,
    { storage = memoryStorage(), followupBusy = false } = {},
) {
    const document = new Element("document");
    document.ownerDocument = document;
    document.createElement = (tag) => new Element(tag, document);
    const add = (parent, tag, props) => {
        const item = new Element(tag, document);
        Object.assign(item, props);
        parent.append(item);
        return item;
    };
    const form = add(document, "form", {
        action: "/a/survey/velreiz",
        dataset: {
            surveyRestart: "",
            sessionUrl: "/a/survey/session",
            draftScope: "draft-scope",
            contactScope: "survey",
            contactDefinition: definitionHash,
        },
    });
    add(form, "input", {
        type: "hidden",
        name: "_token",
        value: "a".repeat(40),
    });
    const button = add(form, "button", { type: "submit" });
    const error = add(form, "p", {
        hidden: true,
        dataset: { restartError: "" },
    });
    const followup = add(document, "form", { id: "contact-followup-form" });
    if (followupBusy) followup.setAttribute("aria-busy", "true");
    const calls = [],
        redirects = [];
    const queue = [...replies];
    const context = vm.createContext({
        document,
        URL,
        Response,
        TextEncoder,
        FormData: FakeFormData,
        sessionStorage: storage,
        location: {
            origin: "http://mikausis.test",
            href: "http://mikausis.test/a/survey/paldies",
            assign: (url) => redirects.push(url),
        },
        fetch: async (url, options) => {
            calls.push({
                url,
                ...options,
                fields: options.body ? [...options.body.entries()] : null,
            });
            assert.ok(queue.length, "Unexpected request");
            const result = queue.shift();
            if (result instanceof Error) throw result;
            return result;
        },
    });
    context.window = context;
    for (const source of sources) vm.runInContext(source, context);
    const draft = context.MikausisSurveyDraftState.create({
        scope: "draft-scope",
        definition: definitionHash,
        questions: [{ key: "a", type: "single", options: [{ value: "yes" }] }],
    });
    draft.save({
        answers: { a: "yes" },
        otherAnswers: {},
        step: 0,
        resumeToken: "old-encrypted-proof",
    });
    const contacts = context.MikausisContactFollowupState.create({
        scope: "survey",
        definition: definitionHash,
        definitions,
    });
    contacts.capture([{ key: "a", values: ["yes"] }]);
    storage.setItem("mikausis.survey-draft:other-survey", "untouched-draft");
    storage.setItem(cacheKey("other-survey"), "untouched-contacts");
    return {
        form,
        button,
        error,
        followup,
        calls,
        redirects,
        storage,
        context,
        post: () => form.fire("submit"),
    };
}

test("second-parent restart clears only matching browser records after server success", async () => {
    const page = setup([csrf("b"), response(200, { redirect: "/a/survey" })]);
    await page.post();
    assert.deepEqual(
        page.calls.map(({ method }) => method),
        ["GET", "POST"],
    );
    assert.deepEqual(page.calls[1].fields, [["_token", "b".repeat(40)]]);
    assert.equal(page.calls[0].headers["X-Survey-Resume"], undefined);
    assert.equal(
        page.storage.getItem("mikausis.survey-draft:draft-scope"),
        null,
    );
    assert.equal(page.storage.getItem(cacheKey()), null);
    assert.equal(
        page.storage.getItem("mikausis.survey-draft:other-survey"),
        "untouched-draft",
    );
    assert.equal(
        page.storage.getItem(cacheKey("other-survey")),
        "untouched-contacts",
    );
    assert.deepEqual(page.redirects, ["http://mikausis.test/a/survey"]);
});

test("expired restart CSRF renews once without main answers or contact data", async () => {
    const page = setup([
        csrf("b"),
        response(419, {}),
        csrf("c"),
        response(200, { redirect: "/a/survey/g/family-token" }),
    ]);
    await page.post();
    assert.deepEqual(
        page.calls.map(({ method }) => method),
        ["GET", "POST", "GET", "POST"],
    );
    assert.ok(
        page.calls
            .filter(({ method }) => method === "POST")
            .every(
                ({ fields }) =>
                    fields.length === 1 && fields[0][0] === "_token",
            ),
    );
    assert.deepEqual(page.redirects, [
        "http://mikausis.test/a/survey/g/family-token",
    ]);
});

test("restart failure keeps local recovery records and restores the button", async () => {
    for (const reply of [
        new TypeError("Offline"),
        response(422, { errors: { survey: ["<img src=x onerror=alert(1)>"] } }),
        response(200, { redirect: "https://foreign.test/" }),
    ]) {
        const page = setup([csrf("b"), reply]);
        await page.post();
        assert.ok(page.storage.getItem("mikausis.survey-draft:draft-scope"));
        assert.ok(page.storage.getItem(cacheKey()));
        assert.deepEqual(page.redirects, []);
        assert.equal(page.button.disabled, false);
        assert.equal(page.error.hidden, false);
        assert.equal(page.error.ownerDocument.activeElement, page.error);
        assert.equal(page.error.htmlWrites.length, 0);
    }
});

test("restart cannot race a pending followup or another restart", async () => {
    const busy = setup([], { followupBusy: true });
    await busy.post();
    assert.equal(busy.calls.length, 0);
    assert.equal(busy.button.disabled, true);
    let finish;
    const pending = new Promise((resolve) => {
        finish = resolve;
    });
    const page = setup([csrf("b"), pending]);
    const posting = page.post();
    await flush();
    assert.equal(page.button.disabled, true);
    assert.equal(page.form.getAttribute("aria-busy"), "true");
    await page.post();
    assert.equal(page.calls.length, 2);
    finish(response(200, { redirect: "/a/survey" }));
    await posting;
    assert.equal(page.button.disabled, false);
});

test("unavailable storage does not block starting the next response", async () => {
    const storage = {
        getItem() {
            throw new Error("Denied");
        },
        setItem() {
            throw new Error("Denied");
        },
        removeItem() {
            throw new Error("Denied");
        },
    };
    const page = setup([csrf("b"), response(200, { redirect: "/a/survey" })], {
        storage: memoryStorage(),
    });
    page.context.sessionStorage = storage;
    await page.post();
    assert.deepEqual(page.redirects, ["http://mikausis.test/a/survey"]);
});
