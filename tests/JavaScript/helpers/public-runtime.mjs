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

export class Element {
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

export class FakeFormData {
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
    has(name) {
        return this.values.some(([key]) => key === name);
    }
    set(name, value) {
        this.values = this.values.filter(([key]) => key !== name);
        this.values.push([name, value]);
    }
}
