import fs from "node:fs";
import path from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import vm from "node:vm";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const source = fs.readFileSync(path.join(root, "public/assets/app.js"), "utf8");

const start = source.indexOf("function crmTenantSlugFromPath");
const end = source.indexOf("function requiresFullNavigation");
if (start < 0 || end < 0 || end <= start) {
  console.error("Could not locate crm tenant path helpers in app.js");
  process.exit(1);
}

const helpers = source.slice(start, end);
const sandbox = {
  window: { location: { pathname: "/pushkino/person" } },
  document: { body: { dataset: { churchSlug: "pushkino" } } },
  console,
};
vm.createContext(sandbox);
vm.runInContext(helpers, sandbox);

const { crmTenantSlugFromPath, crmCurrentTenantSlug, crmTenantApiPath } = sandbox;

function assert(condition, message) {
  if (!condition) {
    console.error(message);
    process.exit(1);
  }
}

// TC1: slug from church CRM path
assert(crmTenantSlugFromPath("/pushkino/person") === "pushkino", "TC1 failed: slug from path");
assert(crmTenantSlugFromPath("/person-field-update") === "", "TC1 failed: mutation segment is not a slug");

// TC2: body dataset wins for installed PWA edge cases
sandbox.window.location.pathname = "/";
assert(crmCurrentTenantSlug() === "pushkino", "TC2 failed: body dataset slug");

// TC3: tenant API prefix
sandbox.window.location.pathname = "/pushkino/person?id=12";
assert(
  crmTenantApiPath("/person-field-update") === "/pushkino/person-field-update",
  "TC3 failed: person-field-update must be tenant-prefixed"
);
assert(
  crmTenantApiPath("/knowledge-image-upload") === "/pushkino/knowledge-image-upload",
  "TC3 failed: knowledge upload must be tenant-prefixed"
);
assert(
  crmTenantApiPath("/pushkino/person-field-update") === "/pushkino/person-field-update",
  "TC3 failed: already-prefixed path must stay unchanged"
);

// TC4: without tenant context keep root path (platform / legacy redirect still available)
sandbox.document.body.dataset.churchSlug = "";
sandbox.window.location.pathname = "/admin";
assert(crmCurrentTenantSlug() === "", "TC4 failed: no slug on platform");
assert(crmTenantApiPath("/person-field-update") === "/person-field-update", "TC4 failed: root fallback");

console.log("crm-tenant-api-path-verify: ok");
// Keep import for future ESM tooling that expects the path helper module shape.
void pathToFileURL;
