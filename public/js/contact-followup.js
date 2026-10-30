(() => {
    "use strict";

    const form = document.querySelector("#contact-followup-form");
    if (!form) return;
    const intro = form.querySelector("[data-followup-intro]");
    const titles = form.querySelector("[data-followup-questions]");
    const errors = form.querySelector("[data-followup-errors]");
    const submit = form.querySelector("#followup-submit");
    const status = document.querySelector("[data-followup-status]");
    form.hidden = true;
    if (intro) intro.hidden = true;
    if (submit) submit.hidden = true;
    if (
        !globalThis.MikausisContactFollowupState ||
        !globalThis.MikausisContactCollector
    )
        return;

    let definitions;
    let cache;
    try {
        definitions = JSON.parse(
            document.querySelector("#contact-followup-definitions")
                ?.textContent || "null",
        );
        if (!Array.isArray(definitions)) return;
        cache = globalThis.MikausisContactFollowupState.create({
            scope: form.dataset.contactScope,
            definition: form.dataset.contactDefinition,
            definitions,
        });
    } catch {
        return;
    }
    const available = cache
        .entries()
        .filter((entry) => entry.status === "available");
    if (!available.length) return;
    const selected = new Map(available.map((entry) => [entry.key, entry]));
    const current = definitions
        .filter((definition) => selected.has(definition.key))
        .map((definition) => ({
            ...definition,
            contactId: selected.get(definition.key).contactId,
        }));
    let sending = false;
    let completed = false;

    function showErrors(messages) {
        if (!errors) return;
        errors.replaceChildren();
        const list = document.createElement("ul");
        for (const message of messages) {
            const item = document.createElement("li");
            item.textContent = message;
            list.append(item);
        }
        errors.append(list);
        errors.hidden = messages.length === 0;
        if (messages.length) errors.focus();
    }

    function updateFollowup() {
        const remaining = collector.state
            .activities()
            .filter((activity) => !activity.saved);
        const offerable = new Set(
            cache
                .entries()
                .filter((entry) => entry.status === "available")
                .map((entry) => entry.key),
        );
        if (titles) {
            titles.replaceChildren();
            for (const activity of remaining.filter((item) =>
                offerable.has(item.key),
            )) {
                const item = document.createElement("li");
                item.textContent = activity.title;
                titles.append(item);
            }
        }
        if (intro) intro.hidden = completed || !titles?.children.length;
        if (submit) {
            submit.hidden = completed || !collector.state.globalConsent;
            submit.disabled = sending;
        }
    }

    function render() {
        collector.render();
        updateFollowup();
    }

    let collector;
    try {
        collector = globalThis.MikausisContactCollector.create({
            form,
            definitions: current,
            isSending: () => sending,
            onChange: updateFollowup,
            onErrors: showErrors,
            onFocus: () => collector.focus(),
            onPrepared: (keys) => cache.mark(keys, "uncertain"),
            onSettled: (key, outcome) => cache.mark([key], outcome),
        });
        if (!collector.state) return;
        collector.state.syncSelections(
            available.map((entry) => ({
                key: entry.key,
                values: entry.values,
            })),
        );
    } catch {
        return;
    }

    form.noValidate = true;
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (
            sending ||
            document.querySelector('[data-survey-restart][aria-busy="true"]') ||
            completed ||
            !collector.state.globalConsent ||
            !collector.validate()
        )
            return;
        sending = true;
        showErrors([]);
        form.setAttribute("aria-busy", "true");
        render();
        try {
            const result = await collector.save();
            if (!result.ok) {
                showErrors(result.errors);
                if (submit)
                    submit.textContent =
                        "Pārbaudīt / atkārtot kontaktu nosūtīšanu";
                return;
            }
            completed = true;
            form.hidden = true;
            if (intro) intro.hidden = true;
            if (status) {
                status.hidden = false;
                status.focus();
                status.scrollIntoView({ block: "center" });
            }
        } finally {
            sending = false;
            form.removeAttribute("aria-busy");
            render();
        }
    });
    form.hidden = false;
    render();
})();
