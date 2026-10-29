import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import vm from "node:vm";

const sources = ["shared-question-contacts.js", "survey.js"].map((name) => [
    name,
    readFileSync(new URL("../../public/js/" + name, import.meta.url), "utf8"),
]);
const camel = (name) =>
    name.replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());

// Only the DOM operations used by the runtime are implemented. Queries traverse
// the actual fixture tree, and events bubble through that tree to the form.
function matchesSimple(node, selector) {
    const conditions = [];
    selector = selector
        .replace(/:not\(:disabled\)/g, () => {
            conditions.push(!node.disabled);
            return "";
        })
        .replace(/:(checked|disabled)/g, (_, property) => {
            conditions.push(Boolean(node[property]));
            return "";
        })
        .replace(
            /\[([\w-]+)(?:([\^$*]?=)(?:"([^"]*)"|'([^']*)'|([^\]]*)))?\]/g,
            (_, name, operator, double, single, bare) => {
                const actual = node.getAttribute(name);
                const expected = double ?? single ?? bare;
                conditions.push(
                    operator === "="
                        ? actual === expected
                        : operator === "$="
                          ? actual?.endsWith(expected) === true
                          : operator === "^="
                            ? actual?.startsWith(expected) === true
                            : operator === "*="
                              ? actual?.includes(expected) === true
                              : actual !== null,
                );
                return "";
            },
        );
    selector = selector
        .replace(/#([\w-]+)/g, (_, id) => {
            conditions.push(node.id === id);
            return "";
        })
        .replace(/\.([\w-]+)/g, (_, name) => {
            conditions.push(node.className.split(/\s+/).includes(name));
            return "";
        });
    return (
        conditions.every(Boolean) &&
        (!selector ||
            selector === "*" ||
            node.tagName === selector.toUpperCase())
    );
}

function matches(node, selector) {
    return selector.split(",").some((part) => {
        const chain = part.trim().split(/\s+(?![^\[]*\])/);
        if (!matchesSimple(node, chain.pop())) return false;
        let ancestor = node.parentElement;
        while (chain.length) {
            const target = chain.pop();
            while (ancestor && !matchesSimple(ancestor, target))
                ancestor = ancestor.parentElement;
            if (!ancestor) return false;
            ancestor = ancestor.parentElement;
        }
        return true;
    });
}

class Element {
    constructor(tag, document) {
        this.tagName = tag.toUpperCase();
        this.ownerDocument = document;
        this.children = [];
        this.parentElement = null;
        this.dataset = {};
        this.attributes = new Map();
        this.listeners = new Map();
        this.style = {};
        this.hidden = this.disabled = this.required = this.checked = false;
        this.value = "";
        this.maxLength = -1;
        this._text = "";
        this.htmlWrites = [];
        this.classList = {
            add: (name) => this.classList.toggle(name, true),
            toggle: (name, force) => {
                const names = new Set(
                    this.className.split(/\s+/).filter(Boolean),
                );
                const add = force ?? !names.has(name);
                if (add) names.add(name);
                else names.delete(name);
                this.className = [...names].join(" ");
                return add;
            },
        };
    }
    get textContent() {
        return (
            this._text +
            this.children.map((child) => child.textContent).join("")
        );
    }
    set textContent(value) {
        this.replaceChildren();
        this._text = String(value);
    }
    set innerHTML(value) {
        this.htmlWrites.push(value);
        this.textContent = value;
    }
    get selectedOptions() {
        return this.children.filter((option) => option.value === this.value);
    }
    getAttribute(name) {
        if (name.startsWith("data-"))
            return this.dataset[camel(name.slice(5))] ?? null;
        return this.attributes.get(name) ?? null;
    }
    setAttribute(name, value) {
        if (name.startsWith("data-"))
            this.dataset[camel(name.slice(5))] = String(value);
        else this.attributes.set(name, String(value));
    }
    hasAttribute(name) {
        return this.getAttribute(name) !== null;
    }
    removeAttribute(name) {
        if (name.startsWith("data-")) delete this.dataset[camel(name.slice(5))];
        else this.attributes.delete(name);
    }
    append(...nodes) {
        for (const node of nodes) {
            node.remove();
            node.parentElement = this;
            this.children.push(node);
        }
    }
    replaceChildren(...nodes) {
        for (const child of this.children) child.parentElement = null;
        this.children = [];
        this._text = "";
        this.append(...nodes);
    }
    remove() {
        if (this.parentElement) {
            const siblings = this.parentElement.children;
            siblings.splice(siblings.indexOf(this), 1);
            this.parentElement = null;
        }
    }
    querySelectorAll(selector) {
        const result = [];
        const visit = (node) => {
            for (const child of node.children) {
                if (matches(child, selector)) result.push(child);
                visit(child);
            }
        };
        visit(this);
        return result;
    }
    querySelector(selector) {
        return this.querySelectorAll(selector)[0] ?? null;
    }
    closest(selector) {
        for (let node = this; node; node = node.parentElement)
            if (matches(node, selector)) return node;
        return null;
    }
    addEventListener(type, listener) {
        if (!this.listeners.has(type)) this.listeners.set(type, []);
        this.listeners.get(type).push(listener);
    }
    dispatch(type, extra = {}) {
        const event = {
            type,
            target: this,
            defaultPrevented: false,
            preventDefault() {
                this.defaultPrevented = true;
            },
            ...extra,
        };
        const results = [];
        for (let node = this; node; node = node.parentElement) {
            for (const listener of node.listeners.get(type) || [])
                results.push(listener(event));
        }
        return { event, done: Promise.all(results) };
    }
    fire(type, extra) {
        return this.dispatch(type, extra).done;
    }
    focus() {
        let visible = !this.disabled;
        for (let node = this; node; node = node.parentElement)
            visible &&= !node.hidden;
        if (visible) this.ownerDocument.activeElement = this;
    }
    scrollIntoView() {}
    checkValidity() {
        if (this.disabled) return true;
        if (
            this.required &&
            (!this.value || (this.type === "checkbox" && !this.checked))
        )
            return false;
        return (
            this.type !== "email" ||
            !this.value ||
            /^[^\s@]+@[^\s@]+\.[^\s@]+$/u.test(this.value)
        );
    }
    reportValidity() {
        this.focus();
        return this.checkValidity();
    }
}
for (const property of ["id", "name", "type", "className", "action", "href"]) {
    const attribute = property === "className" ? "class" : property;
    Object.defineProperty(Element.prototype, property, {
        get() {
            return (
                this.getAttribute(attribute) ??
                (property === "type" && this.tagName === "INPUT" ? "text" : "")
            );
        },
        set(value) {
            this.setAttribute(attribute, value);
        },
    });
}

