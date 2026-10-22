import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, "..");

const INPUT_FILE = path.join(rootDir, "public", "assets", "app.css");
const OUTPUT_FILE = path.join(rootDir, "public", "assets", "app.min.css");

function minifyCss(sourceCode) {
  let result = "";
  let inSingleQuote = false;
  let inDoubleQuote = false;
  let inComment = false;
  let escaped = false;
  let previousWasWhitespace = false;

  for (let i = 0; i < sourceCode.length; i += 1) {
    const char = sourceCode[i];
    const nextChar = sourceCode[i + 1];

    if (inComment) {
      if (char === "*" && nextChar === "/") {
        inComment = false;
        i += 1;
      }
      continue;
    }

    if (!inSingleQuote && !inDoubleQuote && char === "/" && nextChar === "*") {
      inComment = true;
      i += 1;
      continue;
    }

    if (!inDoubleQuote && char === "'" && !escaped) {
      inSingleQuote = !inSingleQuote;
      result += char;
      previousWasWhitespace = false;
      continue;
    }

    if (!inSingleQuote && char === "\"" && !escaped) {
      inDoubleQuote = !inDoubleQuote;
      result += char;
      previousWasWhitespace = false;
      continue;
    }

    escaped = (inSingleQuote || inDoubleQuote) && char === "\\" && !escaped;

    if (!inSingleQuote && !inDoubleQuote && /\s/.test(char)) {
      if (!previousWasWhitespace) {
        result += " ";
        previousWasWhitespace = true;
      }
      continue;
    }

    result += char;
    previousWasWhitespace = false;
  }

  return result
    .replace(/\s*([{}:;,>+~])\s*/g, "$1")
    .replace(/;}/g, "}")
    .replace(/\s+/g, " ")
    .trim();
}

async function build() {
  const source = await readFile(INPUT_FILE, "utf8");
  const minified = minifyCss(source);

  await mkdir(path.dirname(OUTPUT_FILE), { recursive: true });
  await writeFile(OUTPUT_FILE, `${minified}\n`, "utf8");

  console.log(`Built ${path.relative(rootDir, OUTPUT_FILE)}`);
}

build().catch((error) => {
  console.error("CSS build failed:", error);
  process.exitCode = 1;
});
