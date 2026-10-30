(() => {
    "use strict";

    for (const form of document.querySelectorAll("[data-survey-restart]")) {
        const button = form.querySelector('button[type="submit"]');
        const error = form.querySelector("[data-restart-error]");
        const followup = document.querySelector("#contact-followup-form");
        const session = globalThis.MikausisSurveySession?.forForm(form);
        let sending = false;
        const busy = () => followup?.getAttribute("aria-busy") === "true";
        const render = () => {
            button.disabled = sending || busy();
        };
        if (followup && typeof MutationObserver === "function")
            new MutationObserver(render).observe(followup, {
                attributes: true,
                attributeFilter: ["aria-busy"],
            });
        form.addEventListener("submit", async (event) => {
            event.preventDefault();
            if (sending || busy()) return;
            sending = true;
            error.hidden = true;
            form.setAttribute("aria-busy", "true");
            render();
            try {
                const send = session
                    ? session.request
                    : (...args) => fetch(...args);
                const response = await send(form.action, {
                    method: "POST",
                    credentials: "same-origin",
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    body: new FormData(form),
                });
                const result = await response.json();
                if (!response.ok) {
                    const message =
                        Object.values(result.errors || {})
                            .flat()
                            .find((value) => typeof value === "string") ||
                        (typeof result.message === "string"
                            ? result.message
                            : null);
                    if (message) throw new Error(message);
                    throw new TypeError("Restart failed");
                }
                if (typeof result.redirect !== "string")
                    throw new TypeError("Invalid restart response");
                const destination = new URL(
                    result.redirect,
                    window.location.href,
                );
                if (destination.origin !== window.location.origin)
                    throw new TypeError("Invalid restart URL");
                globalThis.MikausisSurveyDraftState?.create({
                    scope: form.dataset.draftScope,
                    definition: form.dataset.contactDefinition,
                    questions: [],
                }).clear();
                globalThis.MikausisContactFollowupState?.create({
                    scope: form.dataset.contactScope,
                    definition: form.dataset.contactDefinition,
                }).clear();
                window.location.assign(destination.href);
            } catch (failure) {
                error.textContent =
                    failure instanceof TypeError
                        ? "Neizdevās savienoties ar serveri. Pārbaudiet interneta savienojumu un mēģiniet vēlreiz."
                        : failure.message;
                error.hidden = false;
                error.focus();
            } finally {
                sending = false;
                form.removeAttribute("aria-busy");
                render();
            }
        });
        render();
    }
})();
