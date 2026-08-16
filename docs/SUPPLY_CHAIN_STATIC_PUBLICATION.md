# Supply-chain static publication

Horus treats supply-chain artifacts as first-class static-delivery state. Seller, ads.txt, mapping, owner identity, and manager-role changes enqueue a coalescing `SUPPLY_CHAIN` global artifact change. Normal changes become eligible at the existing next 30-minute UTC boundary; safety revocations are urgent and immediately eligible. The change enters the same deterministic snapshot, deployment-budget, GitHub/Cloudflare Pages driver, retry, deduplication, and audit path as Site configuration delivery without creating a `ConfigVersion`.

`SupplyChainArtifactBuilder` remains the single generator for `/sellers.json`, `/supply/sellers.json`, and `/supply/domains/{site-domain}/ads.txt`. Static headers keep ads.txt as `text/plain; charset=utf-8` and sellers.json as `application/json; charset=utf-8` with bounded cache lifetimes.

## Publisher ads.txt deployment

`MANUAL_COPY` means the publisher maintains `/ads.txt` from the canonical Horus output manually.

`MANAGED_REDIRECT_DELEGATION` means the publisher installs one HTTP 3xx redirect from `https://{site-domain}/ads.txt` directly to the Horus managed endpoint under `SUPPLY_CHAIN_MANAGED_ADS_TXT_BASE_URL`. Horus verifies that this is exactly one external delegation hop, that the managed target does not redirect again, returns `text/plain`, and matches the generated canonical payload. This deliberately uses a strict safe subset of the ads.txt redirect rules: it never relies on a second cross-root redirect.

## Canonical sellers.json origin

The advertising-system identity remains `horusmedia.net`; therefore production readiness requires the current generated payload at `https://horusmedia.net/sellers.json`. A CDN copy by itself is not sufficient. The supported architecture is either a direct 200 response at the canonical URL or one explicitly configured same-root proxy/redirect to `SUPPLY_CHAIN_SELLERS_JSON_PROXY_TARGET` (default `https://cdn.horusmedia.net/sellers.json`). The verifier rejects any additional redirect, wrong content type, non-2xx response, or payload mismatch and persists evidence. `SupplyChainProductionReadinessService` returns the blocking code `HORUS_SELLERS_JSON_PUBLIC_ORIGIN_UNVERIFIED` until the current payload is verified.

Neither ads.txt nor sellers.json generators include credentials, API keys, or other private integration data.
