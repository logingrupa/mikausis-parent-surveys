(() => {
    "use strict";

    const ttl = 60 * 60 * 1000;
    const maxSize = 64 * 1024;
    const maxEntries = 200;
    const identifier = /^[a-zA-Z0-9_-]{1,160}$/u;
    const uuid =
        /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iu;
    const statuses = new Set(["available", "saved", "uncertain"]);
    const exactKeys = (value, keys) =>
        value !== null &&
        typeof value === "object" &&
        !Array.isArray(value) &&
        Object.keys(value).length === keys.length &&
        keys.every((key) => Object.hasOwn(value, key));

    function create({
        scope,
        definition,
        definitions = [],
        now = Date.now,
        storage,
    } = {}) {
        const enabled =
            typeof scope === "string" &&
            identifier.test(scope) &&
            typeof definition === "string" &&
            /^[0-9a-f]{64}$/u.test(definition);
        const key = enabled ? "mikausis.contact-followup:" + scope : null;
        const current = new Map();
        if (Array.isArray(definitions) && definitions.length <= maxEntries) {
            for (const item of definitions) {
                if (
                    typeof item?.key !== "string" ||
                    !identifier.test(item.key) ||
                    current.has(item.key) ||
                    !Array.isArray(item.triggerValues)
                ) {
                    current.clear();
                    break;
                }
                current.set(item.key, {
                    contactId: item.contactId,
                    multiple: item.multiple === true,
                    triggerValues: item.triggerValues.filter(
                        (value) =>
                            typeof value === "string" &&
                            value.length > 0 &&
                            value.length <= 1024,
                    ),
                });
            }
        }
        if (storage === undefined) {
            try {
                storage = globalThis.sessionStorage;
            } catch {
                storage = null;
            }
        }
        let record = null;

        function remove() {
            try {
                if (key) storage?.removeItem(key);
            } catch {}
        }

        function validEntry(entry) {
            const item = current.get(entry?.key);
            return (
                exactKeys(entry, ["key", "values", "contactId", "status"]) &&
                Boolean(item) &&
                typeof entry.contactId === "string" &&
                uuid.test(entry.contactId) &&
                statuses.has(entry.status) &&
                Array.isArray(entry.values) &&
                entry.values.length > 0 &&
                entry.values.length <= 100 &&
                (item.multiple || entry.values.length === 1) &&
                new Set(entry.values).size === entry.values.length &&
                entry.values.every((value) =>
                    item.triggerValues.includes(value),
                )
            );
        }

        function validRecord(value) {
            const time = now();
            return (
                exactKeys(value, [
                    "version",
                    "definition",
                    "expiresAt",
                    "entries",
                ]) &&
                value.version === 1 &&
                value.definition === definition &&
                Number.isSafeInteger(value.expiresAt) &&
                value.expiresAt > time &&
                value.expiresAt <= time + ttl &&
                Array.isArray(value.entries) &&
                value.entries.length <= maxEntries &&
                new Set(value.entries.map((entry) => entry?.key)).size ===
                    value.entries.length &&
                value.entries.every(validEntry)
            );
        }

        if (enabled) {
            try {
                const raw = storage?.getItem(key);
                if (typeof raw === "string") {
                    const parsed =
                        raw.length <= maxSize ? JSON.parse(raw) : null;
                    if (validRecord(parsed)) record = parsed;
                    else remove();
                }
            } catch {
                remove();
            }
        }

        function persist() {
            if (!enabled || !record) return;
            try {
                const raw = JSON.stringify(record);
                if (raw.length > maxSize) {
                    record = null;
                    remove();
                    return;
                }
                storage?.setItem(key, raw);
            } catch {
                remove();
            }
        }

        function entries() {
            if (!record || !validRecord(record)) {
                record = null;
                remove();
                return [];
            }
            return record.entries.map((entry) => ({
                ...entry,
                values: [...entry.values],
            }));
        }

        function capture(selections) {
            if (!enabled || !Array.isArray(selections)) return;
            const selected = new Map(
                selections.map((selection) => [selection?.key, selection]),
            );
            const eligible = [];
            for (const [questionKey, item] of current) {
                const selection = selected.get(questionKey);
                if (
                    !selection ||
                    selection.visible === false ||
                    !Array.isArray(selection.values)
                )
                    continue;
                const values = [
                    ...new Set(
                        item.triggerValues.filter((value) =>
                            selection.values.includes(value),
                        ),
                    ),
                ];
                const entry = {
                    key: questionKey,
                    values,
                    contactId: item.contactId,
                    status: "available",
                };
                if (validEntry(entry)) eligible.push(entry);
            }
            record = {
                version: 1,
                definition,
                expiresAt: now() + ttl,
                entries: eligible,
            };
            persist();
        }

        function mark(keys, status) {
            if (
                !statuses.has(status) ||
                !Array.isArray(keys) ||
                !entries().length
            )
                return;
            const selected = new Set(keys);
            for (const entry of record.entries) {
                if (selected.has(entry.key) && entry.status !== "saved")
                    entry.status = status;
            }
            persist();
        }

        function clear() {
            record = null;
            remove();
        }

        return Object.freeze({ capture, entries, mark, clear });
    }

    globalThis.MikausisContactFollowupState = Object.freeze({ create });
})();
