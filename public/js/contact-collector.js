(() => {
    "use strict";

    function create({
        form,
        definitions,
        isSending = () => false,
        onChange = () => {},
        onErrors = () => {},
        onFocus = () => {},
        onPrepared = () => {},
        onSettled = () => {},
    }) {
        const session = globalThis.MikausisSurveySession?.forForm(form);
        const roleLabels = {
            mother: "Mamma",
            father: "Tētis",
            guardian: "Cits aizbildnis",
        };
        const sharedPanel = form.querySelector("[data-shared-contacts]");
        const sharedConsent = sharedPanel?.querySelector(
            "[data-shared-contact-consent]",
        );
        const sharedPrompt = sharedPanel?.querySelector(
            "#shared-contact-prompt",
        );
        const sharedPurpose = sharedPanel?.querySelector(
            "[data-shared-contact-purpose]",
        );
        const sharedDetails = sharedPanel?.querySelector(
            "[data-shared-contact-details]",
        );
        const sharedActivities = sharedPanel?.querySelector(
            "[data-shared-contact-activities]",
        );
        const sharedFields = sharedPanel?.querySelector(
            "[data-shared-contact-fields]",
        );
        const sharedStatus = sharedPanel?.querySelector(
            "[data-shared-contact-status]",
        );
        const sharedContacts =
            sharedPanel && globalThis.MikausisSharedContacts
                ? globalThis.MikausisSharedContacts.create(definitions)
                : null;
        const activityNodes = new Map();
        const fieldNodes = new Map();
        const contactErrors = new Map();
        let sharedFieldSequence = 0;

        function renderSharedContacts() {
            if (!sharedContacts) return;
            const activities = sharedContacts
                .activities()
                .filter(
                    (activity) =>
                        activity.active || activity.saved || activity.pending,
                );
            const busy = isSending() || sharedContacts.locked;
            const commonPrompt =
                activities.length &&
                activities.every(
                    (activity) => activity.prompt === activities[0].prompt,
                )
                    ? activities[0].prompt
                    : "";
            const commonPurpose =
                activities.length &&
                activities.every(
                    (activity) => activity.purpose === activities[0].purpose,
                )
                    ? activities[0].purpose
                    : "";
            if (sharedPrompt)
                sharedPrompt.textContent =
                    commonPrompt || "Atstāt kontaktinformāciju";
            if (sharedPurpose) {
                sharedPurpose.textContent = commonPurpose;
                sharedPurpose.hidden = !commonPurpose;
            }
            sharedPanel.hidden = activities.length === 0;
            sharedConsent.checked = sharedContacts.globalConsent;
            sharedConsent.disabled =
                busy || !activities.some((activity) => !activity.saved);
            sharedDetails.hidden =
                !sharedContacts.globalConsent &&
                !activities.some(
                    (activity) => activity.saved || activity.pending,
                );

            const activeKeys = new Set(
                activities.map((activity) => activity.key),
            );
            for (const [key, node] of activityNodes) {
                if (!activeKeys.has(key)) {
                    node.wrapper.remove();
                    activityNodes.delete(key);
                }
            }
            for (const activity of activities) {
                let node = activityNodes.get(activity.key);
                if (!node) {
                    const wrapper = document.createElement("li");
                    wrapper.className = "shared-contact-activity";
                    wrapper.dataset.sharedContactActivity = activity.key;
                    const title = document.createElement("h4");
                    const prompt = document.createElement("p");
                    prompt.className = "field-help";
                    const purpose = document.createElement("p");
                    purpose.className = "field-help";
                    const status = document.createElement("p");
                    status.className = "field-help";
                    status.setAttribute("role", "status");
                    wrapper.append(title, prompt, purpose, status);
                    sharedActivities.append(wrapper);
                    node = { wrapper, title, prompt, purpose, status };
                    activityNodes.set(activity.key, node);
                }
                node.title.textContent = activity.title;
                node.prompt.textContent = commonPrompt ? "" : activity.prompt;
                node.prompt.hidden = !node.prompt.textContent;
                node.purpose.textContent =
                    commonPurpose && sharedPurpose ? "" : activity.purpose;
                node.purpose.hidden = !node.purpose.textContent;
                node.status.textContent = activity.saved
                    ? "Kontaktinformācija šai aktivitātei ir saglabāta atsevišķi; to vairs nemainām."
                    : activity.uncertain
                      ? "Saglabāšanas rezultāts vēl nav zināms. Atkārtoti nosūtīsim tieši tās pašas vērtības; labošana pagaidām ir apturēta."
                      : contactErrors.get(activity.key) ||
                        (activity.pending
                            ? "Saglabājam kontaktinformāciju…"
                            : "");
                node.status.dataset.state = activity.saved
                    ? "saved"
                    : activity.pending
                      ? "pending"
                      : contactErrors.has(activity.key)
                        ? "error"
                        : "ready";
                node.status.hidden = !node.status.textContent;
            }

            const fields = sharedContacts.fields();
            const needed = new Set(fields.map((field) => field.id));
            for (const [id, node] of fieldNodes) {
                if (!needed.has(id)) {
                    node.input.value = "";
                    node.wrapper.remove();
                    fieldNodes.delete(id);
                }
            }
            for (const field of fields) {
                let node = fieldNodes.get(field.id);
                if (!node) {
                    const wrapper = document.createElement("div");
                    wrapper.className = "respondent-field";
                    const label = document.createElement("label");
                    const input = document.createElement(
                        field.type === "role" ? "select" : "input",
                    );
                    input.id = "shared-contact-field-" + ++sharedFieldSequence;
                    input.dataset.sharedContactInput = field.id;
                    label.htmlFor = input.id;
                    if (field.type === "role") {
                        for (const [value, text] of Object.entries({
                            "": "Izvēlieties lomu",
                            ...roleLabels,
                        })) {
                            const option = document.createElement("option");
                            option.value = value;
                            option.textContent = text;
                            input.append(option);
                        }
                    } else input.type = field.type;
                    input.setAttribute(
                        "aria-describedby",
                        "shared-contacts-help",
                    );
                    const read = () => {
                        if (isSending() || sharedContacts.locked) {
                            renderSharedContacts();
                            return;
                        }
                        sharedContacts.setValue(field.id, input.value);
                        input.removeAttribute("aria-invalid");
                        onChange();
                        renderSharedContacts();
                    };
                    input.addEventListener("input", read);
                    input.addEventListener("change", read);
                    wrapper.append(label, input);
                    sharedFields.append(wrapper);
                    node = { wrapper, label, input };
                    fieldNodes.set(field.id, node);
                }
                node.label.textContent = field.displayLabel || field.label;
                if (field.required) {
                    const required = document.createElement("span");
                    required.className = "required-label";
                    required.textContent =
                        " Obligāti, ja atstājat kontaktinformāciju";
                    node.label.append(required);
                }
                node.input.required = field.required;
                node.input.disabled = busy;
                if (field.maxLength !== null)
                    node.input.maxLength = field.maxLength;
                if (node.input.value !== field.value)
                    node.input.value = field.value;
            }
            const saved = activities.filter(
                (activity) => activity.saved,
            ).length;
            const pending = activities.filter(
                (activity) => activity.pending,
            ).length;
            sharedStatus.textContent = pending
                ? "Kamēr pārbaudām kontaktu saglabāšanu, kontaktinformācijas labošana ir apturēta. Varat atkārtot nosūtīšanu vai turpināt bez atlikušajiem kontaktiem."
                : saved
                  ? "Saglabāto aktivitāšu skaits: " +
                    saved +
                    ". Turpmāki labojumi attiecas tikai uz vēl nesaglabātajām aktivitātēm."
                  : "";
            sharedStatus.hidden = !sharedStatus.textContent;
        }

        function validateSharedContacts() {
            if (!sharedContacts || sharedContacts.locked) return true;
            const errors = sharedContacts.validate();
            const nativeInvalid = [...fieldNodes.values()].find(
                ({ input }) => !input.disabled && !input.checkValidity(),
            );
            if (!errors.length && !nativeInvalid) return true;
            const messages = [];
            for (const error of errors) {
                const node = fieldNodes.get(error.fieldId);
                node?.input.setAttribute("aria-invalid", "true");
                const field = sharedContacts
                    .fields()
                    .find((item) => item.id === error.fieldId);
                messages.push(
                    error.code === "phone"
                        ? "Lūdzu, pārbaudiet tālruņa numuru. Piemērs: +371 20000000."
                        : error.code === "empty"
                          ? "Katrai norādītajai aktivitātei aizpildiet vismaz vienu kontaktinformācijas lauku vai noņemiet kontaktinformācijas atstāšanas atzīmi."
                          : "Pārbaudiet kontaktinformācijas lauku “" +
                            (field?.displayLabel || field?.label || "") +
                            "”.",
                );
            }
            if (nativeInvalid) {
                nativeInvalid.input.setAttribute("aria-invalid", "true");
                if (!messages.length)
                    messages.push("Pārbaudiet kontaktinformāciju.");
            }
            onErrors([...new Set(messages)]);
            onFocus();
            return false;
        }

        async function save() {
            if (!validateSharedContacts()) return { ok: false, errors: [] };
            const errors = [];
            // Snapshot every independent request before the first asynchronous operation.
            const requests = definitions
                .map((definition) => ({
                    key: definition.key,
                    definition,
                    payload: sharedContacts?.prepare(
                        definition.key,
                        definition,
                    ),
                }))
                .filter(({ payload }) => payload);
            onPrepared(requests.map(({ key }) => key));
            onChange();
            renderSharedContacts();
            for (const { definition, key, payload } of requests) {
                contactErrors.delete(key);
                let safelyRejected = false;
                try {
                    const send = session
                        ? session.request
                        : (...args) => fetch(...args);
                    const response = await send(definition.endpoint, {
                        method: "POST",
                        credentials: "same-origin",
                        headers: {
                            Accept: "application/json",
                            "Content-Type": "application/json",
                            "X-CSRF-TOKEN":
                                form.querySelector('[name="_token"]').value,
                        },
                        body: JSON.stringify(payload),
                    });
                    safelyRejected = [419, 429].includes(response.status);
                    if (response.status === 419)
                        throw new Error(
                            "Kontaktinformāciju neizdevās saglabāt. Mēģiniet vēlreiz.",
                        );
                    if (response.status === 429)
                        throw new Error(
                            "Pārāk daudz pieprasījumu. Nogaidiet minūti un mēģiniet vēlreiz.",
                        );
                    const result = await response.json();
                    safelyRejected =
                        response.status === 422 &&
                        !Object.hasOwn(result.errors || {}, "contact_id");
                    if (!response.ok || result.saved !== true) {
                        const messages = Object.values(result.errors || {})
                            .flat()
                            .filter((message) => typeof message === "string");
                        throw new Error(
                            messages.join(" ") ||
                                "Kontaktinformāciju neizdevās saglabāt. Mēģiniet vēlreiz.",
                        );
                    }
                    sharedContacts.settle(key, "saved");
                    onSettled(key, "saved");
                } catch (error) {
                    const unlocked =
                        safelyRejected &&
                        sharedContacts.settle(key, "rejected");
                    if (!unlocked) sharedContacts.settle(key, "ambiguous");
                    onSettled(key, unlocked ? "available" : "uncertain");
                    const message =
                        error instanceof TypeError ||
                        error instanceof SyntaxError
                            ? "Kontaktinformāciju neizdevās nosūtīt. Pārbaudiet savienojumu un mēģiniet vēlreiz."
                            : error.message;
                    contactErrors.set(key, message);
                    errors.push(message);
                }
                onChange();
                renderSharedContacts();
            }
            return { ok: errors.length === 0, errors: [...new Set(errors)] };
        }

        sharedConsent?.addEventListener("change", () => {
            if (isSending() || sharedContacts?.locked) {
                renderSharedContacts();
                return;
            }
            sharedContacts?.setGlobalConsent(sharedConsent.checked);
            contactErrors.clear();
            onChange();
            renderSharedContacts();
        });

        return Object.freeze({
            state: sharedContacts,
            render: renderSharedContacts,
            validate: validateSharedContacts,
            save,
            focus() {
                const target =
                    sharedFields?.querySelector(
                        '[aria-invalid="true"]:not(:disabled)',
                    ) ||
                    sharedFields?.querySelector(
                        "input:not(:disabled),select:not(:disabled)",
                    ) ||
                    sharedConsent;
                target?.focus();
                sharedPanel?.scrollIntoView({ block: "start" });
            },
        });
    }

    globalThis.MikausisContactCollector = Object.freeze({ create });
})();
