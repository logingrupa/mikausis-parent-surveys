import test from 'node:test';
import assert from 'node:assert/strict';
import vm from 'node:vm';
import { readFileSync } from 'node:fs';

const source = readFileSync(new URL('../../public/js/survey-share.js', import.meta.url), 'utf8');

function runtime(navigator = {}) {
    const statuses = [];
    const prompts = [];
    const window = {
        location: { href: 'https://survey.example/admin', origin: 'https://survey.example' },
        prompt: (...args) => prompts.push(args),
    };
    vm.runInNewContext(source, { window, navigator, URL, WeakSet, document: { createElement: () => ({ dataset: {}, setAttribute() {} }) } });
    const button = {
        dataset: { shareUrl: 'https://survey.example/a/survey-slug', shareTitle: 'Survey title', shareCopy: 'Copy link', shareSuccess: 'Copied' },
        parentElement: { querySelector: () => statuses[0], append: (status) => statuses.push(status) },
        disabled: false,
        setAttribute() {},
        removeAttribute() {},
    };
    return { button, statuses, prompts, share: () => window.MikausisSurveyShare.share(button) };
}

test('native sharing receives only the public URL and title', async () => {
    const calls = [];
    const state = runtime({ share: async (data) => calls.push(data) });
    await state.share();
    assert.deepEqual(JSON.parse(JSON.stringify(calls)), [{ title: 'Survey title', url: 'https://survey.example/a/survey-slug' }]);
    assert.equal(state.button.disabled, false);
});

test('unsupported native sharing copies and announces the public link', async () => {
    const copied = [];
    const state = runtime({ clipboard: { writeText: async (url) => copied.push(url) } });
    await state.share();
    assert.deepEqual(copied, ['https://survey.example/a/survey-slug']);
    assert.equal(state.statuses[0].textContent, 'Copied');
    assert.equal(state.prompts.length, 0);
});

test('cancelled native sharing does not copy or prompt', async () => {
    let copied = false;
    const state = runtime({ share: async () => { throw { name: 'AbortError' }; }, clipboard: { writeText: async () => { copied = true; } } });
    await state.share();
    assert.equal(copied, false);
    assert.equal(state.prompts.length, 0);
    assert.equal(state.button.disabled, false);
});

test('clipboard failure gives a manual copy fallback', async () => {
    const state = runtime({ clipboard: { writeText: async () => { throw new Error(); } } });
    await state.share();
    assert.deepEqual(state.prompts, [['Copy link', 'https://survey.example/a/survey-slug']]);
});

test('native permission failure falls back to copying', async () => {
    const copied = [];
    const state = runtime({ share: async () => { throw { name: 'NotAllowedError' }; }, clipboard: { writeText: async (url) => copied.push(url) } });
    await state.share();
    assert.equal(copied.length, 1);
});

test('unsupported data uses the copy fallback', async () => {
    let shared = false;
    const state = runtime({ canShare: () => false, share: async () => { shared = true; } });
    await state.share();
    assert.equal(shared, false);
    assert.equal(state.prompts.length, 1);
});

test('native capability errors still allow manual copying', async () => {
    const state = runtime({ canShare: () => { throw new Error(); }, share: async () => {} });
    await state.share();
    assert.equal(state.prompts.length, 1);
    assert.equal(state.button.disabled, false);
});

test('duplicate activation is ignored while sharing is pending', async () => {
    let finish;
    let calls = 0;
    const state = runtime({ share: () => { calls++; return new Promise((resolve) => { finish = resolve; }); } });
    const first = state.share();
    await state.share();
    assert.equal(calls, 1);
    finish();
    await first;
    assert.equal(state.button.disabled, false);
});

test('family tokens, external hosts, credentials and query strings are never shared', async () => {
    for (const url of ['https://survey.example/a/survey/g/private-token', 'https://elsewhere.example/a/survey', 'https://user:password@survey.example/a/survey', 'https://survey.example/a/survey?token=private', 'javascript:alert(1)']) {
        const state = runtime();
        state.button.dataset.shareUrl = url;
        await state.share();
        assert.equal(state.prompts.length, 0);
        assert.equal(state.statuses.length, 0);
    }
});
