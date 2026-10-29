(() => {
    "use strict";
    const form = document.querySelector("#survey-form");
    if (!form) return;
    const steps = [...form.querySelectorAll("[data-step]")];
    const questions = [...form.querySelectorAll("[data-question]")];
    const sectionCount = Number(form.dataset.sectionCount);
    const errorBox = document.querySelector("#form-errors");
    const submitButton = document.querySelector("#submit-button");
    const actions = form.querySelector(".step-actions");
    const anonymousControl = form.querySelector("#respondent-anonymous");
    const respondentDetails = form.querySelector("[data-respondent-details]");
    const identityInputs = [
        ...form.querySelectorAll("[data-respondent-input]"),
    ];
    const roleLabels = {
        mother: "Mamma",
        father: "Tētis",
        guardian: "Cits aizbildnis",
    };
    const visited = new Set();
    let current = -1;
    let dirty = false;
    let sending = false;
    let answersSubmitted = false;
    let thanksRedirect = null;
    const contactBlocks = [...form.querySelectorAll("[data-question-contact]")];
    const values = (question) =>
        [...question.querySelectorAll(".answer-options input:checked")].map(
            (input) => input.value,
        );
    const hasValue = (question) =>
        question.dataset.type === "text"
            ? Boolean(question.querySelector("textarea").value.trim())
            : values(question).length > 0;
    const visible = (question) => !question.hidden;

    function contactFields(block) {
        return [...block.querySelectorAll("[data-contact-field]")];
    }

    const sharedPanel = form.querySelector("[data-shared-contacts]");
    const sharedConsent = sharedPanel?.querySelector(
        "[data-shared-contact-consent]",
    );
    const sharedPrompt = sharedPanel?.querySelector("#shared-contact-prompt");
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
            ? globalThis.MikausisSharedContacts.create(
                  contactBlocks.map((block) => {
                      const question = block.closest("[data-question]");
                      return {
                          key: question.dataset.question,
                          title: question.dataset.title,
                          prompt:
                              block.dataset.contactPrompt ||
                              block
                                  .querySelector("[data-contact-consent]")
                                  ?.closest("label")
                                  ?.textContent.trim(),
                          purpose:
                              block.dataset.contactPurpose ||
                              block
                                  .querySelector('[id$="-purpose"]')
                                  ?.textContent.trim(),
                          triggerValues: JSON.parse(block.dataset.triggers),
                          fields: contactFields(block).map((field) => ({
                              key: field.dataset.contactField,
                              label: field.dataset.contactLabel,
                              type:
                                  field.dataset.contactType ||
                                  (field.tagName === "SELECT"
                                      ? "role"
                                      : field.type),
                              required: field.dataset.required === "true",
                              maxLength: field.maxLength,
                          })),
                      };
                  }),
              )
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
        const busy = sending || sharedContacts.locked;
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
            !activities.some((activity) => activity.saved || activity.pending);

        const activeKeys = new Set(activities.map((activity) => activity.key));
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
                    (activity.pending ? "Saglabājam kontaktinformāciju…" : "");
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
                input.setAttribute("aria-describedby", "shared-contacts-help");
                const read = () => {
                    if (sending || sharedContacts.locked) {
                        renderSharedContacts();
                        return;
                    }
                    sharedContacts.setValue(field.id, input.value);
                    input.removeAttribute("aria-invalid");
                    synchronizeContactShadows();
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
        const saved = activities.filter((activity) => activity.saved).length;
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

    function synchronizeContactShadows() {
        const activities = new Map(
            (sharedContacts?.activities() || []).map((item) => [
                item.key,
                item,
            ]),
        );
        for (const block of contactBlocks) {
            const question = block.closest("[data-question]");
            const activity = activities.get(question.dataset.question);
            const consent = block.querySelector("[data-contact-consent]");
            // Shadows never participate in native validation or the main FormData.
            consent.checked = Boolean(activity?.included || activity?.saved);
            consent.disabled = true;
            consent.removeAttribute("name");
            consent.closest("label").hidden = true;
            block.querySelector("[data-contact-details]").hidden = true;
            const purpose = block.querySelector('[id$="-purpose"]');
            if (purpose) purpose.hidden = true;
            block.hidden = true;
            const scattered = activity?.saved
                ? {}
                : sharedContacts?.pending(question.dataset.question)?.fields ||
                  sharedContacts?.scatter(question.dataset.question) ||
                  {};
            for (const field of contactFields(block)) {
                field.disabled = true;
                field.required = false;
                field.removeAttribute("name");
                field.value = Object.hasOwn(
                    scattered,
                    field.dataset.contactField,
                )
                    ? scattered[field.dataset.contactField]
                    : "";
            }
        }
    }

    function updateQuestionContacts() {
        if (!sending)
            sharedContacts?.syncSelections(
                contactBlocks.map((block) => {
                    const question = block.closest("[data-question]");
                    return {
                        key: question.dataset.question,
                        visible: !question.hidden,
                        values: values(question),
                    };
                }),
            );
        synchronizeContactShadows();
        renderSharedContacts();
    }

    sharedConsent?.addEventListener("change", () => {
        if (sending || sharedContacts?.locked) {
            renderSharedContacts();
            return;
        }
        sharedContacts?.setGlobalConsent(sharedConsent.checked);
        contactErrors.clear();
        updateQuestionContacts();
    });

    function focusSharedContacts() {
        goTo(sectionCount, false);
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
                error.code === "empty"
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
        showErrors({ contacts: [...new Set(messages)] });
        focusSharedContacts();
        return false;
    }

    async function saveQuestionContacts() {
        if (!validateSharedContacts()) return false;
        const errors = [];
        // Snapshot every independent request before the first asynchronous operation.
        const requests = contactBlocks
            .map((block) => {
                const question = block.closest("[data-question]");
                const key = question.dataset.question;
                const payload = sharedContacts?.prepare(key, {
                    contactId: block.dataset.contactId,
                    multiple: question.dataset.type === "multiple",
                });
                return { block, key, payload };
            })
            .filter(({ payload }) => payload);
        updateQuestionContacts();
        for (const { block, key, payload } of requests) {
            contactErrors.delete(key);
            let safelyRejected = false;
            try {
                const response = await fetch(block.dataset.endpoint, {
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
                        "Sesija ir beigusies. Kontaktus varēsiet atstāt atsevišķajā aktivitātes lapā.",
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
                block.dataset.saved = "true";
            } catch (error) {
                const unlocked =
                    safelyRejected && sharedContacts.settle(key, "rejected");
                if (!unlocked) sharedContacts.settle(key, "ambiguous");
                const message =
                    error instanceof TypeError || error instanceof SyntaxError
                        ? "Kontaktinformāciju neizdevās nosūtīt. Pārbaudiet savienojumu un mēģiniet vēlreiz."
                        : error.message;
                contactErrors.set(key, message);
                errors.push(message);
            }
            updateQuestionContacts();
        }
        if (errors.length) {
            showErrors({ contacts: [...new Set(errors)] });
            goTo(sectionCount, false);
            errorBox.querySelector("strong").textContent =
                "Aptaujas atbildes ir saglabātas. Kontaktinformācijas saglabāšana vēl jāpārbauda.";
            const skip = form.querySelector("[data-skip-contacts]");
            skip.href = thanksRedirect;
            skip.hidden = false;
            return false;
        }
        dirty = false;
        window.location.assign(thanksRedirect);
        return true;
    }

    function updateRespondentDetails() {
        if (!anonymousControl) return;
        const anonymous = anonymousControl.checked;
        respondentDetails.hidden = anonymous;
        for (const input of identityInputs) {
            input.disabled = anonymous;
            input.required = !anonymous && input.dataset.required === "true";
            if (anonymous) {
                input.value = "";
                input.removeAttribute("aria-invalid");
            }
        }
        const familyRole = form.querySelector(
            'input[name="role"]:checked',
        )?.value;
        form.querySelectorAll("[data-respondent-family-role]").forEach(
            (item) => {
                item.textContent =
                    roleLabels[familyRole] ||
                    "Izmantojam iepriekš izvēlēto ģimenes lomu.";
            },
        );
        const note = form.querySelector("[data-submission-note]");
        note.textContent = anonymous
            ? "Atbilžu saturam nepievienojam jūsu identitāti. Pēc iesniegšanas atbildes vairs nevarēsiet labot."
            : "Atbildes un jūsu norādītie dati būs redzami administrācijai. Pēc iesniegšanas atbildes vairs nevarēsiet labot.";
        if (anonymous) {
            respondentDetails
                .querySelectorAll("[data-field-error]")
                .forEach((error) => {
                    error.hidden = true;
                });
            const error = form.querySelector("#error-respondent");
            if (error) error.hidden = true;
        }
    }

    function identityTarget(key) {
        if (key === "anonymous") return anonymousControl;
        if (key === "respondent")
            return form.querySelector("#respondent-identity");
        if (!key.startsWith("respondent.")) return null;
        return document.getElementById(
            "respondent-" + key.slice("respondent.".length),
        );
    }

    function updateConditionalQuestions() {
        if (answersSubmitted) {
            updateQuestionContacts();
            return;
        }
        for (const question of questions) {
            const condition = JSON.parse(question.dataset.condition || "null");
            const source = condition
                ? questions.find(
                      (item) => item.dataset.question === condition.question,
                  )
                : null;
            const show =
                !condition ||
                Boolean(
                    source &&
                        !source.hidden &&
                        values(source).some((value) =>
                            condition.values.includes(value),
                        ),
                );
            question.hidden = !show;
            question.querySelectorAll("input, textarea").forEach((input) => {
                input.disabled = !show;
            });
            const other = question.querySelector("[data-other]");
            if (other) {
                const showOther = show && values(question).includes("other");
                other.hidden = !showOther;
                other.querySelectorAll("textarea").forEach((input) => {
                    input.disabled = !showOther;
                    input.required = showOther;
                });
            }
            const clearButton = question.querySelector("[data-clear]");
            if (clearButton) clearButton.hidden = !hasValue(question);
        }
        updateQuestionContacts();
    }

    function checkStep(index) {
        const section = steps.find(
            (step) => Number(step.dataset.step) === index,
        );
        if (!section) return true;
        for (const input of section.querySelectorAll(
            "input, textarea, select",
        )) {
            if (
                !input.disabled &&
                !input.hasAttribute("data-shared-contact-input") &&
                !input.checkValidity()
            ) {
                input.reportValidity();
                return false;
            }
        }
        return true;
    }

    function buildReview() {
        updateQuestionContacts();
        const container = document.querySelector("#review-answers");
        container.replaceChildren();
        if (anonymousControl) {
            const item = document.createElement("div");
            item.className = "review-item review-identity";
            const title = document.createElement("h3");
            title.textContent = "Atbildētāja informācija";
            const answer = document.createElement("p");
            if (anonymousControl.checked) answer.textContent = "Anonīmi";
            else {
                const details = identityInputs
                    .filter((input) => input.value.trim())
                    .map((input) => {
                        const value =
                            input.tagName === "SELECT"
                                ? input.selectedOptions[0].textContent
                                : input.value.trim();
                        return input.dataset.respondentLabel + ": " + value;
                    });
                form.querySelectorAll("[data-respondent-family-role]").forEach(
                    (field) => {
                        details.push(
                            field.dataset.respondentLabel +
                                ": " +
                                field.textContent,
                        );
                    },
                );
                answer.textContent = details.join("; ") || "Nav norādīti dati";
            }
            const edit = document.createElement("button");
            edit.type = "button";
            edit.textContent = "Labot";
            edit.disabled = answersSubmitted;
            edit.addEventListener("click", () => {
                goTo(-1);
                anonymousControl.focus();
            });
            item.append(title, answer, edit);
            container.append(item);
        }
        for (const question of questions.filter(visible)) {
            const item = document.createElement("div");
            item.className = "review-item";
            const title = document.createElement("h3");
            title.textContent = question.dataset.title;
            const answer = document.createElement("p");
            if (question.dataset.type === "text")
                answer.textContent =
                    question.querySelector("textarea").value.trim() ||
                    "Nav atbildēts";
            else {
                const selected = [
                    ...question.querySelectorAll(
                        ".answer-options input:checked",
                    ),
                ].map((input) =>
                    input
                        .closest("label")
                        .querySelector("span")
                        .textContent.trim(),
                );
                const otherText = question.querySelector(
                    "[data-other] textarea",
                );
                answer.textContent = selected.join("; ") || "Nav atbildēts";
                if (otherText && !otherText.disabled && otherText.value.trim())
                    answer.textContent += ": " + otherText.value.trim();
            }
            if (!hasValue(question)) answer.className = "muted";
            const edit = document.createElement("button");
            edit.type = "button";
            edit.textContent = "Labot";
            edit.disabled = answersSubmitted;
            edit.addEventListener("click", () => {
                goTo(Number(question.closest("[data-step]").dataset.step));
                question.scrollIntoView({ block: "start" });
            });
            item.append(title, answer, edit);
            const contact = question.querySelector("[data-question-contact]");
            if (
                contact &&
                !contact.hidden &&
                contact.querySelector("[data-contact-consent]").checked
            ) {
                const note = document.createElement("div");
                note.className = "review-contact";
                note.textContent =
                    contact.dataset.saved === "true"
                        ? "Kontaktinformācija šai aktivitātei ir saglabāta atsevišķi."
                        : "Šī aktivitāte ir iekļauta kopīgajā kontaktinformācijas izvēlē aptaujas beigās.";
                item.append(note);
            }
            container.append(item);
        }
    }

    function goTo(index, focus = true) {
        if (index > -1 && !checkStep(-1)) {
            index = -1;
        }
        current = Math.max(-1, Math.min(sectionCount, index));
        form.querySelector(".mobile-progress").hidden = current < 0;
        if (current >= 0) visited.add(current);
        steps.forEach((step) => {
            step.hidden = Number(step.dataset.step) !== current;
        });
        form.querySelectorAll("[data-go-step]").forEach((button) => {
            const target = Number(button.dataset.goStep);
            if (target === current) button.setAttribute("aria-current", "step");
            else button.removeAttribute("aria-current");
            button.classList.toggle("is-visited", visited.has(target));
        });
        actions.hidden = current < 0;
        actions.querySelector("[data-next]").hidden = current === sectionCount;
        if (current === sectionCount) {
            document.querySelector("#review-title").textContent =
                "Vēlreiz pārskatām?";
            buildReview();
        }
        const heading = steps
            .find((step) => !step.hidden)
            ?.querySelector("h1,h2");
        document.querySelector("#mobile-step-label").textContent =
            current < 0
                ? "Pirms sākam"
                : current === sectionCount
                  ? "Pārskatīt un iesniegt"
                  : heading.textContent;
        document.querySelector("#step-fraction").textContent =
            current < 0
                ? ""
                : `${Math.min(current + 1, sectionCount)} / ${sectionCount}`;
        document.querySelector("#progress-fill").style.transform =
            `scaleX(${current < 0 ? 0 : Math.min(current + 1, sectionCount) / sectionCount})`;
        if (focus && heading) {
            heading.focus({ preventScroll: true });
            form.scrollIntoView({
                block: "start",
                behavior: matchMedia("(prefers-reduced-motion: reduce)").matches
                    ? "instant"
                    : "smooth",
            });
        }
    }

    function showErrors(errors) {
        errorBox.replaceChildren();
        const heading = document.createElement("strong");
        heading.textContent = "Lūdzu, pārbaudiet atbildes.";
        const list = document.createElement("ul");
        for (const [key, messages] of Object.entries(errors)) {
            const respondentInput = identityTarget(key);
            if (respondentInput) {
                const error =
                    key === "anonymous"
                        ? form.querySelector("#error-anonymous")
                        : key === "respondent"
                          ? form.querySelector("#error-respondent")
                          : document.getElementById(
                                "error-respondent-" +
                                    key.slice("respondent.".length),
                            );
                if (error) {
                    error.textContent = messages.join(" ");
                    error.hidden = false;
                }
                respondentInput.setAttribute("aria-invalid", "true");
            }
            if (key === "role") {
                const roleError = document.querySelector("#error-role");
                if (roleError) {
                    roleError.textContent = messages.join(" ");
                    roleError.hidden = false;
                    form.querySelectorAll('input[name="role"]').forEach(
                        (input) => input.setAttribute("aria-invalid", "true"),
                    );
                }
            }
            const questionKey = key.split(".")[1];
            const question = questions.find(
                (item) => item.dataset.question === questionKey,
            );
            if (question && !question.hidden) {
                const error = question.querySelector("[data-field-error]");
                error.textContent = messages.join(" ");
                error.hidden = false;
                question.querySelectorAll("input,textarea").forEach((input) => {
                    input.setAttribute("aria-invalid", "true");
                });
            }
            for (const message of messages) {
                const li = document.createElement("li");
                if (
                    question ||
                    key === "role" ||
                    respondentInput ||
                    key === "contacts"
                ) {
                    const link = document.createElement("a");
                    link.textContent = message;
                    if (key === "contacts") {
                        link.href = "#shared-contacts-title";
                        link.dataset.errorContacts = "";
                    } else if (question) {
                        link.href = `#question-${questionKey}`;
                        link.dataset.errorQuestion = questionKey;
                    } else if (respondentInput) {
                        link.href = "#" + respondentInput.id;
                        link.dataset.errorIdentity = respondentInput.id;
                    } else {
                        link.href = "#role-picker";
                        link.dataset.errorRole = "";
                    }
                    li.append(link);
                } else li.textContent = message;
                list.append(li);
            }
        }
        const firstKey = Object.keys(errors)
            .find(
                (key) =>
                    key.startsWith("answers.") ||
                    key.startsWith("other_answers."),
            )
            ?.split(".")[1];
        const firstQuestion = questions.find(
            (question) =>
                question.dataset.question === firstKey && !question.hidden,
        );
        if (firstQuestion)
            goTo(
                Number(firstQuestion.closest("[data-step]").dataset.step),
                false,
            );
        if (
            errors.role ||
            Object.keys(errors).some((key) => identityTarget(key))
        )
            goTo(-1, false);
        if (errors.contacts) goTo(sectionCount, false);
        errorBox.append(heading, list);
        errorBox.hidden = false;
        errorBox.focus();
    }

    errorBox.addEventListener("click", (event) => {
        const link = event.target.closest(
            "[data-error-question], [data-error-role], [data-error-identity], [data-error-contacts]",
        );
        if (!link) return;
        event.preventDefault();
        if (link.hasAttribute("data-error-contacts")) {
            focusSharedContacts();
            return;
        }
        if (link.hasAttribute("data-error-identity")) {
            goTo(-1, false);
            document.getElementById(link.dataset.errorIdentity)?.focus();
            return;
        }
        if (link.hasAttribute("data-error-role")) {
            goTo(-1, false);
            form.querySelector('input[name="role"]:not(:disabled)')?.focus();
            return;
        }
        const question = questions.find(
            (item) => item.dataset.question === link.dataset.errorQuestion,
        );
        if (question && !question.hidden) {
            goTo(Number(question.closest("[data-step]").dataset.step), false);
            question.scrollIntoView({ block: "start" });
            question
                .querySelector("input:not(:disabled), textarea:not(:disabled)")
                ?.focus({ preventScroll: true });
        }
    });

    form.querySelectorAll("[data-next]").forEach((button) => {
        button.hidden = false;
        button.addEventListener("click", () => {
            if (checkStep(current)) goTo(current + 1);
        });
    });
    form.querySelector("[data-previous]").addEventListener("click", () =>
        goTo(current - 1),
    );
    form.querySelectorAll("[data-go-step]").forEach((button) =>
        button.addEventListener("click", () => {
            if (checkStep(current)) goTo(Number(button.dataset.goStep));
        }),
    );
    form.querySelectorAll("[data-clear]").forEach((button) =>
        button.addEventListener("click", () => {
            if (answersSubmitted || sending) return;
            const question = button.closest("[data-question]");
            question.querySelectorAll("input").forEach((input) => {
                input.checked = false;
            });
            question.querySelectorAll("textarea").forEach((input) => {
                input.value = "";
            });
            dirty = true;
            updateConditionalQuestions();
        }),
    );
    form.addEventListener("change", (event) => {
        if (sending || sharedContacts?.locked) return;
        dirty = true;
        if (event.target === anonymousControl || event.target.name === "role")
            updateRespondentDetails();
        const question = event.target.closest("[data-question]");
        if (
            question &&
            event.target.name.startsWith("answers[") &&
            event.target.type === "checkbox" &&
            event.target.checked
        ) {
            const exclusive = JSON.parse(question.dataset.exclusive || "[]");
            question
                .querySelectorAll(".answer-options input:checked")
                .forEach((input) => {
                    if (
                        input !== event.target &&
                        (exclusive.includes(event.target.value) ||
                            exclusive.includes(input.value))
                    )
                        input.checked = false;
                });
        }
        updateConditionalQuestions();
    });
    form.addEventListener("input", () => {
        dirty = true;
    });
    form.querySelector("[data-skip-contacts]")?.addEventListener(
        "click",
        () => {
            if (answersSubmitted) dirty = false;
        },
    );
    window.addEventListener("beforeunload", (event) => {
        if (dirty && !sending) {
            event.preventDefault();
            event.returnValue = "";
        }
    });
    form.addEventListener("submit", async (event) => {
        event.preventDefault();
        if (sending) return;
        if (answersSubmitted) {
            sending = true;
            submitButton.disabled = true;
            renderSharedContacts();
            form.setAttribute("aria-busy", "true");
            try {
                await saveQuestionContacts();
            } finally {
                sending = false;
                submitButton.disabled = false;
                renderSharedContacts();
                form.removeAttribute("aria-busy");
            }
            return;
        }
        updateConditionalQuestions();
        updateRespondentDetails();
        for (let index = -1; index < sectionCount; index++) {
            const section = steps.find(
                (step) => Number(step.dataset.step) === index,
            );
            const invalid = [
                ...section.querySelectorAll("input,textarea,select"),
            ].find((input) => !input.disabled && !input.checkValidity());
            if (invalid) {
                goTo(index);
                invalid.reportValidity();
                return;
            }
        }
        if (!questions.filter(visible).some(hasValue)) {
            showErrors({
                answers: [
                    "Atbildiet vismaz uz vienu jautājumu, lai iesniegtu aptauju.",
                ],
            });
            return;
        }
        if (!validateSharedContacts()) return;
        errorBox.hidden = true;
        form.querySelectorAll("[aria-invalid]").forEach((input) =>
            input.removeAttribute("aria-invalid"),
        );
        form.querySelectorAll("[data-field-error]").forEach((item) => {
            item.hidden = true;
        });
        sending = true;
        submitButton.disabled = true;
        renderSharedContacts();
        submitButton.textContent = "Iesniedzam atbildes…";
        form.setAttribute("aria-busy", "true");
        const answerPayload = new FormData(form);
        const enabledAnswerControls = [
            ...form.querySelectorAll(
                "input[name]:not(:disabled),textarea[name]:not(:disabled),select[name]:not(:disabled),[data-clear]:not(:disabled)",
            ),
        ];
        enabledAnswerControls.forEach((input) => {
            input.disabled = true;
        });
        try {
            const response = await fetch(form.action, {
                method: "POST",
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: answerPayload,
                credentials: "same-origin",
            });
            if (response.status === 419)
                throw new Error(
                    "Sesija ir beigusies. Atveriet aptauju no jauna un mēģiniet vēlreiz.",
                );
            if (response.status === 429)
                throw new Error(
                    "Saņemts pārāk daudz pieprasījumu. Nogaidiet minūti un mēģiniet vēlreiz.",
                );
            const result = await response.json();
            if (response.status === 422) {
                showErrors(result.errors || { form: [result.message] });
                return;
            }
            if (!response.ok || !result.redirect)
                throw new Error(
                    "Atbildes neizdevās iesniegt. Lūdzu, mēģiniet vēlreiz.",
                );
            thanksRedirect = result.redirect;
            answersSubmitted = true;
            // Contact inputs deliberately have no name: they never enter FormData above.
            form.querySelectorAll(
                "input[name],textarea[name],select[name],[data-clear]",
            ).forEach((input) => {
                input.disabled = true;
            });
            await saveQuestionContacts();
        } catch (error) {
            showErrors({
                form: [
                    error instanceof TypeError
                        ? "Neizdevās savienoties ar serveri. Pārbaudiet interneta savienojumu un mēģiniet vēlreiz."
                        : error.message,
                ],
            });
        } finally {
            if (!answersSubmitted)
                enabledAnswerControls.forEach((input) => {
                    input.disabled = false;
                });
            sending = false;
            submitButton.disabled = false;
            renderSharedContacts();
            submitButton.innerHTML = answersSubmitted
                ? "Pārbaudīt / atkārtot kontaktu nosūtīšanu"
                : 'Iesniegt atbildes <span aria-hidden="true">→</span>';
            form.removeAttribute("aria-busy");
        }
    });
    form.classList.add("js-enhanced");
    form.noValidate = true;
    form.querySelector(".mobile-progress").hidden = false;
    updateConditionalQuestions();
    updateRespondentDetails();
    goTo(-1, false);
    if (!errorBox.hidden) {
        const invalidQuestion = form
            .querySelector('[aria-invalid="true"]')
            ?.closest("[data-question]");
        if (invalidQuestion)
            goTo(
                Number(invalidQuestion.closest("[data-step]").dataset.step),
                false,
            );
        errorBox.focus();
    }
})();
