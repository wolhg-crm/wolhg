import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const source = fs.readFileSync(path.join(root, "public/assets/app.js"), "utf8");
const start = source.indexOf("const PHONE_PREFIX");
const end = source.indexOf("function ensureContactMask");
if (start < 0 || end < 0 || end <= start) {
  console.error("Could not locate phone mask helpers in app.js");
  process.exit(1);
}

const block = source.slice(start, end) + `
return {
  PHONE_PREFIX,
  formatPhoneMask,
  countMeaningfulBefore,
  caretForMeaningfulCount
};
`;
const {
  PHONE_PREFIX,
  formatPhoneMask,
  countMeaningfulBefore,
  caretForMeaningfulCount
} = new Function(block)();

function applyMaskStep(value, caret) {
  const meaningful = countMeaningfulBefore(value, caret, "phone");
  const next = formatPhoneMask(value);
  const nextCaret = caretForMeaningfulCount(next, meaningful, "phone");
  return { value: next, caret: nextCaret };
}

function typeDigits(digits) {
  let value = "+7 ";
  let caret = value.length;
  for (const ch of digits) {
    value = value.slice(0, caret) + ch + value.slice(caret);
    caret += 1;
    ({ value, caret } = applyMaskStep(value, caret));
  }
  return value;
}

const typed = typeDigits("977818");
if (typed !== "+7 977 818") {
  console.error(`Expected "+7 977 818", got "${typed}"`);
  process.exit(1);
}

// Caret must stay after the last typed digit, not inside the country prefix.
let value = "+7 ";
let caret = value.length;
for (const ch of "977818") {
  value = value.slice(0, caret) + ch + value.slice(caret);
  caret += 1;
  ({ value, caret } = applyMaskStep(value, caret));
  if (caret < PHONE_PREFIX.length) {
    console.error(`Caret moved inside prefix after typing "${ch}": ${caret}`);
    process.exit(1);
  }
}

console.log("phone-mask-verify: ok");