class FakeFormData {
    constructor(form) {
        this.values = form
            .querySelectorAll("input,textarea,select")
            .filter(
                (input) =>
                    input.name &&
                    !input.disabled &&
                    !["submit", "button", "reset"].includes(input.type) &&
                    (!["checkbox", "radio"].includes(input.type) ||
                        input.checked),
            )
            .map((input) => [input.name, input.value]);
    }
    entries() {
        return this.values[Symbol.iterator]();
    }
    [Symbol.iterator]() {
        return this.entries();
    }
    get(name) {
        return this.values.find(([key]) => key === name)?.[1] ?? null;
    }
    getAll(name) {
        return this.values
            .filter(([key]) => key === name)
            .map(([, value]) => value);
    }
}

const email = (key, label = "Vecāka e-pasts") => ({
    key,
    label,
    type: "email",
    required: true,
});
const defaultDefinitions = [
    {
        key: "a",
        type: "single",
        fields: [
            email("email_a", "  Vecāka   E-PASTS  "),
            { key: "phone_a", label: "Tālrunis", type: "tel" },
            { key: "role_a", label: "Loma", type: "role" },
        ],
    },
    {
        key: "b",
        type: "multiple",
        fields: [
            email("email_b"),
            { key: "child_b", label: "Bērna vārds", type: "text" },
            { key: "role_b", label: "Loma", type: "role" },
            { key: "phone_note", label: "Tālrunis", type: "text" },
        ],
    },
];
const response = (status, body) => ({
    status,
    ok: status >= 200 && status < 300,
    json: async () => body,
});
const mainSaved = () => response(200, { redirect: "/thanks" });
const contactSaved = () => response(200, { saved: true });
const rejected = (message = "Pārbaudiet e-pastu.") =>
    response(422, { errors: { "fields.email_b": [message] } });
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
};
const flush = () => new Promise((resolve) => setImmediate(resolve));

