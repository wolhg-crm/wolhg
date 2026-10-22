import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const source = fs.readFileSync(path.join(root, "public/assets/app.js"), "utf8");

const required = [
  "let currentDocumentBase",
  "function isSameDocumentUrl",
  "function scrollToHash",
  "function navigateSameDocumentHash",
  "if (url.hash && isSameDocumentUrl(url)) return false;",
  "if (link && navigateSameDocumentHash(link.href))",
  "if (isSameDocumentUrl(window.location.href))"
];

for (const marker of required) {
  if (!source.includes(marker)) {
    console.error(`Missing AJAX anchor navigation marker: ${marker}`);
    process.exit(1);
  }
}

const sameHashGuard = source.indexOf("if (link && navigateSameDocumentHash(link.href))");
const ajaxGuard = source.indexOf("if (!shouldAjaxLink(link, event)) return;", sameHashGuard);
if (sameHashGuard < 0 || ajaxGuard < 0 || sameHashGuard > ajaxGuard) {
  console.error("Expected same-document hash handling before AJAX link handling");
  process.exit(1);
}

const popstateGuard = source.indexOf("if (isSameDocumentUrl(window.location.href))");
const popstateLoad = source.indexOf("loadPage(window.location.href", popstateGuard);
if (popstateGuard < 0 || popstateLoad < 0 || popstateGuard > popstateLoad) {
  console.error("Expected popstate same-document guard before AJAX loadPage");
  process.exit(1);
}

console.log("ajax-anchor-navigation-verify: ok");
