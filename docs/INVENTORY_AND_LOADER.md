# Inventory, Static Configuration, GPT, and Horus Loader

## Delivery boundary

Horus Media is a control plane. Laravel stores inventory definitions, performs
approved Google Ad Manager API synchronization, and publishes public static
configuration files. It never proxies browser ad requests and it receives no
request per impression.

Runtime flow:

```text
Publisher page
  -> https://cdn.horusmedia.net/hm-loader.js
  -> https://cdn.horusmedia.net/configs/_global/control.json
  -> https://cdn.horusmedia.net/configs/{SITE_KEY}/manifest.json
  -> https://cdn.horusmedia.net/configs/{SITE_KEY}/production.vN.HASH.json
  -> Google Publisher Tag
  -> selected Google Ad Manager network
```

The static JSON contains only public delivery settings. Credentials, private
keys, OAuth tokens, contractual data, revenue shares, internal identifiers, and
private notes are excluded.

## Permanent installation

Install one script once:

```html
<script async
        src="https://cdn.horusmedia.net/hm-loader.js"
        data-site-key="PUBLIC_SITE_KEY"></script>
```

Add a container wherever an advertisement may appear:

```html
<div class="hm-ad"
     data-placement="PLACEMENT_CODE"></div>
```

The installation code contains no GAM network code. Changing from `HORUS_GAM`
to an explicitly selected MCM partner or publisher GAM connection republishes
the static configuration while the publisher code remains unchanged.

Optional development attributes:

```html
<script async
        src="https://cdn.horusmedia.net/hm-loader.js"
        data-site-key="PUBLIC_SITE_KEY"
        data-environment="test"
        data-config-base="https://cdn.horusmedia.net/configs"
        data-config-version="12"></script>
```

Production publishers normally need only `data-site-key`.

## Inventory model

### Ad units

`ad_units` are local Horus Media inventory records assigned to one website.
They have stable lowercase codes and one or more `ad_unit_sizes`. Local ad units
may be enabled or disabled independently of placements.

Synchronization uses the GAM connection selected by the website's serving mode.
The selected connection must define this non-secret configuration value:

```json
{
  "root_ad_unit_id": "GAM_ROOT_AD_UNIT_ID"
}
```

A synchronization dry-run performs no Google write. Successful synchronization
stores the GAM object ID and payload hash in the existing `gam_remote_objects`
table. A matching hash is treated as already synchronized. A changed local hash
is reported as a remote difference and can be deliberately resynchronized.

### Placements

Supported placement types:

- `DISPLAY`
- `NATIVE`
- `VIDEO`
- `STICKY`
- `INTERSTITIAL`
- `REWARDED`
- `CUSTOM`

A placement owns the browser-facing code and delivery behavior:

- active, paused, or disabled status
- fixed and fluid sizes
- viewport and device-specific responsive mappings
- lazy loading margins and mobile scaling
- optional refresh interval and limit
- empty-div collapse
- SafeFrame enforcement
- placement targeting
- sort order and non-secret metadata

Page-level targeting is stored in `placement_targeting` with a null
`placement_id`. Placement-level targeting uses the same table with a placement
ID. Keys accept letters, numbers, and underscores; values are serialized as
public strings.

## Static configuration

Configurations are generated for three independent environments:

- `PREVIEW`
- `TEST`
- `PRODUCTION`

Publishing records a pending version and transactional outbox item. The
scheduler batches changes into one complete Cloudflare Pages snapshot containing
an immutable version, a short-lived manifest, and a compatibility alias:

```text
configs/{SITE_KEY}/manifest.json
configs/{SITE_KEY}/production.v5.SHA.json
configs/{SITE_KEY}/production.json
```

Every publication stores `config_versions` and `static_delivery_items` in one
database transaction with the public payload and SHA-256 checksum. No filesystem
or network write occurs in the admin request. `static_delivery_batches` records
batching, upload, retry, remote workflow evidence, and confirmed deployment.

Production publication follows the website lifecycle automatically:

- Draft, review, and approval changes remain control-plane-only and create no
  production outbox traffic.