function runtime(replies = [], definitions = defaultDefinitions) {
    const document = new Element("document", null);
    document.ownerDocument = document;
    document.createElement = (tag) => new Element(tag, document);
    document.getElementById = (id) => document.querySelector("#" + id);
    const add = (parent, tag, props = {}) => {
        const node = document.createElement(tag);
        Object.assign(node, props);
        parent.append(node);
        return node;
    };
    const form = add(document, "form", {
        id: "survey-form",
        action: "/answers",
        dataset: { sectionCount: "1" },
    });
    add(form, "input", { type: "hidden", name: "_token", value: "csrf-test" });
    const errorBox = add(form, "div", { id: "form-errors", hidden: true });
    const intro = add(form, "section", { dataset: { step: "-1" } });
    add(intro, "h1", { textContent: "Pirms sākam" });
    add(intro, "input", {
        id: "respondent-anonymous",
        type: "checkbox",
        name: "anonymous",
        checked: true,
        value: "1",
    });
    add(intro, "div", { dataset: { respondentDetails: "" } });
    add(form, "p", { dataset: { submissionNote: "" } });
    const section = add(form, "section", { dataset: { step: "0" } });
    add(section, "h2", { textContent: "Jautājumi" });
    const blocks = [];
    for (const definition of definitions) {
        const { key, type, fields } = definition;
        const question = add(section, "div", {
            id: "question-" + key,
            dataset: {
                question: key,
                title: definition.title || "Aktivitāte " + key,
                type,
            },
        });
        const options = add(question, "div", { className: "answer-options" });
        for (const value of ["yes", "maybe", "unrelated"]) {
            const label = add(options, "label");
            add(label, "input", {
                type: type === "multiple" ? "checkbox" : "radio",
                name:
                    "answers[" + key + "]" + (type === "multiple" ? "[]" : ""),
                value,
                checked: value === "yes" || type === "multiple",
            });
            add(label, "span", { textContent: value });
        }
        add(question, "p", { hidden: true, dataset: { fieldError: "" } });
        const block = add(question, "div", {
            dataset: {
                questionContact: "",
                triggers: JSON.stringify(
                    definition.triggerValues || ["yes", "maybe"],
                ),
                contactId: "server-uuid-" + key,
                endpoint: "/contacts/" + key,
                contactPrompt:
                    definition.prompt || "Sazinieties ar mani par " + key,
                contactPurpose: definition.purpose || "Organizēšanai " + key,
            },
        });
        blocks.push(block);
        const consentLabel = add(block, "label");
        add(consentLabel, "input", {
            name: "contacts[" + key + "][consent]",
            type: "checkbox",
            checked: true,
            dataset: { contactConsent: "" },
        });
        add(block, "p", { id: key + "-purpose" });
        add(block, "p", { dataset: { contactMode: "" }, hidden: true });
        const details = add(block, "div", { dataset: { contactDetails: "" } });
        for (const field of fields)
            add(details, field.type === "role" ? "select" : "input", {
                name: "contacts[" + key + "][fields][" + field.key + "]",
                type: field.type,
                value: "stale-secret-" + field.key,
                required: true,
                maxLength: field.maxLength ?? 160,
                dataset: {
                    contactField: field.key,
                    contactLabel: field.label,
                    contactType: field.type,
                    required: String(field.required === true),
                },
            });
    }
    const review = add(form, "section", { dataset: { step: "1" } });
    add(review, "h2", { id: "review-title" });
    add(review, "div", { id: "review-answers" });
    const panel = add(review, "section", { dataset: { sharedContacts: "" } });
    add(panel, "h3", { id: "shared-contacts-title" });
    const consentLabel = add(panel, "label");
    const consent = add(consentLabel, "input", {
        type: "checkbox",
        dataset: { sharedContactConsent: "" },
    });
    add(consentLabel, "span", { id: "shared-contact-prompt" });
    const details = add(panel, "div", {
        dataset: { sharedContactDetails: "" },
    });
    add(details, "p", {
        id: "shared-contacts-purpose",
        dataset: { sharedContactPurpose: "" },
        hidden: true,
    });
    add(details, "p", {
        id: "shared-contact-activities-label",
        textContent: "Kontaktinformācija paredzēta šīm aktivitātēm:",
    });
    const activities = add(details, "ul", {
        dataset: { sharedContactActivities: "" },
    });
    activities.setAttribute(
        "aria-labelledby",
        "shared-contact-activities-label",
    );
    const fields = add(details, "div", {
        dataset: { sharedContactFields: "" },
    });
    add(details, "p", { dataset: { sharedContactStatus: "" } });
    const skip = add(review, "a", {
        dataset: { skipContacts: "" },
        hidden: true,
    });
    const submit = add(review, "button", {
        id: "submit-button",
        type: "submit",
    });
    const actions = add(form, "div", { className: "step-actions" });
    add(actions, "button", { type: "button", dataset: { next: "" } });
    add(actions, "button", { type: "button", dataset: { previous: "" } });
    add(form, "div", { className: "mobile-progress" });
    for (const id of ["mobile-step-label", "step-fraction", "progress-fill"])
        add(form, "span", { id });
    const navigation = add(form, "button", {
        type: "button",
        dataset: { goStep: "1" },
    });
    const calls = [],
        redirects = [],
        queue = [...replies];
    const windowEvents = new Element("window", document);
    const context = vm.createContext({
        document,
        FormData: FakeFormData,
        matchMedia: () => ({ matches: true }),
        addEventListener: windowEvents.addEventListener.bind(windowEvents),
        location: { assign: (url) => redirects.push(url) },
        fetch: (url, options) => {
            calls.push({ url, ...options });
            assert.ok(queue.length, "Unexpected fetch: " + url);
            const reply = queue.shift();
            return reply instanceof Error
                ? Promise.reject(reply)
                : Promise.resolve(reply);
        },
    });
    context.window = context;
    for (const [filename, source] of sources)
        vm.runInContext(source, context, { filename });
    return {
        document,
        form,
        errorBox,
        panel,
        consent,
        activities,
        fields,
        blocks,
        review,
        skip,
        submit,
        calls,
        redirects,
        post: () => form.fire("submit"),
        goReview: () => navigation.fire("click"),
        optIn: async () => {
            consent.checked = true;
            await consent.fire("change");
        },
        input(label, type = "email") {
            const id = JSON.stringify([
                label.trim().replace(/\s+/gu, " ").toLowerCase(),
                type,
            ]);
            const node = fields
                .querySelectorAll("input,select")
                .find((input) => input.dataset.sharedContactInput === id);
            assert.ok(node, "Missing shared field " + id);
            return node;
        },
        async fill(label, value, type = "email") {
            const node = this.input(label, type);
            assert.equal(node.disabled, false);
            node.value = value;
            await node.fire("input");
            return node;
        },
        beforeUnload: () => windowEvents.dispatch("beforeunload").event,
    };
}

