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
    let prints = 0;
    window.requestAnimationFrame = () => {
        throw new Error("Printing must not depend on animation frames");
    };
    window.print = () => {
        prints++;
    };
    runInNewContext(source, { document, window });

    return { window, prints: () => prints };
}

test("automatic print waits for load, fonts and image decodes without animation frames", async () => {
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

    document.readyState = "complete";
    page.window.dispatchEvent(new Event("load"));
    await setImmediate();
    assert.deepEqual(decoded, []);
    assert.equal(page.prints(), 0);

    fonts.resolve();
    await setImmediate();
    assert.deepEqual(decoded, [0, 1]);
    assert.equal(page.prints(), 0);

    logo.resolve();
    await setImmediate();
    assert.equal(page.prints(), 0);

    other.resolve();
    await setImmediate();
    assert.equal(page.prints(), 1);

    page.window.dispatchEvent(new Event("load"));
    await setImmediate();
    assert.equal(page.prints(), 1);
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
    assert.equal(page.prints(), 1);
});

test("manual print while assets load cancels the later automatic dialog", async () => {
    const fonts = deferred();
    const page = report({
        readyState: "complete",
        fonts: { ready: fonts.promise },
        images: [],
    });

    page.window.dispatchEvent(new Event("beforeprint"));
    fonts.resolve();
    await setImmediate();
    assert.equal(page.prints(), 0);
});
