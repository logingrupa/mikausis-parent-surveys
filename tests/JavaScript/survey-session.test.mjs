import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import vm from "node:vm";

const source = readFileSync(
    new URL("../../public/js/survey-session.js", import.meta.url),
    "utf8",
);
const mainId = "11111111-1111-4111-8111-111111111111";
const csrf = (letter) => letter.repeat(40);
const response = (value, status = 200) =>
    new Response(JSON.stringify(value), {
        status,
        headers: { "Content-Type": "application/json" },
    });
const renewed = (letter = "b", extra = {}) =>
    response({
        csrf_token: csrf(letter),
        submission_id: mainId,
        submitted: false,
        ...extra,
    });

function setup(replies, { contact = false, clock = () => 1000 } = {}) {
    const token = { value: csrf("a") };
    const submission = contact ? null : { value: mainId };
    const form = {
        action: "/a/demo",
        dataset: {
            sessionUrl: "/a/demo/session",
            ...(contact ? {} : { sessionResume: "encrypted-resume-proof" }),
        },
        querySelector: (selector) =>
            selector === '[name="_token"]' ? token : submission,
    };
    const calls = [];
    const fetch = async (url, options) => {
        calls.push({
            url,
            ...options,
            fields:
                options.body instanceof FormData
                    ? [...options.body.entries()]
                    : null,
        });
        assert.ok(replies.length, "Unexpected request");
        const reply = replies.shift();
        if (reply instanceof Error) throw reply;
        return typeof reply === "function" ? reply() : reply;
    };
    const context = vm.createContext({
        URL,
        location: {
            href: "http://mikausis.test/a/demo",
            origin: "http://mikausis.test",
        },
        fetch,
    });
    vm.runInContext(source, context);
    const controller = context.MikausisSurveySession.create(form, {
        fetch,
        now: clock,
    });
    return {
        controller,
        form,
        token,
        submission,
        calls,
        create: context.MikausisSurveySession.create,
    };
}

const mainPayload = () => {
    const body = new FormData();
    body.set("_token", csrf("a"));
    body.set("submission_id", mainId);
    body.set("answers[q]", "Keep this answer");
    return body;
};

test("preflight renews CSRF and binds the proven UUID without a page reload", async () => {
    const page = setup([renewed(), response({ redirect: "/a/demo/paldies" })]);
    const result = await page.controller.request("/a/demo", {
        method: "POST",
        body: mainPayload(),
        headers: { Accept: "application/json" },
    });
    assert.equal(result.status, 200);
    assert.equal(page.calls.length, 2);
    assert.equal(
        page.calls[0].headers["X-Survey-Resume"],
        "encrypted-resume-proof",
    );
    assert.equal(page.calls[0].cache, "no-store");
    assert.equal(page.calls[1].headers["X-CSRF-TOKEN"], csrf("b"));
    assert.deepEqual(page.calls[1].fields, [
        ["_token", csrf("b")],
        ["submission_id", mainId],
        ["answers[q]", "Keep this answer"],
    ]);
});

test("a 419 renews once and repeats the same answers and submission UUID", async () => {
    const page = setup([
        renewed("b"),
        response({}, 419),
        renewed("c"),
        response({ redirect: "/thanks" }),
    ]);
    await page.controller.request("/a/demo", {
        method: "POST",
        body: mainPayload(),
    });
    assert.equal(page.calls.length, 4);
    assert.equal(page.calls[3].headers["X-CSRF-TOKEN"], csrf("c"));
    assert.deepEqual(
        page.calls[1].fields.filter(([key]) => key !== "_token"),
        page.calls[3].fields.filter(([key]) => key !== "_token"),
    );
});

test("permanent cookie rejection stays bounded after one retry", async () => {
    const page = setup([
        renewed(),
        response({}, 419),
        renewed("c"),
        response({}, 419),
    ]);
    assert.equal(
        (
            await page.controller.request("/a/demo", {
                method: "POST",
                body: mainPayload(),
            })
        ).status,
        419,
    );
    assert.equal(page.calls.length, 4);
});

test("network ambiguity and ordinary validation never cause an automatic repost", async () => {
    for (const reply of [
        new TypeError("Offline"),
        response({ errors: { "answers.q": ["Invalid"] } }, 422),
        response({}, 500),
    ]) {
        const page = setup([renewed(), reply]);
        try {
            await page.controller.request("/a/demo", {
                method: "POST",
                body: mainPayload(),
            });
        } catch (error) {
            assert.equal(error.message, "Offline");
        }
        assert.equal(page.calls.length, 2);
    }
});