function assertShadows(fixture) {
    for (const block of fixture.blocks) {
        assert.equal(block.hidden, true, "Per-question contacts stay hidden");
        assert.equal(block.closest("#survey-form"), fixture.form);
        const consent = block.querySelector("[data-contact-consent]");
        assert.equal(consent.name, "");
        assert.equal(consent.disabled, true);
        assert.equal(consent.closest("label").hidden, true);
        assert.equal(
            block.querySelector("[data-contact-details]").hidden,
            true,
        );
        for (const field of block.querySelectorAll("[data-contact-field]")) {
            assert.equal(field.name, "");
            assert.equal(field.disabled, true);
            assert.equal(field.required, false);
        }
    }
}
function assertCollectorLocked(fixture) {
    const controls = fixture.panel.querySelectorAll("input,select");
    assert.ok(controls.length >= 3);
    assert.ok(
        controls.every((input) => input.disabled),
        "Global consent and every shared field must be locked",
    );
    assert.equal(fixture.submit.disabled, true);
}
const contactCalls = (fixture) =>
    fixture.calls.filter(({ url }) => url.startsWith("/contacts/"));

test("common wording appears once with an informational activity list and one global checkbox", async () => {
    const prompt = "Vēlos atstāt kontaktus izvēlētajām aktivitātēm";
    const purpose = "Saziņai par izvēlētajām aktivitātēm.";
    const fixture = runtime(
        [],
        defaultDefinitions.map((definition) => ({
            ...definition,
            prompt,
            purpose,
        })),
    );
    await fixture.optIn();
    assert.equal(
        fixture.document.getElementById("shared-contact-prompt").textContent,
        prompt,
    );
    assert.equal(
        fixture.document.getElementById("shared-contacts-purpose").textContent,
        purpose,
    );
    assert.equal(
        fixture.document.getElementById("shared-contacts-purpose").hidden,
        false,
    );
    assert.equal(fixture.activities.tagName, "UL");
    assert.equal(
        fixture.activities.getAttribute("aria-labelledby"),
        "shared-contact-activities-label",
    );
    assert.equal(
        fixture.panel.querySelectorAll('input[type="checkbox"]').length,
        1,
    );
    assert.equal(
        fixture.activities.querySelectorAll(
            "input,label,select,button,textarea",
        ).length,
        0,
    );
    const rows = fixture.activities.querySelectorAll(
        "[data-shared-contact-activity]",
    );
    assert.equal(rows.length, 2);
    for (const [index, wrapper] of rows.entries()) {
        assert.equal(wrapper.tagName, "LI");
        assert.equal(wrapper.querySelector("h4").hidden, false);
        assert.equal(
            wrapper.querySelector("h4").textContent,
            "Aktivitāte " + (index ? "b" : "a"),
        );
        assert.ok(
            wrapper
                .querySelectorAll("p")
                .every((paragraph) => paragraph.hidden),
        );
    }
    assert.equal(fixture.panel.textContent.split(prompt).length - 1, 1);
    assert.equal(fixture.panel.textContent.split(purpose).length - 1, 1);
    assert.equal(fixture.calls.length, 0);
    const legacy = runtime();
    await legacy.optIn();
    assert.equal(
        legacy.document.getElementById("shared-contact-prompt").textContent,
        "Atstāt kontaktinformāciju",
    );
    assert.equal(
        legacy.document.getElementById("shared-contacts-purpose").hidden,
        true,
    );
    for (const wrapper of legacy.activities.querySelectorAll(
        "[data-shared-contact-activity]",
    )) {
        assert.equal(wrapper.querySelector("h4").hidden, false);
        assert.equal(wrapper.querySelector("p").hidden, false);
        const key = wrapper.dataset.sharedContactActivity;
        assert.equal(
            wrapper.querySelectorAll("p")[0].textContent,
            "Sazinieties ar mani par " + key,
        );
        assert.equal(
            wrapper.querySelectorAll("p")[1].textContent,
            "Organizēšanai " + key,
        );
    }
    assert.equal(legacy.activities.querySelectorAll("input,label").length, 0);
});

