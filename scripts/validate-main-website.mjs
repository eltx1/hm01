import { existsSync, lstatSync, readFileSync, readdirSync } from "node:fs";
import { extname, join, relative, resolve } from "node:path";

const root = resolve(process.argv[2] || "website");
const failures = [];
const required = [
  "index.html",
  "terms/index.html",
  "privacy/index.html",
  "publisher-terms/index.html",
  "assets/css/style.css",
  "assets/js/main.js",
  "assets/images/horusmedia-emblem-header.png",
  "assets/images/horusmedia-emblem-hero.png",
  "favicon.png",
  "site.webmanifest",
  "sitemap.xml",
  "_headers",
];

for (const path of required) {
  if (!existsSync(join(root, path))) failures.push(`Missing required file: ${path}`);
}

function walk(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    if (lstatSync(path).isSymbolicLink()) {
      failures.push(`Symlinks are not allowed: ${relative(root, path)}`);
      return [];
    }
    return entry.isDirectory() ? walk(path) : [path];
  });
}

const files = existsSync(root) ? walk(root) : [];
const textExtensions = new Set([".html", ".css", ".js", ".json", ".txt", ""]);
const texts = files
  .filter((path) => textExtensions.has(extname(path)))
  .map((path) => ({ path, content: readFileSync(path, "utf8") }));

const forbidden = [
  [/__CF\$cv\$params/, "Cloudflare challenge injection"],
  [/BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY/, "private key"],
  [/AKIA[0-9A-Z]{16}/, "AWS access key"],
  [/(?:CLOUDFLARE_API_TOKEN|CLOUDFLARE_ACCOUNT_ID)\s*[:=]\s*[^$\s{][^\s]*/i, "Cloudflare credential"],
];

for (const { path, content } of texts) {
  for (const [pattern, label] of forbidden) {
    if (pattern.test(content)) failures.push(`${label} found in ${relative(root, path)}`);
  }
}

const htmlFiles = texts.filter(({ path }) => extname(path) === ".html");
for (const { path, content } of htmlFiles) {
  const name = relative(root, path);
  if (!/<meta name="viewport"/.test(content)) failures.push(`${name} is missing a viewport declaration`);
  if (!/<link rel="canonical" href="https:\/\/horusmedia\.net\//.test(content)) failures.push(`${name} is missing its production canonical URL`);
  if (!/<link rel="stylesheet" href="\/assets\/css\/style\.css">/.test(content)) failures.push(`${name} is missing the shared stylesheet`);
  if (!/<main\b[^>]*id="main-content"/.test(content)) failures.push(`${name} is missing the accessible main landmark`);

  for (const match of content.matchAll(/(?:href|src)="(\/[^"]*)"/g)) {
    const target = match[1].split(/[?#]/, 1)[0];
    if (target === "/") continue;
    const candidate = target.endsWith("/") ? join(root, target, "index.html") : join(root, target);
    if (!existsSync(candidate)) failures.push(`${name} references missing local path: ${target}`);
  }
}

const homepage = readFileSync(join(root, "index.html"), "utf8");
for (const value of [
  "https://app.horusmedia.net/register/publisher",
  "/terms/",
  "/privacy/",
  "/publisher-terms/",
  "mohamed@horusmedia.net",
  "Horus Media LLC",
  "HORUS MEDIA GROUP LTD",
  "30 N Gould St Ste N",
  "2 Frederick Street, Kings Cross",
]) {
  if (!homepage.includes(value)) failures.push(`Homepage is missing required content: ${value}`);
}

for (const [path, canonical] of [
  ["terms/index.html", "https://horusmedia.net/terms/"],
  ["privacy/index.html", "https://horusmedia.net/privacy/"],
  ["publisher-terms/index.html", "https://horusmedia.net/publisher-terms/"],
]) {
  const content = readFileSync(join(root, path), "utf8");
  if (!content.includes(`Version: 2026-08-22`)) failures.push(`${path} is missing the configured legal version`);
  if (!content.includes(`href="${canonical}"`)) failures.push(`${path} canonical URL is incorrect`);
}

if (failures.length > 0) {
  console.error(failures.map((failure) => `- ${failure}`).join("\n"));
  process.exit(1);
}

console.log(`Validated ${files.length} files for plain-truth-6412.`);
