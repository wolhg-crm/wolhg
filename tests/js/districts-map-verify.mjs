import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../..");
const appJs = fs.readFileSync(path.join(root, "public/assets/app.js"), "utf8");

function assert(condition, message) {
  if (!condition) {
    console.error("FAIL:", message);
    process.exit(1);
  }
}

assert(appJs.includes("function initDistrictsMap"), "initDistrictsMap missing");
assert(appJs.includes("[data-districts-map]"), "districts map selector missing");
assert(appJs.includes("geojsonToYmapsPolygons"), "geojsonToYmapsPolygons missing");
assert(appJs.includes("[data-district-draw-start]"), "draw start hook missing");
assert(appJs.includes("initDistrictsMap();"), "initDistrictsMap not called");

console.log("districts-map-verify: ok");