test("answer selection alone never opts in or posts contacts, and legacy controls remain nameless disabled shadows", async () => {
    const fixture = runtime([mainSaved()]);
    assert.equal(fixture.consent.checked, false);
    assert.equal(fixture.panel.hidden, false);
    assert.equal(fixture.fields.querySelectorAll("input,select").length, 0);
    assert.equal(
        fixture.panel.querySelectorAll('input[type="checkbox"]').length,
        1,
    );
    assert.equal(fixture.activities.querySelectorAll("input,label").length, 0);
    assertShadows(fixture);
    assert.ok(
        fixture.blocks
            .flatMap((block) => block.querySelectorAll("[data-contact-field]"))
            .every((field) => field.value === ""),
    );
    await fixture.post();
    assert.equal(fixture.calls.length, 1);
    assert.deepEqual(
        [...fixture.calls[0].body],
        [
            ["_token", "csrf-test"],
            ["anonymous", "1"],
            ["answers[a]", "yes"],
            ["answers[b][]", "yes"],
            ["answers[b][]", "maybe"],
            ["answers[b][]", "unrelated"],
        ],
    );
    assert.deepEqual(fixture.redirects, ["/thanks"]);
});

test("activity titles, legacy disclosures, field labels and server errors are rendered only as plaintext", async () => {
    const title = '<img src=x onerror="alert(1)">';
    const prompt = '<script>alert("prompt")</script>';
    const purpose = '<b onclick="alert(2)">Purpose</b>';
    const label = '<svg onload="alert(3)">';
    const message = '<iframe src="javascript:alert(4)"></iframe>';
    const fixture = runtime(
        [mainSaved(), rejected(message), contactSaved()],
        [
            {
                key: "a",
                type: "single",
                title,
                prompt,
                purpose,
                fields: [email("email_a", label)],
            },
            { key: "b", type: "single", fields: [email("email_b", label)] },
        ],
    );
    await fixture.optIn();
    const row = fixture.activities.querySelector(
        '[data-shared-contact-activity="a"]',
    );
    assert.equal(row.querySelector("h4").textContent, title);
    assert.equal(row.querySelectorAll("p")[0].textContent, prompt);
    assert.equal(row.querySelectorAll("p")[1].textContent, purpose);
    assert.ok(
        fixture.fields.querySelector("label").textContent.startsWith(label),
    );
    await fixture.fill(label, "parent@example.test");
    await fixture.post();
    assert.equal(row.querySelector('[role="status"]').textContent, message);
    assert.equal(
        fixture.panel.querySelectorAll("img,script,b,svg,iframe").length,
        0,
    );
    assert.ok(
        fixture.panel
            .querySelectorAll("*")
            .every((node) => node.htmlWrites.length === 0),
    );
});

test("one global opt-in automatically posts all five eligible activities with separate IDs and exact field schemas", async () => {
    const definitions = Array.from({ length: 5 }, (_, index) => ({
        key: "activity" + index,
        type: index % 2 ? "multiple" : "single",
        fields: [
            email("email_" + index),
            { key: "note_" + index, label: "Piezīme", type: "text" },
        ],
    }));
    const fixture = runtime(
        [mainSaved(), ...definitions.map(() => contactSaved())],
        definitions,
    );
    assert.equal(fixture.consent.checked, false);
    assert.equal(fixture.fields.querySelectorAll("input,select").length, 0);
    await fixture.optIn();
    assert.equal(
        fixture.panel.querySelectorAll('input[type="checkbox"]').length,
        1,
    );
    assert.equal(fixture.activities.querySelectorAll("li").length, 5);
    assert.equal(
        fixture.activities.querySelectorAll(
            "input,label,select,button,textarea",
        ).length,
        0,
    );
    assert.equal(fixture.fields.querySelectorAll("input,select").length, 2);
    await fixture.fill("Vecāka e-pasts", "all-five@example.test");
    await fixture.fill("Piezīme", "Shared private note", "text");
    await fixture.post();
    assert.equal(fixture.calls.length, 6);
    assert.doesNotMatch(
        JSON.stringify([...fixture.calls[0].body]),
        /contacts|all-five@|Shared private note|stale-secret/,
    );
    const calls = contactCalls(fixture);
    assert.equal(
        new Set(calls.map(({ body }) => JSON.parse(body).contact_id)).size,
        5,
    );
    for (const [index, call] of calls.entries()) {
        assert.equal(call.url, "/contacts/activity" + index);
        assert.deepEqual(JSON.parse(call.body), {
            contact_id: "server-uuid-activity" + index,
            consent: true,
            answer: index % 2 ? ["yes", "maybe"] : "yes",
            fields: {
                ["email_" + index]: "all-five@example.test",
                ["note_" + index]: "Shared private note",
            },
        });
    }
    assertShadows(fixture);
    assert.deepEqual(fixture.redirects, ["/thanks"]);
});