- First activation queues the complete initial production configuration.
- Runtime changes for an active website queue a new production version. This
  includes domains, loader/GPT settings, placements, targeting, serving mode,
  GAM resolution, Prebid, native demand, and operational controls.
- Bulk placement changes create one production version after the transaction,
  not one version per row.
- Suspension, archive from active state, and emergency pause queue an urgent
  paused version. Emergency pause creates exactly one urgent version.
- Manual Preview/Test/Production and rollback remain available as explicit
  operations. A non-active website can never generate an active payload.

The active-state check and outbox write are serialized under a database lock.
This prevents a concurrent suspension and settings update from publishing an
incorrectly active production payload.

A rollback never mutates old evidence. It copies the selected payload into a
new version, records `source_version_id`, and replaces the current alias. This
makes rollback history explicit and reversible.

Changing a selected GAM connection or serving mode publishes a new production
configuration for active affected websites. Changing the primary fallback GAM
connection republishes all active websites that resolve through that fallback;
explicitly assigned unrelated websites are not changed.

## Public configuration shape

Example:

```json
{
  "siteKey": "HM_SITE_001",
  "servingMode": "HORUS_GAM",
  "gamNetworkCode": "123456789",
  "configVersion": 5,
  "environment": "PRODUCTION",
  "status": "active",
  "immediatePause": false,
  "prebidEnabled": false,
  "debug": false,
  "houseAdTesting": false,
  "clickGuard": {
    "enabled": false,
    "maxClicks": 3,
    "windowHours": 6,
    "blockHours": 12
  },
  "allowedHostnames": ["publisher.example"],
  "loader": {
    "version": "1.0.0",
    "assetUrl": "https://cdn.horusmedia.net/assets/hm-loader.min.js",
    "cacheBust": 5
  },
  "gpt": {
    "url": "https://securepubads.g.doubleclick.net/tag/js/gpt.js",
    "tagVersion": "1.0.0",
    "singleRequest": true
  },
  "pageTargeting": {},
  "placements": []
}
```

## Horus Loader behavior

`public/assets/hm-loader.js`:

1. reads the public site key from its script element
2. derives or reads the static configuration base URL
3. detects the current hostname
4. fetches global control, then a manifest and immutable JSON file
5. falls back to the current alias during manifest/deployment propagation
6. rejects unauthorized hostnames before loading GPT
7. stops immediately when the site is paused
8. loads GPT once for the page
9. defines only active, enabled placements with valid ad-unit paths
10. builds responsive GPT size mappings and applies targeting
11. displays and refreshes slots directly through GPT/Prebid
12. supports SPA rescans without Horus telemetry
13. catches failures so the publisher page continues normally

The loader does not call Laravel. All Horus requests use the loader's own static
origin with `credentials: omit`. It never calls `app.horusmedia.net` or sends
per-page, impression, bid, refresh, or click telemetry to Horus.

`public/assets/hm-loader.min.js` is generated from the readable source by:

```bash
npm run build:loader
```

The normal production build runs that command automatically.

## Horus Click Guard

Horus Click Guard is an optional browser-local protection in the Horus Loader.
It is disabled by default. Delivery Settings publishes only these public values:

```json
{
  "clickGuard": {
    "enabled": true,
    "maxClicks": 3,
    "windowHours": 6,
    "blockHours": 12
  }
}
```

When enabled, the loader stores a minimal versioned record under
`hm:click-guard:v1:{SITE_KEY}` in the publisher origin's `localStorage`. The
record contains only click timestamps in Unix epoch milliseconds and a
`blockedUntil` timestamp. It contains no URL, creative data, user identifier,
IP address, fingerprint, page history, or Horus account/database identifier,
and it is never transmitted to Laravel.

At every relevant read/write, timestamps outside the rolling `windowHours`
window are pruned. Reaching `maxClicks` creates `blockedUntil` for
`blockHours`, clears the prior click window, cancels Horus refresh timers, and
prevents future Horus-managed GPT, Prebid, native-demand, refresh, and SPA scan
requests. Advertising already rendered before the threshold is not destroyed.
A browser that loads the page while a valid block is active stops after static
configuration/privacy/host gates and before advertising libraries initialize.
When the block expires the state is reset to a fresh click window and normal
eligibility resumes.

