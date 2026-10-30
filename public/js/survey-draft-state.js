(() => {
    "use strict";

    const ttl = 60 * 60 * 1000;
    const maxBytes = 256 * 1024;
    const object = (value) =>
        value !== null && typeof value === "object" && !Array.isArray(value);
    const own = (value, key) => object(value) && Object.hasOwn(value, key);
    const exactKeys = (value, keys) =>
        object(value) &&
        Object.keys(value).length === keys.length &&
        keys.every((key) => Object.hasOwn(value, key));
    const textWithin = (value, limit) =>
        typeof value === "string" &&
        value.length <= limit * 2 &&
        Array.from(value).length <= limit;
    const validStep = (value) =>
        Number.isInteger(value) && value >= -1 && value <= 100;
    const validRole = (value) =>
        ["mother", "father", "guardian"].includes(value);
    const validToken = (value) =>
        typeof value === "string" &&
        /^[\x21-\x7e]{1,4096}$/u.test(value) &&
        !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iu.test(
            value,
        );

    function questionMap(questions) {
        if (!Array.isArray(questions) || questions.length > 100) return null;
        const result = new Map();
        for (const question of questions) {
            if (
                !object(question) ||
                typeof question.key !== "string" ||
                !/^[A-Za-z][A-Za-z0-9_-]{0,79}$/u.test(question.key) ||
                result.has(question.key) ||
                !["text", "single", "multiple"].includes(question.type) ||
                (question.allowOther !== undefined &&
                    typeof question.allowOther !== "boolean")
            )
                return null;
            const options = question.options ?? [];
            if (!Array.isArray(options) || options.length > 100) return null;
            const values = new Set();
            for (const option of options) {
                if (
                    !object(option) ||
                    !textWithin(option.value, 100) ||
                    option.value === "" ||
                    values.has(option.value)
                )
                    return null;
                values.add(option.value);
            }
            if (question.allowOther === true) values.add("other");
            result.set(question.key, { type: question.type, values });
        }
        return result;
    }

    function normalize(questions, rawAnswers, rawOther) {
        const answers = Object.create(null);
        const otherAnswers = Object.create(null);
        for (const [key, question] of questions) {
            if (!own(rawAnswers, key)) continue;
            const value = rawAnswers[key];
            if (question.type === "text") {
                if (textWithin(value, 2000)) answers[key] = value;
                continue;
            }
            if (question.type === "single") {
                if (typeof value !== "string" || !question.values.has(value))
                    continue;
                answers[key] = value;
            } else {
                if (
                    !Array.isArray(value) ||
                    value.length > question.values.size ||
                    new Set(value).size !== value.length ||
                    !Array.from(value).every(
                        (option) =>
                            typeof option === "string" &&
                            question.values.has(option),
                    )
                )
                    continue;
                answers[key] = [...value];
            }
            const selected = Array.isArray(value) ? value : [value];
            if (
                selected.includes("other") &&
                own(rawOther, key) &&
                textWithin(rawOther[key], 2000)
            )
                otherAnswers[key] = rawOther[key];
        }
        return { answers, otherAnswers };
    }

    function sameMap(raw, normalized) {
        return (
            exactKeys(raw, Object.keys(normalized)) &&
            Object.keys(normalized).every(
                (key) =>
                    JSON.stringify(raw[key]) ===
                    JSON.stringify(normalized[key]),
            )
        );
    }

    function boundedJson(raw) {
        return (
            typeof raw === "string" &&
            raw.length <= maxBytes &&
            new TextEncoder().encode(raw).byteLength <= maxBytes
        );
    }

    function create({
        scope,
        definition,
        questions,
        storage,
        now = Date.now,
    } = {}) {
        const current = questionMap(questions);
        const validScope =
            typeof scope === "string" && /^[A-Za-z0-9_-]{1,160}$/u.test(scope);
        const storageKey = validScope ? "mikausis.survey-draft:" + scope : null;
        const enabled =
            current !== null &&
            validScope &&
            typeof definition === "string" &&
            /^[0-9a-f]{64}$/iu.test(definition);
        if (storage === undefined) {
            try {
                storage = globalThis.sessionStorage;
            } catch {
                storage = null;
            }
        }

        function clear() {
            try {
                if (storageKey) storage?.removeItem(storageKey);
            } catch {}
        }

        function validTime(time) {
            return (
                Number.isSafeInteger(time) &&
                time >= 0 &&
                Number.isSafeInteger(time + ttl)
            );
        }

        function load(time) {
            if (!enabled || !validTime(time)) {
                clear();
                return null;
            }
            let record;
            try {
                const raw = storage?.getItem(storageKey);
                if (raw === null || raw === undefined) return null;
                record = boundedJson(raw) ? JSON.parse(raw) : null;
            } catch {
                clear();
                return null;
            }
            const keys = [
                "version",
                "scope",
                "definition",
                "createdAt",
                "expiresAt",
                "answers",
                "otherAnswers",
                "step",
                "resumeToken",
            ];
            if (own(record, "role")) keys.push("role");
            if (
                !exactKeys(record, keys) ||
                record.version !== 1 ||
                record.scope !== scope ||
                record.definition !== definition ||
                !Number.isSafeInteger(record.createdAt) ||
                record.createdAt < 0 ||
                record.createdAt > time ||
                !Number.isSafeInteger(record.expiresAt) ||
                record.expiresAt !== record.createdAt + ttl ||
                record.expiresAt <= time ||
                !validStep(record.step) ||
                !validToken(record.resumeToken) ||
                (own(record, "role") && !validRole(record.role))
            ) {
                clear();
                return null;
            }
            const normalized = normalize(
                current,
                record.answers,
                record.otherAnswers,
            );
            if (
                !sameMap(record.answers, normalized.answers) ||
                !sameMap(record.otherAnswers, normalized.otherAnswers)
            ) {
                clear();
                return null;
            }
            return record;
        }

        function read() {
            try {
                const record = load(now());
                if (!record) return null;
                const result = {
                    answers: record.answers,
                    otherAnswers: record.otherAnswers,
                    step: record.step,
                    resumeToken: record.resumeToken,
                    expiresAt: record.expiresAt,
                };
                if (own(record, "role")) result.role = record.role;
                return result;
            } catch {
                clear();
                return null;
            }
        }

        function save(input) {
            try {
                const time = now();
                if (
                    !enabled ||
                    !validTime(time) ||
                    !own(input, "step") ||
                    !validStep(input.step)
                ) {
                    clear();
                    return false;
                }
                const previous = load(time);
                const resumeToken =
                    previous?.resumeToken ??
                    (own(input, "resumeToken") ? input.resumeToken : null);
                if (!validToken(resumeToken)) {
                    clear();
                    return false;
                }
                const record = {
                    version: 1,
                    scope,
                    definition,
                    createdAt: previous?.createdAt ?? time,
                    expiresAt: previous?.expiresAt ?? time + ttl,
                    ...normalize(
                        current,
                        own(input, "answers") ? input.answers : null,
                        own(input, "otherAnswers") ? input.otherAnswers : null,
                    ),
                    step: input.step,
                    resumeToken,
                };
                if (own(input, "role") && validRole(input.role))
                    record.role = input.role;
                const raw = JSON.stringify(record);
                if (!boundedJson(raw)) {
                    clear();
                    return false;
                }
                storage.setItem(storageKey, raw);
                return true;
            } catch {
                clear();
                return false;
            }
        }

        return Object.freeze({ read, save, clear });
    }

    globalThis.MikausisSurveyDraftState = Object.freeze({ create });
})();