test("unrelated choices preserve opt-in, but a changed triggering selection clears values and requires the global checkbox again", async () => {
    const fixture = runtime([mainSaved(), contactSaved(), contactSaved()]);
    await fixture.optIn();
    await fixture.fill("Vecāka e-pasts", "old-scope@example.test");
    const answers = fixture.blocks[1]
        .closest("[data-question]")
        .querySelectorAll(".answer-options input");
    const unrelated = answers.find((input) => input.value === "unrelated");
    unrelated.checked = false;
    await unrelated.fire("change");
    assert.equal(fixture.consent.checked, true);
    assert.equal(
        fixture.input("Vecāka e-pasts").value,
        "old-scope@example.test",
    );
    const maybe = answers.find((input) => input.value === "maybe");
    maybe.checked = false;
    await maybe.fire("change");
    assert.equal(fixture.consent.checked, false);
    assert.equal(fixture.fields.querySelectorAll("input,select").length, 0);
    assert.equal(fixture.activities.querySelectorAll("li").length, 2);
    assert.ok(
        fixture.blocks
            .flatMap((block) => block.querySelectorAll("[data-contact-field]"))
            .every((field) => field.value === ""),
    );
    assert.equal(fixture.calls.length, 0);
    await fixture.optIn();
    assert.equal(fixture.input("Vecāka e-pasts").value, "");
    await fixture.fill("Vecāka e-pasts", "current-scope@example.test");
    await fixture.post();
    const payloads = contactCalls(fixture).map(({ body }) => JSON.parse(body));
    assert.equal(payloads[0].fields.email_a, "current-scope@example.test");
    assert.equal(payloads[1].fields.email_b, "current-scope@example.test");
    assert.deepEqual(payloads[1].answer, ["yes"]);
    assert.doesNotMatch(
        JSON.stringify(fixture.calls.map(({ body }) => body)),
        /old-scope@/,
    );
});

test("answer changes and review navigation keep legacy blocks hidden while the final activity list updates", async () => {
    const fixture = runtime();
    await fixture.optIn();
    await fixture.fill("Vecāka e-pasts", "parent@example.test");
    await fixture.goReview();
    assert.equal(fixture.review.hidden, false);
    assertShadows(fixture);
    const answers = fixture.blocks[0]
        .closest("[data-question]")
        .querySelectorAll(".answer-options input");
    for (const value of ["unrelated", "yes"]) {
        for (const input of answers) input.checked = input.value === value;
        await answers.find((input) => input.checked).fire("change");
        assertShadows(fixture);
        assert.equal(fixture.consent.checked, false);
        assert.equal(fixture.fields.querySelectorAll("input,select").length, 0);
        assert.equal(
            fixture.activities.querySelectorAll(
                "[data-shared-contact-activity]",
            ).length,
            value === "yes" ? 2 : 1,
        );
        await fixture.optIn();
        assert.equal(fixture.input("Vecāka e-pasts").value, "");
        await fixture.fill("Vecāka e-pasts", "new@example.test");
    }
    assert.equal(fixture.calls.length, 0);
});

