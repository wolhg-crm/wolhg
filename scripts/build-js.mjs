import { mkdir, readFile, writeFile } from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const rootDir = path.resolve(__dirname, "..");

const BUILD_TARGETS = [
  {
    input: path.join(rootDir, "public", "assets", "app.js"),
    output: path.join(rootDir, "public", "assets", "app.min.js"),
  },
];

function minifyJs(sourceCode) {
  // A conservative minifier: removes comments and collapses whitespace
  // outside of strings/templates so output stays behavior-safe.
  let result = "";
  let inSingleQuote = false;
  let inDoubleQuote = false;
  let inTemplate = false;
  let inLineComment = false;
  let inBlockComment = false;
  let escaped = false;
  let previousWasWhitespace = false;

  for (let i = 0; i < sourceCode.length; i += 1) {
    const char = sourceCode[i];
    const nextChar = sourceCode[i + 1];

    if (inLineComment) {
      if (char === "\n") {
        inLineComment = false;
        previousWasWhitespace = true;
      }
      continue;
    }

    if (inBlockComment) {
      if (char === "*" && nextChar === "/") {
        inBlockComment = false;
        i += 1;
      }
      continue;
    }

    if (!inSingleQuote && !inDoubleQuote && !inTemplate) {
      if (char === "/" && nextChar === "/") {
        inLineComment = true;
        i += 1;
        continue;
      }
      if (char === "/" && nextChar === "*") {
        inBlockComment = true;
        i += 1;
        continue;
      }
    }

    if (!inDoubleQuote && !inTemplate && char === "'" && !escaped) {
      inSingleQuote = !inSingleQuote;
      result += char;
      previousWasWhitespace = false;
      continue;
    }

    if (!inSingleQuote && !inTemplate && char === "\"" && !escaped) {
      inDoubleQuote = !inDoubleQuote;
      result += char;
      previousWasWhitespace = false;
      continue;
    }

    if (!inSingleQuote && !inDoubleQuote && char === "`" && !escaped) {
      inTemplate = !inTemplate;
      result += char;
      previousWasWhitespace = false;
      continue;
    }

    escaped = (inSingleQuote || inDoubleQuote || inTemplate) && char === "\\" && !escaped;

    if (!inSingleQuote && !inDoubleQuote && !inTemplate && /\s/.test(char)) {
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
    .replace(/\s*([{}();,:])\s*/g, "$1")
    .replace(/\s+/g, " ")
    .trim();
}

async function build() {
  for (const target of BUILD_TARGETS) {
    const source = await readFile(target.input, "utf8");
    const minified = minifyJs(source);

    await mkdir(path.dirname(target.output), { recursive: true });
    await writeFile(target.output, `${minified}\n`, "utf8");

    console.log(`Built ${path.relative(rootDir, target.output)}`);
  }
}

build().catch((error) => {
  console.error("JS build failed:", error);
  process.exitCode = 1;
});