test("a definite stale session binding renews once while other 422 fields are preserved", async () => {
    const page = setup([
        renewed(),
        response({ errors: { submission_id: ["Expired binding"] } }, 422),
        renewed("c"),
        response({ redirect: "/thanks" }),
    ]);
    assert.equal(
        (
            await page.controller.request("/a/demo", {
                method: "POST",
                body: mainPayload(),
            })
        ).status,
        200,
    );
    assert.equal(page.calls.length, 4);
    assert.equal(
        page.calls[3].fields.find(([key]) => key === "submission_id")[1],
        mainId,
    );
});

test("contact retries preserve the exact JSON and do not request a main submission binding", async () => {
    const page = setup(
        [
            response({ csrf_token: csrf("b") }),
            response({}, 419),
            response({ csrf_token: csrf("c") }),
            response({ saved: true }),
        ],
        { contact: true },
    );
    const body = JSON.stringify({
        contact_id: mainId,
        fields: { email: "synthetic@example.test" },
    });
    await page.controller.request("/a/demo/kontakti/q", {
        method: "POST",
        body,
        headers: { "Content-Type": "application/json" },
    });
    assert.equal(page.calls[0].headers["X-Survey-Resume"], undefined);
    assert.equal(page.calls[1].body, body);
    assert.equal(page.calls[3].body, body);
    assert.equal(page.calls[3].headers["X-CSRF-TOKEN"], csrf("c"));
});

test("refreshes share one in-flight request and recheck after four minutes", async () => {
    let release;
    let time = 1000;
    const page = setup(
        [
            () =>
                new Promise((resolve) => {
                    release = resolve;
                }),
            renewed("c"),
        ],
        { clock: () => time },
    );
    const first = page.controller.refresh();
    const second = page.controller.refresh(true);
    assert.equal(page.calls.length, 1);
    release(renewed());
    await Promise.all([first, second]);
    await page.controller.refresh();
    assert.equal(page.calls.length, 1);
    time += 4 * 60 * 1000;
    await page.controller.refresh();
    assert.equal(page.calls.length, 2);
});

test("a proven previous success returns completion without another main POST", async () => {
    const page = setup([
        renewed("b", { submitted: true, redirect: "/a/demo/paldies" }),
    ]);
    const result = await page.controller.request("/a/demo", {
        method: "POST",
        body: mainPayload(),
    });
    assert.equal(
        (await result.json()).redirect,
        "http://mikausis.test/a/demo/paldies",
    );
    assert.equal(page.calls.length, 1);
});

test("proven main completion still sends contacts using the main form session", async () => {
    const page = setup([
        renewed("b", { submitted: true, redirect: "/a/demo/paldies" }),
        response({ saved: true }),
    ]);
    await page.controller.request("/a/demo", {
        method: "POST",
        body: mainPayload(),
    });
    const body = JSON.stringify({
        contact_id: mainId,
        fields: { email: "demo@example.test" },
    });
    const result = await page.controller.request("/a/demo/kontakti/q", {
        method: "POST",
        body,
        headers: { "Content-Type": "application/json" },
    });
    assert.deepEqual(await result.json(), { saved: true });
    assert.equal(page.calls.length, 2);
    assert.equal(page.calls[1].body, body);
});

test("invalid renewal responses and foreign submission URLs fail before any POST", async () => {
    for (const reply of [
        response({}, 403),
        response({ csrf_token: "invalid", submission_id: mainId }),
        response({ csrf_token: csrf("b"), submission_id: "invalid" }),
    ]) {
        const page = setup([reply]);
        await assert.rejects(
            page.controller.request("/a/demo", {
                method: "POST",
                body: mainPayload(),
            }),
        );
        assert.equal(page.calls.length, 1);
        assert.equal(page.token.value, csrf("a"));
    }
    const page = setup([]);
    await assert.rejects(
        page.controller.request("https://foreign.test/submit", {
            method: "POST",
        }),
    );
    assert.equal(page.calls.length, 0);
    page.form.dataset.sessionUrl = "https://foreign.test/session";
    assert.throws(() => page.create(page.form));
});
