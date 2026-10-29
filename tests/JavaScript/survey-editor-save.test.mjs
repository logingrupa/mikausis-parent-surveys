import assert from "node:assert/strict";
import test from "node:test";
import surveyEditor from "../../public/js/survey-editor.js";

function editor() {
    return surveyEditor({
        state: {
            title: "Aptauja",
            allow_identified_responses: false,
            sections: [{ key: "all", title: "Jautājumi" }],
            questions: [
                {
                    key: "legacy_text",
                    section: "all",
                    title: "Jūsu ieteikumi",
                    type: "text",
                },
                {
                    key: "choice",
                    section: "all",
                    title: "Vai piedalīsieties?",
                    type: "single",
                    options: [{ value: "yes", label: "Jā" }],
                },
            ],
        },
    });
}

test("saving legacy text questions without options sends the request without rewriting absent schema fields", async () => {
    const instance = editor();
    const original = structuredClone(instance.state);
    const saved = [];
    instance.$wire = {
        save: async () => {
            assert.equal(instance.saving, true);
            saved.push(structuredClone(instance.state));
        },
    };

    await instance.save();

    assert.deepEqual(saved, [original]);
    assert.deepEqual(instance.state, original);
    assert.equal(Object.hasOwn(instance.state.questions[0], "options"), false);
    assert.equal(instance.saving, false);
});

test("option normalization failures unlock the editor and allow a corrected save", async () => {
    const instance = editor();
    instance.state.questions.shift();
    instance.state.questions[0].options[0].label = 42;
    let requests = 0;
    instance.$wire = {
        save: async () => {
            assert.equal(instance.saving, true);
            requests++;
        },
    };

    await assert.rejects(instance.save(), TypeError);
    assert.equal(requests, 0);
    assert.equal(instance.saving, false);

    instance.state.questions[0].options[0].label = "Jā";
    await instance.save();
    assert.equal(requests, 1);
    assert.equal(instance.saving, false);
});

test("preview preparation failures unlock the editor and allow retrying the save", async () => {
    const instance = editor();
    instance.state.questions.shift();
    const original = structuredClone(instance.state);
    const syncPreviewContacts = instance.syncPreviewContacts;
    const failure = new Error("Preview preparation failed");
    instance.syncPreviewContacts = () => {
        assert.equal(instance.saving, true);
        throw failure;
    };
    let requests = 0;
    instance.$wire = {
        save: async () => {
            assert.equal(instance.saving, true);
            requests++;
        },
    };

    await assert.rejects(instance.save(), (error) => error === failure);
    assert.equal(requests, 0);
    assert.equal(instance.saving, false);
    assert.deepEqual(instance.state, original);

    instance.syncPreviewContacts = syncPreviewContacts;
    await instance.save();
    assert.equal(requests, 1);
    assert.equal(instance.saving, false);
    assert.deepEqual(instance.state, original);
});
