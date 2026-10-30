import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { setImmediate } from "node:timers/promises";
import test from "node:test";
import { runInNewContext } from "node:vm";

const source = readFileSync(
    new URL("../../public/js/report-print.js", import.meta.url),
    "utf8",
);

function deferred() {
    let resolve;
    const promise = new Promise((done) => {
        resolve = done;
    });
    return { promise, resolve };
}

function report(document) {
    const window = new EventTarget();
    const frames = [];
    let prints = 0;
    window.requestAnimationFrame = (callback) => frames.push(callback);
    window.print = () => {
        prints++;
    };
    runInNewContext(source, { document, window });

    return { window, frames, prints: () => prints };
}

test("automatic print waits for load, fonts, all image decodes, and two frames", async () => {
    const fonts = deferred();
    const logo = deferred();
    const other = deferred();
    const decoded = [];
    const document = {
        readyState: "loading",
        fonts: { ready: fonts.promise },
        images: [logo, other].map((image, index) => ({
            decode() {
                decoded.push(index);
                return image.promise;
            },
        })),
    };
    const page = report(document);
    await setImmediate();
    assert.equal(page.prints(), 0);
    assert.deepEqual(decoded, []);
    assert.equal(page.frames.length, 0);

    document.readyState = "complete";
    page.window.dispatchEvent(new Event("load"));
    await setImmediate();
    assert.deepEqual(decoded, []);
    assert.equal(page.prints(), 0);

    fonts.resolve();
    await setImmediate();
    assert.deepEqual(decoded, [0, 1]);
    assert.equal(page.frames.length, 0);
    assert.equal(page.prints(), 0);

    logo.resolve();
    await setImmediate();
    assert.equal(page.frames.length, 0);
    assert.equal(page.prints(), 0);

    other.resolve();
    await setImmediate();
    assert.equal(page.frames.length, 1);
    assert.equal(page.prints(), 0);
    page.frames.shift()();
    assert.equal(page.frames.length, 1);
    assert.equal(page.prints(), 0);
    page.frames.shift()();
    await setImmediate();
    assert.equal(page.prints(), 1);

    page.window.dispatchEvent(new Event("load"));
    await setImmediate();
    assert.equal(page.prints(), 1);
    assert.equal(page.frames.length, 0);
});

test("an already loaded report prints despite a failed decode or missing asset APIs", async () => {
    const page = report({
        readyState: "complete",
        images: [
            { decode: () => Promise.reject(new Error("Broken image")) },
            {},
        ],
    });

    await setImmediate();
    assert.equal(page.frames.length, 1);
    assert.equal(page.prints(), 0);
    page.frames.shift()();
    assert.equal(page.prints(), 0);
    page.frames.shift()();
    await setImmediate();
    assert.equal(page.prints(), 1);
    assert.equal(page.frames.length, 0);
});