test("one visible nameless field per normalized label/type scatters only each endpoint's exact whitelist and server ID", async () => {
    const main = deferred();
    const fixture = runtime([main.promise, contactSaved(), contactSaved()]);
    await fixture.optIn();
    const shared = fixture.fields.querySelectorAll("input,select");
    assert.equal(
        shared.length,
        5,
        "Matching emails and roles merge; same-label text and tel remain independent",
    );
    assert.equal(
        new Set(shared.map((field) => field.dataset.sharedContactInput)).size,
        5,
    );
    for (const input of fixture.panel.querySelectorAll("input,select")) {
        assert.equal(input.name, "");
        assert.equal(input.closest("[data-question]"), null);
    }
    await fixture.fill("Vecāka e-pasts", "parent@example.test");
    await fixture.fill("Tālrunis", "+371 20000000", "tel");
    await fixture.fill("Loma", "guardian", "role");
    await fixture.fill("Bērna vārds", "Child private", "text");
    await fixture.fill("Tālrunis", "Phone note private", "text");
    assertShadows(fixture);
    const posting = fixture.post();
    assert.equal(fixture.calls[0].body instanceof FakeFormData, true);
    const entries = [...fixture.calls[0].body];
    assert.deepEqual(
        entries.map(([name]) => name),
        [
            "_token",
            "anonymous",
            "answers[a]",
            "answers[b][]",
            "answers[b][]",
            "answers[b][]",
        ],
    );
    assert.doesNotMatch(
        JSON.stringify(entries),
        /contacts|parent@|20000000|guardian|Child private|Phone note private|stale-secret/,
    );
    assertCollectorLocked(fixture);
    const pendingInput = fixture.input("Vecāka e-pasts");
    pendingInput.value = "changed-during-main@example.test";
    await pendingInput.fire("input");
    fixture.consent.checked = false;
    await fixture.consent.fire("change");
    assert.equal(pendingInput.value, "parent@example.test");
    assert.equal(fixture.consent.checked, true);
    assertCollectorLocked(fixture);
    assert.ok(
        fixture.form
            .querySelectorAll("input[name],textarea[name],select[name]")
            .every((input) => input.disabled),
        "Synthetic collector events cannot re-enable main answers during submission",
    );
    main.resolve(mainSaved());
    await posting;
    const contacts = contactCalls(fixture);
    assert.deepEqual(
        contacts.map(({ url }) => url),
        ["/contacts/a", "/contacts/b"],
    );
    assert.deepEqual(
        contacts.map(({ body }) => JSON.parse(body)),
        [
            {
                contact_id: "server-uuid-a",
                consent: true,
                answer: "yes",
                fields: {
                    email_a: "parent@example.test",
                    phone_a: "+371 20000000",
                    role_a: "guardian",
                },
            },
            {
                contact_id: "server-uuid-b",
                consent: true,
                answer: ["yes", "maybe"],
                fields: {
                    email_b: "parent@example.test",
                    child_b: "Child private",
                    role_b: "guardian",
                    phone_note: "Phone note private",
                },
            },
        ],
    );
    for (const call of contacts) {
        assert.equal(call.method, "POST");
        assert.equal(call.credentials, "same-origin");
        assert.deepEqual(
            { ...call.headers },
            {
                Accept: "application/json",
                "Content-Type": "application/json",
                "X-CSRF-TOKEN": "csrf-test",
            },
        );
    }
    assertShadows(fixture);
    assert.deepEqual(fixture.redirects, ["/thanks"]);
});

test("all collector controls stay locked across sequential requests and duplicate submits do not start more requests", async () => {
    const a = deferred(),
        b = deferred();
    const fixture = runtime([mainSaved(), a.promise, b.promise]);
    await fixture.optIn();
    await fixture.fill("Vecāka e-pasts", "parent@example.test");
    const posting = fixture.post();
    await flush();
    assert.equal(contactCalls(fixture).length, 1);
    assertCollectorLocked(fixture);
    await fixture.post();
    assert.equal(fixture.calls.length, 2);
    a.resolve(contactSaved());
    await flush();
    assert.equal(contactCalls(fixture).length, 2);
    assertCollectorLocked(fixture);
    b.resolve(contactSaved());
    await posting;
    assert.deepEqual(fixture.redirects, ["/thanks"]);
});

test("an ambiguous request retries byte-identical JSON, even after a later 422, and never reposts the main answers or saved activity", async () => {
    const a = deferred();
    const fixture = runtime([
        mainSaved(),
        a.promise,
        contactSaved(),
        rejected(),
        contactSaved(),
    ]);
    await fixture.optIn();
    await fixture.fill("Vecāka e-pasts", "original@example.test");
    const posting = fixture.post();
    await flush();
    a.reject(new TypeError("Connection lost after possible write"));
    await posting;
    assert.equal(fixture.redirects.length, 0);
    assert.ok(
        fixture.panel
            .querySelectorAll("input,select")
            .every((input) => input.disabled),
    );
    assert.equal(
        fixture.submit.disabled,
        false,
        "An uncertain result must still allow retry",
    );
    assert.equal(fixture.skip.hidden, false);
    assert.equal(fixture.skip.href, "/thanks");
    const original = contactCalls(fixture)[0].body;
    // Dispatching synthetic input cannot mutate a pending snapshot either.
    const input = fixture.input("Vecāka e-pasts");
    input.value = "changed@example.test";
    await input.fire("input");
    fixture.consent.checked = false;
    await fixture.consent.fire("change");
    await fixture.post();
    assert.equal(contactCalls(fixture)[2].body, original);
    assert.equal(input.value, "original@example.test");
    assert.equal(
        input.disabled,
        true,
        "422 cannot unlock a request whose earlier result was ambiguous",
    );
    await fixture.post();
    assert.equal(contactCalls(fixture)[3].body, original);
    assert.deepEqual(
        fixture.calls.map(({ url }) => url),
        [
            "/answers",
            "/contacts/a",
            "/contacts/b",
            "/contacts/a",
            "/contacts/a",
        ],
    );
    assert.deepEqual(fixture.redirects, ["/thanks"]);
});

