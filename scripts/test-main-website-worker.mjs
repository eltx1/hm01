import assert from "node:assert/strict";

import websiteWorker from "../website-worker.mjs";

const assets = {
  async fetch(request) {
    return new Response(`asset:${new URL(request.url).pathname}`, { status: 200 });
  },
};

for (const [path, target] of [
  ["/sellers.json", "https://cdn.horusmedia.net/sellers.json"],
  ["/supply/sellers.json", "https://cdn.horusmedia.net/supply/sellers.json"],
]) {
  const response = await websiteWorker.fetch(new Request(`https://horusmedia.net${path}`), { ASSETS: assets });

  assert.equal(response.status, 302);
  assert.equal(response.headers.get("location"), target);
  assert.equal(response.headers.get("cache-control"), "public, max-age=300");
}

const assetResponse = await websiteWorker.fetch(new Request("https://horusmedia.net/privacy/"), { ASSETS: assets });
assert.equal(assetResponse.status, 200);
assert.equal(await assetResponse.text(), "asset:/privacy/");

console.log("Validated main website Worker routing.");
