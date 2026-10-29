import assert from "node:assert/strict";
import test from "node:test";
import surveyEditor from "../../public/js/survey-editor.js";

function editor() {
    const questions = ["first", "second", "third"].map((key) => ({
        key,
        section: "all",
        title: key,
        type: "single",
        options: [
            { value: "yes", label: "Jā" },
            { value: "later", label: "Vēlāk" },
            { value: "no", label: "Nē" },
        ],
        contact: {
            prompt: "Atstāt kontaktus",
            purpose: "Saziņai par aktivitāti",
            trigger_values: ["yes", "later"],
            fields: [
                {
                    key: "email_" + key,
                    label: "E-pasts",
                    type: "email",
                    required: true,
                },
            ],
        },
    }));
    const instance = surveyEditor({
        state: {
            allow_identified_responses: false,
            respondent_fields: [],
            sections: [{ key: "all", title: "Jautājumi" }],
            questions,
        },
    });
    instance.$nextTick = () => {};
    return instance;
}

test("one preview entry fills configured activity keys without entering saved survey state", () => {
    const instance = editor();
    const original = JSON.stringify(instance.state);
    instance.answerPreview(instance.state.questions[0], "yes", true);
    instance.answerPreview(instance.state.questions[1], "yes", true);
    assert.equal(instance.previewShared.activities.length, 2);
    assert.equal(instance.previewShared.consent, false);
    assert.deepEqual(instance.previewShared.fields, []);
    instance.setSharedPreviewConsent(true);
    assert.equal(instance.previewShared.fields.length, 1);
    instance.setSharedPreviewValue(
        instance.previewShared.fields[0].id,
        "preview@example.test",
    );
    assert.deepEqual(instance.previewSharedController.scatter("first"), {
        email_first: "preview@example.test",
    });
    assert.deepEqual(instance.previewSharedController.scatter("second"), {
        email_second: "preview@example.test",
    });
    assert.deepEqual(instance.previewSharedController.scatter("third"), {});
    assert.equal(JSON.stringify(instance.state), original);
    instance.togglePreview(false);
    assert.equal(instance.previewShared.consent, false);
    assert.deepEqual(instance.previewShared.fields, []);
    assert.doesNotMatch(
        JSON.stringify(instance.previewSharedController.activities()),
        /preview@example/,
    );
});

test("common preview wording is presented once while distinct legacy prompts and purposes remain distinct", () => {
    const instance = editor();
    for (const question of instance.state.questions.slice(0, 2))
        instance.answerPreview(question, "yes", true);
    const before = JSON.stringify(instance.state);
    assert.equal(instance.previewSharedCommonPrompt, "Atstāt kontaktus");
    assert.equal(instance.previewSharedCommonPurpose, "Saziņai par aktivitāti");
    assert.equal(JSON.stringify(instance.state), before);
    instance.state.questions[1].contact.prompt = "Sazinieties par koncertu";
    instance.state.questions[1].contact.purpose = "Tikai koncertam";
    instance.syncSharedPreviewContacts();
    assert.equal(instance.previewSharedCommonPrompt, "");
    assert.equal(instance.previewSharedCommonPurpose, "");
    assert.equal(
        instance.previewShared.activities[1].purpose,
        "Tikai koncertam",
    );
});

test("changing eligible answers clears global consent and values until the parent opts in to the new scope", () => {
    const instance = editor();
    for (const question of instance.state.questions.slice(0, 2))
        instance.answerPreview(question, "yes", true);
    instance.setSharedPreviewConsent(true);
    instance.setSharedPreviewValue(
        instance.previewShared.fields[0].id,
        "preview@example.test",
    );
    instance.answerPreview(instance.state.questions[0], "later", true);
    assert.equal(
        instance.previewShared.activities.find((item) => item.key === "first")
            .included,
        false,
    );
    assert.equal(
        instance.previewShared.activities.find((item) => item.key === "second")
            .included,
        false,
    );
    assert.equal(instance.previewShared.consent, false);
    assert.deepEqual(instance.previewSharedController.scatter("first"), {});
    assert.deepEqual(instance.previewShared.fields, []);
    instance.answerPreview(instance.state.questions[2], "yes", true);
    assert.equal(
        instance.previewShared.activities.find((item) => item.key === "third")
            .included,
        false,
    );
    instance.setSharedPreviewConsent(true);
    assert.equal(instance.previewShared.fields[0].value, "");
    assert.ok(
        instance.previewShared.activities.every(
            (activity) => activity.included,
        ),
    );
    instance.setSharedPreviewValue(
        instance.previewShared.fields[0].id,
        "new@example.test",
    );
    assert.deepEqual(instance.previewSharedController.scatter("third"), {
        email_third: "new@example.test",
    });
});
