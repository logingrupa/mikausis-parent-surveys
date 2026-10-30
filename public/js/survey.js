(() => {
    "use strict";
    const form = document.querySelector("#survey-form");
    if (!form) {
        const completed = document.querySelector(
            "[data-completed-draft-scope]",
        );
        if (completed)
            globalThis.MikausisSurveyDraftState?.create({
                scope: completed.dataset.completedDraftScope,
                definition: completed.dataset.contactDefinition,
                questions: [],
            }).clear();
        return;
    }
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
    let restoringDraft = true;
    const session = globalThis.MikausisSurveySession?.forForm(form);
    const draft = globalThis.MikausisSurveyDraftState?.create({
        scope: form.dataset.draftScope,
        definition: form.dataset.contactDefinition,
        questions: questions.map((question) => ({
            key: question.dataset.question,
            type: question.dataset.type,
            options: [
                ...question.querySelectorAll(".answer-options input"),
            ].map((input) => ({ value: input.value })),
            allowOther: Boolean(question.querySelector("[data-other]")),
        })),
    });
    const restoredDraft = draft?.read();
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

    function saveDraft() {
        if (!draft || restoringDraft || answersSubmitted) return;
        const answers = Object.create(null);
        const otherAnswers = Object.create(null);
        for (const question of questions) {
            const key = question.dataset.question;
            if (question.dataset.type === "text") {
                answers[key] = question.querySelector("textarea").value;
            } else {
                const selected = values(question);
                answers[key] =
                    question.dataset.type === "multiple"
                        ? selected
                        : selected[0] || "";
            }
            const other = question.querySelector("[data-other] textarea");
            if (other) otherAnswers[key] = other.value;
        }
        draft.save({
            answers,
            otherAnswers,
            step: current,
            role:
                form.querySelector('input[name="role"]:checked')?.value || null,
            resumeToken: form.dataset.sessionResume || "",
        });
    }

    function restoreDraft() {
        if (!restoredDraft) return;
        for (const question of questions) {
            const key = question.dataset.question;
            const answer = restoredDraft.answers[key];
            if (question.dataset.type === "text") {
                question.querySelector("textarea").value =
                    typeof answer === "string" ? answer : "";
            } else {
                const selected = Array.isArray(answer) ? answer : [answer];
                question
                    .querySelectorAll(".answer-options input")
                    .forEach((input) => {
                        input.checked = selected.includes(input.value);
                    });
            }
            const other = question.querySelector("[data-other] textarea");
            if (other) other.value = restoredDraft.otherAnswers[key] || "";
        }
        form.querySelectorAll('input[name="role"]').forEach((input) => {
            input.checked =
                !input.disabled && input.value === restoredDraft.role;
        });
        if (restoredDraft.resumeToken)
            form.dataset.sessionResume = restoredDraft.resumeToken;
        if (anonymousControl) anonymousControl.checked = true;
        dirty = true;
    }

    function renewSession() {
        if (!session || sending || answersSubmitted) return;
        session
            .refresh()
            .then(() => {
                if (
                    !session.redirect ||
                    !restoredDraft ||
                    sending ||
                    answersSubmitted ||
                    sharedContacts?.globalConsent
                )
                    return;
                if (contactCache && !contactCache.entries().length)
                    contactCache.capture(
                        contactBlocks.map((block) => {
                            const question = block.closest("[data-question]");
                            return {
                                key: question.dataset.question,
                                values: values(question),
                                visible: !question.hidden,
                            };
                        }),
                    );
                answersSubmitted = true;
                draft?.clear();
                dirty = false;
                window.location.assign(session.redirect);
            })
            .catch(() => {});
    }

    function contactFields(block) {
        return [...block.querySelectorAll("[data-contact-field]")];
    }

    const contactDefinitions = contactBlocks.map((block) => {
        const question = block.closest("[data-question]");
        return {
            key: question.dataset.question,
            contactId: block.dataset.contactId,
            endpoint: block.dataset.endpoint,
            multiple: question.dataset.type === "multiple",
            title: question.dataset.title,
            prompt:
                block.dataset.contactPrompt ||
                block
                    .querySelector("[data-contact-consent]")
                    ?.closest("label")
                    ?.textContent.trim(),
            purpose:
                block.dataset.contactPurpose ||
                block.querySelector('[id$="-purpose"]')?.textContent.trim(),
            triggerValues: JSON.parse(block.dataset.triggers),
            fields: contactFields(block).map((field) => ({
                key: field.dataset.contactField,
                label: field.dataset.contactLabel,
                type:
                    field.dataset.contactType ||
                    (field.tagName === "SELECT" ? "role" : field.type),
                required: field.dataset.required === "true",
                maxLength: field.maxLength,
            })),
        };
    });
    const contactCache = globalThis.MikausisContactFollowupState?.create({
        scope: form.dataset.contactScope,
        definition: form.dataset.contactDefinition,
        definitions: contactDefinitions,
    });
    const collector = globalThis.MikausisContactCollector?.create({
        form,
        definitions: contactDefinitions,
        isSending: () => sending,
        onChange: synchronizeContactShadows,
        onErrors: (messages) => showErrors({ contacts: messages }),
        onFocus: focusSharedContacts,
        onPrepared: (keys) => contactCache?.mark(keys, "uncertain"),
        onSettled: (key, status) => {
            contactCache?.mark([key], status);
            if (status === "saved") {
                const block = contactBlocks.find(
                    (item) =>
                        item.closest("[data-question]").dataset.question ===
                        key,
                );
                if (block) block.dataset.saved = "true";
            }
        },
    });
    const sharedContacts = collector?.state;
    const renderSharedContacts = () => collector?.render();
    const validateSharedContacts = () => collector?.validate() !== false;
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

    function focusSharedContacts() {
        goTo(sectionCount, false);
        collector?.focus();
    }

    async function saveQuestionContacts() {
        const result = await collector?.save();
        if (!result?.ok) {
            const errors = result?.errors || [];
            if (!errors.length) return false;

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
        saveDraft();
        renewSession();
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
            saveDraft();
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
        if (question || event.target.name === "role") saveDraft();
    });
    form.addEventListener("input", (event) => {
        dirty = true;
        if (event.target.closest("[data-question]")) saveDraft();
    });
    form.querySelector("[data-skip-contacts]")?.addEventListener(
        "click",
        () => {
            if (answersSubmitted) dirty = false;
        },
    );
    window.addEventListener("beforeunload", (event) => {
        saveDraft();
        if (dirty && !sending) {
            event.preventDefault();
            event.returnValue = "";
        }
    });
    window.addEventListener("pagehide", saveDraft);
    window.addEventListener("pageshow", renewSession);
    document.addEventListener("visibilitychange", () => {
        if (document.visibilityState === "visible") renewSession();
    });
    if (session && typeof window.setInterval === "function")
        window.setInterval(
            () => {
                draft?.read();
                if (document.visibilityState === "visible") renewSession();
            },
            4 * 60 * 1000,
        );
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
        saveDraft();
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
            const send = session
                ? session.request
                : (...args) => fetch(...args);
            const response = await send(form.action, {
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
                    "Atbildes neizdevās iesniegt. Lūdzu, mēģiniet vēlreiz.",
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
            draft?.clear();
            contactCache?.capture(
                contactBlocks.map((block) => {
                    const question = block.closest("[data-question]");
                    return {
                        key: question.dataset.question,
                        values: values(question),
                        visible: !question.hidden,
                    };
                }),
            );
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
    restoreDraft();
    updateConditionalQuestions();
    updateRespondentDetails();
    goTo(restoredDraft?.step ?? -1, false);
    restoringDraft = false;
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
