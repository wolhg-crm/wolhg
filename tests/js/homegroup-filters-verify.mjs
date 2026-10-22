import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const source = fs.readFileSync(path.join(root, "public/assets/app.js"), "utf8");
const start = source.indexOf("function homegroupFilterSummary");
const end = source.indexOf("function updateFilterDropdownSummary");
if (start < 0 || end < 0 || end <= start) {
  console.error("Could not locate homegroup filter helpers in app.js");
  process.exit(1);
}

const block = source.slice(start, end) + `
return { homegroupFilterSummary };
`;
const { homegroupFilterSummary } = new Function(block)();

const empty = homegroupFilterSummary([]);
if (empty.text !== "Любой" || !empty.placeholder) {
  console.error("Expected empty summary to be placeholder 'Любой'");
  process.exit(1);
}

const one = homegroupFilterSummary(["Взрослые"]);
if (one.text !== "Взрослые" || one.placeholder) {
  console.error("Expected single selection label");
  process.exit(1);
}

const two = homegroupFilterSummary(["Взрослые", "Подростки"]);
if (two.text !== "Взрослые, Подростки" || two.placeholder) {
  console.error("Expected two labels joined by comma");
  process.exit(1);
}

const many = homegroupFilterSummary(["A", "B", "C"]);
if (many.text !== "3 выбрано" || many.placeholder) {
  console.error("Expected count summary for three selections");
  process.exit(1);
}

console.log("homegroup-filters-verify: ok");
