import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { dirname, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";

const root = join(dirname(fileURLToPath(import.meta.url)), "../..");
const modulePath = pathToFileURL(join(root, "preza/assets/preza.mjs")).href;
const { clampIndex, nextIndex, prevIndex, slideLabel, isAdvanceKey, isRetreatKey } = await import(modulePath);

assert.equal(clampIndex(-1, 10), 0);
assert.equal(clampIndex(99, 10), 9);
assert.equal(clampIndex(3.7, 10), 3);
assert.equal(clampIndex(2, 0), 0);
assert.equal(nextIndex(0, 3), 1);
assert.equal(nextIndex(2, 3), 2);
assert.equal(prevIndex(0, 3), 0);
assert.equal(prevIndex(2, 3), 1);
assert.equal(slideLabel(0, 10), "Слайд 1 из 10");
assert.equal(slideLabel(9, 10), "Слайд 10 из 10");

assert.equal(isAdvanceKey({ key: "PageDown", code: "PageDown" }), true);
assert.equal(isAdvanceKey({ key: " ", code: "Space" }), true);
assert.equal(isAdvanceKey({ key: "Enter", code: "Enter" }), true);
assert.equal(isAdvanceKey({ key: "n", code: "KeyN" }), true);
assert.equal(isRetreatKey({ key: "PageUp", code: "PageUp" }), true);
assert.equal(isRetreatKey({ key: "Backspace", code: "Backspace" }), true);
assert.equal(isRetreatKey({ key: "p", code: "KeyP" }), true);
assert.equal(isAdvanceKey({ key: "a", code: "KeyA" }), false);

const html = readFileSync(join(root, "preza/index.html"), "utf8");
assert.match(html, /type="module" src="assets\/preza\.mjs"/);
assert.match(html, /data-deck-print/);

const js = readFileSync(join(root, "preza/assets/preza.mjs"), "utf8");
assert.match(js, /isAdvanceKey/);
assert.match(js, /MediaTrackNext|PageDown/);
assert.match(js, /contextmenu/);

console.log("preza-deck-verify: ok");
