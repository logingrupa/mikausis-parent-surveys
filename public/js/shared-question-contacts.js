(() => {
    "use strict";

    // Pure, in-memory state shared by the public survey and the editor preview.
    // Definitions, selections and returned snapshots are copied; no DOM or storage.
    const limits = { text: 160, email: 254, tel: 40, role: null };
    const roles = ["mother", "father", "guardian"];
    const builtInTypes = {
        name: "text",
        email: "email",
        phone: "tel",
        role: "role",
    };
    const validPhone = (value) => {
        if (!/^\+?[0-9 ()-]+$/u.test(value)) return false;
        let number = value.replace(/[ ()-]/gu, "");
        if (number.startsWith("00")) number = "+" + number.slice(2);
        if (number.startsWith("+371"))
            return /^\+371[26][0-9]{7}$/u.test(number);
        return number.startsWith("+")
            ? /^\+[1-9][0-9]{6,14}$/u.test(number)
            : /^[26][0-9]{7}$/u.test(number);
    };
    const typeLabels = {
        text: "īss teksts",
        email: "e-pasts",
        tel: "tālrunis",
        role: "loma",
    };
    const normalizeLabel = (label) =>
        label.trim().replace(/\s+/gu, " ").toLowerCase();
    const fieldId = (field) =>
        JSON.stringify([normalizeLabel(field.label), field.type]);
    const freezePayload = (payload) => {
        Object.freeze(payload.fields);
        if (Array.isArray(payload.answer)) Object.freeze(payload.answer);
        return Object.freeze(payload);
    };

    function normalizeDefinitions(definitions) {
        const keys = new Set();
        return definitions.map((definition) => {
            const key = String(definition.key);
            if (keys.has(key))
                throw new Error("Duplicate contact question key.");
            keys.add(key);
            const fields = new Map();
            for (const field of definition.fields || []) {
                const type = Object.hasOwn(builtInTypes, field.key)
                    ? builtInTypes[field.key]
                    : field.type;
                if (
                    typeof field.key !== "string" ||
                    typeof field.label !== "string" ||
                    !field.label.trim() ||
                    !Object.hasOwn(limits, type)
                )
                    continue;
                const cap = limits[type];
                fields.set(field.key, {
                    key: field.key,
                    label: field.label.trim(),
                    type,
                    required: field.required === true,
                    maxLength:
                        cap === null
                            ? null
                            : Math.min(
                                  cap,
                                  Number.isInteger(field.maxLength) &&
                                      field.maxLength > 0
                                      ? field.maxLength
                                      : cap,
                              ),
                });
            }
            return {
                key,
                title: String(definition.title || ""),
                prompt: String(definition.prompt || ""),
                purpose: String(definition.purpose || ""),
                triggerValues: [
                    ...new Set(
                        (definition.triggerValues || []).filter(
                            (value) => typeof value === "string",
                        ),
                    ),
                ],
                fields: [...fields.values()],
            };
        });
    }

    const definitionSignature = (definition) =>
        JSON.stringify({
            ...definition,
            triggerValues: [...definition.triggerValues].sort(),
        });

    function create(definitions = []) {
        let rows = new Map();
        let globalConsent = false;
        const entered = new Map();
        const locked = () =>
            [...rows.values()].some((row) => row.request !== null);
        const included = (row) =>
            globalConsent && row.active && row.included && !row.saved;
        const consentScope = () =>
            JSON.stringify(
                [...rows.values()]
                    .filter((row) => row.active && !row.saved)
                    .map((row) => [
                        row.definition.key,
                        definitionSignature(row.definition),
                        [...row.selected].sort(),
                    ])
                    .sort(([a], [b]) => a.localeCompare(b)),
            );

        function fields() {
            const merged = new Map();
            for (const row of rows.values()) {
                if (!included(row)) continue;
                for (const field of row.definition.fields) {
                    const id = fieldId(field);
                    if (!merged.has(id))
                        merged.set(id, {
                            id,
                            label: field.label,
                            type: field.type,
                            required: false,
                            maxLength: field.maxLength,
                            value: entered.get(id) || "",
                            sources: [],
                        });
                    const target = merged.get(id);
                    target.required ||= field.required;
                    if (field.maxLength !== null)
                        target.maxLength = Math.min(
                            target.maxLength,
                            field.maxLength,
                        );
                    target.sources.push({
                        questionKey: row.definition.key,
                        fieldKey: field.key,
                    });
                }
            }
            const result = [...merged.values()];
            const labelCounts = new Map();
            for (const field of result) {
                const label = normalizeLabel(field.label);
                labelCounts.set(label, (labelCounts.get(label) || 0) + 1);
            }
            for (const field of result) {
                if (labelCounts.get(normalizeLabel(field.label)) > 1)
                    field.displayLabel =
                        field.label + " (" + typeLabels[field.type] + ")";
            }
            return result;
        }

        function prune() {
            const needed = new Set(fields().map((field) => field.id));
            for (const id of entered.keys())
                if (!needed.has(id)) entered.delete(id);
        }

        function syncDefinitions(next) {
            if (locked()) return false;
            const previousScope = consentScope();
            const updated = new Map();
            for (const definition of normalizeDefinitions(next)) {
                const previous = rows.get(definition.key);
                if (previous?.saved) {
                    updated.set(definition.key, previous);
                } else if (
                    previous &&
                    definitionSignature(previous.definition) ===
                        definitionSignature(definition)
                ) {
                    updated.set(definition.key, previous);
                } else {
                    const selected =
                        previous?.selected.filter((value) =>
                            definition.triggerValues.includes(value),
                        ) || [];
                    updated.set(definition.key, {
                        definition,
                        selected,
                        active: Boolean(previous?.active && selected.length),
                        included: false,
                        saved: false,
                        request: null,
                    });
                }
            }
            for (const [key, row] of rows) {
                if (row.saved && !updated.has(key)) updated.set(key, row);
            }
            rows = updated;
            if (globalConsent && previousScope !== consentScope())
                setGlobalConsent(false);
            prune();
            return true;
        }

        function syncSelections(selections) {
            if (locked()) return false;
            const previousScope = consentScope();
            const next = new Map(
                selections.map((selection) => [selection.key, selection]),
            );
            for (const [key, row] of rows) {
                if (row.saved) continue;
                const selection = next.get(key);
                const selected = row.definition.triggerValues.filter((value) =>
                    selection?.values?.includes(value),
                );
                const active =
                    selection?.visible !== false &&
                    Boolean(selection) &&
                    selected.length > 0;
                row.active = active;
                row.selected = selected;
                row.included = globalConsent && active;
            }
            if (globalConsent && previousScope !== consentScope())
                setGlobalConsent(false);
            prune();
            return true;
        }

        function setGlobalConsent(value) {
            if (locked()) return false;
            const next = value === true;
            if (next === globalConsent) return true;
            globalConsent = next;
            for (const row of rows.values()) {
                row.included = next && row.active && !row.saved;
            }
            if (!next) entered.clear();
            prune();
            return true;
        }

        function scatter(key) {
            const row = rows.get(key);
            if (!row || !included(row)) return {};
            return Object.fromEntries(
                row.definition.fields.map((field) => [
                    field.key,
                    (entered.get(fieldId(field)) || "").trim(),
                ]),
            );
        }

        function validate() {
            const errors = [];
            for (const field of fields()) {
                const value = field.value.trim();
                let code = null;
                if (field.required && !value) code = "required";
                else if (value && field.type === "tel" && !validPhone(value))
                    code = "phone";
                else if (
                    value &&
                    field.maxLength !== null &&
                    [...value].length > field.maxLength
                )
                    code = "maxLength";
                else if (
                    value &&
                    field.type === "role" &&
                    !roles.includes(value)
                )
                    code = "role";
                else if (
                    value &&
                    field.type === "email" &&
                    !/^[^\s@]+@[^\s@]+\.[^\s@]+$/u.test(value)
                )
                    code = "email";
                if (code)
                    errors.push({
                        fieldId: field.id,
                        questionKey: field.sources[0].questionKey,
                        code,
                    });
            }
            for (const [key, row] of rows) {
                if (included(row) && !Object.values(scatter(key)).some(Boolean))
                    errors.push({
                        questionKey: key,
                        fieldId: row.definition.fields[0]
                            ? fieldId(row.definition.fields[0])
                            : null,
                        code: "empty",
                    });
            }
            return errors;
        }

        function prepare(key, { contactId, multiple = false }) {
            const row = rows.get(key);
            if (row?.request) return row.request.payload;
            if (!row || !included(row) || validate().length) return null;
            const payload = freezePayload({
                contact_id: contactId,
                consent: true,
                answer: multiple ? [...row.selected] : row.selected[0],
                fields: scatter(key),
            });
            row.request = { payload, uncertain: false };
            return payload;
        }

        function settle(key, outcome) {
            const row = rows.get(key);
            if (!row?.request) return false;
            if (outcome === "ambiguous") {
                row.request.uncertain = true;
                return true;
            }
            if (outcome === "saved") row.saved = true;
            else if (outcome !== "rejected" || row.request.uncertain)
                return false;
            row.request = null;
            prune();
            return true;
        }

        syncDefinitions(definitions);
        return Object.freeze({
            syncDefinitions,
            syncSelections,
            setGlobalConsent,
            fields,
            scatter,
            validate,
            prepare,
            settle,
            get globalConsent() {
                return globalConsent;
            },
            get locked() {
                return locked();
            },
            value: (id) => entered.get(id) || "",
            setValue(id, value) {
                if (
                    locked() ||
                    typeof value !== "string" ||
                    !fields().some((field) => field.id === id)
                )
                    return false;
                entered.set(id, value);
                return true;
            },
            pending: (key) => rows.get(key)?.request?.payload || null,
            activities: () =>
                [...rows.values()].map((row) => ({
                    key: row.definition.key,
                    title: row.definition.title,
                    prompt: row.definition.prompt,
                    purpose: row.definition.purpose,
                    active: row.active,
                    included: row.included,
                    saved: row.saved,
                    pending: row.request !== null,
                    uncertain: row.request?.uncertain === true,
                })),
        });
    }

    globalThis.MikausisSharedContacts = Object.freeze({ create });
})();