Probable iframe interaction is detected without reading or modifying cross-origin
iframe contents. Click Guard tracks only iframes inside active `.hm-ad` and
`.hm-native` placement containers, arms on pointer entry (with mouse fallback),
and records one probable click when the top window subsequently loses focus.
The existing SPA `MutationObserver` is reused to register dynamically inserted
or replaced ad iframes and remove listeners from removed frames; unrelated page
iframes are ignored. A small internal debounce and re-entry requirement prevent
one interaction from being counted repeatedly.

Same-origin tabs synchronize blocks through the browser's native `storage`
event. No WebSocket or backend synchronization is used. Corrupt storage is
normalized safely. If `localStorage` is unavailable or throws a security/policy
exception, Click Guard fails open and normal advertising continues.

Cross-origin iframe click detection is necessarily heuristic and
browser-dependent. Desktop pointer/focus behavior is the most reliable case;
mobile/touch interaction is inherently less reliable. Click Guard does not claim
to detect every advertising click or to be complete click-fraud detection.

## Pausing

There are two levels:

- Site immediate pause publishes `status: paused` and `immediatePause: true`.
  The loader does not load GPT or make ad requests.
- Placement pause or disable publishes `enabled: false`. The loader ignores that
  placement while other active placements continue.

Emergency pause in website operations also publishes a paused production file.
This keeps the operational pause independent of a Laravel request at impression
time.

## House-ad test mode

House-ad testing adds this targeting at page and placement level:

```text
hm_house_test=1
```

A house line item can target that key inside the selected GAM network. This
allows end-to-end slot verification without opening production demand broadly.

## Layout duplication and bulk creation

Bulk placement input uses one line per placement:

```text
code|name|type|ad_unit_code|300x250,336x280
```

Layout duplication copies local ad units, sizes, placements, delivery settings,
and targeting to another website. It intentionally does not copy GAM remote IDs;
the target website synchronizes into its own selected GAM connection.

## Test publisher

A self-contained publisher fixture is stored at:

```text
tests/fixtures/publisher-site/index.html
```

Its matching static configuration is under the fixture's `configs` directory.
Browser runtime tests use a controlled DOM/GPT harness and verify GPT singleton
loading, pause behavior, disabled placements, hostname rejection, and static
configuration cache busting.

## CDN and cache-control recommendations

Recommended headers:

| Resource | Cache-Control |
|---|---|
| `hm-loader.js` | `public, max-age=300, stale-while-revalidate=86400` |
| versioned loader asset | `public, max-age=31536000, immutable` |
| `production.vN.HASH.json` | `public, max-age=31536000, immutable` |
| `manifest.json` | `public, max-age=15, must-revalidate, stale-while-revalidate=60` |
| `production.json` | `public, max-age=30, must-revalidate, stale-while-revalidate=60` |
| `test.json` and `preview.json` | `no-cache` or a very short public TTL |

Static JSON and loader files should include:

```text
Access-Control-Allow-Origin: *
Content-Type: application/json; charset=utf-8
```

Use JavaScript content type for loader assets. Do not attach cookies, private
headers, or credentials to CDN delivery.

## Deployment

Production uses the pipeline variables in `.env.production.example`, including:

```dotenv
HORUS_CDN_URL=https://cdn.horusmedia.net
HORUS_LOADER_URL=https://cdn.horusmedia.net/hm-loader.js
HORUS_STATIC_DELIVERY_DRIVER=cloudflare-pages-pipeline
HORUS_EDGE_GITHUB_BRANCH=edge-delivery
HORUS_EDGE_GITHUB_TOKEN_REFERENCE=env:HORUS_EDGE_GITHUB_TOKEN
```

Hostinger requires no Node.js and no writable CDN document root. Cron processes
the database outbox; GitHub Actions runs Wrangler with Cloudflare secrets. No
permanent worker, Redis, WebSocket, Pages Function, or impression endpoint is
required. See `CLOUDFLARE_SETUP.md` and ADR 0001.
