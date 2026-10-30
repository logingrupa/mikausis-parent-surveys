export const definitionHash = "a".repeat(64);
export const contactId = (index = 1) =>
    "7106690b-4e47-47d8-b942-" + String(index).padStart(12, "0");
export const definitions = ["a", "b", "c"].map((key, index) => ({
    key,
    title: "Aktivitāte " + key,
    prompt: "Atstāt kontaktinformāciju",
    purpose: "Organizēšanai",
    triggerValues: ["yes", "maybe"],
    fields: [{ key: "email", label: "E-pasts", type: "email", required: true }],
    endpoint: "/contacts/" + key,
    contactId: contactId(index + 1),
    multiple: key === "b",
}));

export function memoryStorage() {
    const values = new Map();
    return {
        values,
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
}

export const cacheKey = (scope = "survey") =>
    "mikausis.contact-followup:" + scope;
export const response = (status, result) => ({
    status,
    ok: status >= 200 && status < 300,
    json: async () => result,
});