test("partial success keeps main answers locked, permits correction of an initial 422, and never reposts saved A", async () => {
    const fixture = runtime([
        mainSaved(),
        contactSaved(),
        rejected(),
        contactSaved(),
    ]);
    await fixture.optIn();
    await fixture.fill("Vecāka e-pasts", "original@example.test");
    await fixture.post();
    assert.equal(fixture.review.hidden, false);
    assert.equal(fixture.redirects.length, 0);
    assert.ok(
        fixture.form
            .querySelectorAll("input[name],textarea[name],select[name]")
            .every((input) => input.disabled),
    );
    assert.equal(fixture.input("Vecāka e-pasts").disabled, false);
    assert.equal(fixture.activities.querySelectorAll("input,label").length, 0);
    const saved = fixture.activities.querySelector(
        '[data-shared-contact-activity="a"]',
    );
    const failed = fixture.activities.querySelector(
        '[data-shared-contact-activity="b"]',
    );
    assert.equal(saved.querySelector("h4").textContent, "Aktivitāte a");
    assert.equal(saved.querySelector('[role="status"]').dataset.state, "saved");
    assert.match(
        saved.querySelector('[role="status"]').textContent,
        /saglabāta/,
    );
    assert.equal(failed.querySelector("h4").textContent, "Aktivitāte b");
    assert.equal(
        failed.querySelector('[role="status"]').dataset.state,
        "error",
    );
    assert.equal(
        failed.querySelector('[role="status"]').textContent,
        "Pārbaudiet e-pastu.",
    );
    assert.equal(fixture.blocks[0].dataset.saved, "true");
    assertShadows(fixture);
    const link = fixture.errorBox.querySelector("[data-error-contacts]");
    assert.ok(link);
    assert.equal(link.href, "#shared-contacts-title");
    await link.fire("click");
    assert.equal(
        fixture.document.activeElement,
        fixture.input("Vecāka e-pasts"),
    );
    assert.equal(fixture.document.activeElement.disabled, false);
    assert.equal(fixture.review.hidden, false);
    await fixture.fill("Vecāka e-pasts", "corrected@example.test");
    await fixture.post();
    assert.deepEqual(
        fixture.calls.map(({ url }) => url),
        ["/answers", "/contacts/a", "/contacts/b", "/contacts/b"],
    );
    assert.equal(
        JSON.parse(contactCalls(fixture)[0].body).fields.email_a,
        "original@example.test",
    );
    assert.equal(
        JSON.parse(contactCalls(fixture)[2].body).fields.email_b,
        "corrected@example.test",
    );
    assert.deepEqual(fixture.redirects, ["/thanks"]);
});

test("contact validation focuses an enabled shared select on review; initial rejection still offers skip without an unload warning", async () => {
    const fixture = runtime(
        [mainSaved(), rejected("Izvēlieties lomu.")],
        [
            {
                key: "a",
                type: "single",
                fields: [
                    {
                        key: "role",
                        label: "Loma",
                        type: "role",
                        required: true,
                    },
                ],
            },
        ],
    );
    await fixture.optIn();
    await fixture.post();
    assert.equal(
        fixture.calls.length,
        0,
        "Invalid contact consent must not submit answers or contacts",
    );
    const select = fixture.input("Loma", "role");
    assert.equal(select.tagName, "SELECT");
    assert.equal(select.disabled, false);
    assert.equal(select.getAttribute("aria-invalid"), "true");
    assert.equal(fixture.document.activeElement, select);
    assert.equal(fixture.review.hidden, false);
    await fixture.errorBox.querySelector("[data-error-contacts]").fire("click");
    assert.equal(fixture.document.activeElement, select);
    await fixture.fill("Loma", "guardian", "role");
    assert.equal(select.hasAttribute("aria-invalid"), false);
    await fixture.post();
    await fixture.errorBox.querySelector("[data-error-contacts]").fire("click");
    assert.equal(fixture.document.activeElement, select);
    assert.equal(select.disabled, false);
    assert.equal(fixture.skip.hidden, false);
    assert.equal(fixture.skip.href, "/thanks");
    assert.equal(fixture.beforeUnload().defaultPrevented, true);
    await fixture.skip.fire("click");
    assert.equal(fixture.beforeUnload().defaultPrevented, false);
    assert.equal(
        fixture.calls.length,
        2,
        "Skipping must not start another contact request",
    );
});
