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
  -> https://cdn.horusmedia.net/configs/{SITE_KEY}/production.json
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

Publishing creates both an immutable versioned file and a current alias:

```text
configs/{SITE_KEY}/production.v5.json
configs/{SITE_KEY}/production.json
```

Every publication stores a database record in `config_versions` with the JSON
payload, SHA-256 checksum, file path, environment, version, actor, and timestamp.
Files are written atomically to prevent a browser from receiving a partially
written JSON document.

A rollback never mutates old evidence. It copies the selected payload into a
new version, records `source_version_id`, and replaces the current alias. This
makes rollback history explicit and reversible.

Changing the selected GAM connection or serving mode publishes a new production
configuration. Changing one website does not change another website.

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
4. fetches one static JSON file
5. rejects unauthorized hostnames before loading GPT
6. stops immediately when the site is paused
7. loads GPT once for the page
8. defines only active, enabled placements with valid ad-unit paths
9. builds responsive GPT size mappings
10. applies page and placement targeting
11. configures lazy loading, single-request mode, SafeFrame, and empty collapse
12. displays slots directly through GPT
13. schedules explicitly configured refreshes while the page is visible
14. observes added placement elements for practical SPA support
15. exposes diagnostics only when debug mode is enabled
16. catches failures so the publisher page continues normally

The loader does not call Laravel. Its only Horus request is the cacheable static
configuration file, normally once per page load.

`public/assets/hm-loader.min.js` is generated from the readable source by:

```bash
npm run build:loader
```

The normal production build runs that command automatically.

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
| `production.vN.json` | `public, max-age=31536000, immutable` |
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

Production environment variables:

```dotenv
HORUS_CDN_URL=https://cdn.horusmedia.net
HORUS_STATIC_CONFIG_ROOT=/home/ACCOUNT/domains/cdn.horusmedia.net/public_html/configs
HORUS_STATIC_CONFIG_PUBLIC_PATH=configs
HORUS_LOADER_URL=https://cdn.horusmedia.net/hm-loader.js
HORUS_GPT_URL=https://securepubads.g.doubleclick.net/tag/js/gpt.js
HORUS_CONFIG_CACHE_TTL=60
```

The PHP account must be able to create directories and atomically rename files
inside `HORUS_STATIC_CONFIG_ROOT`. No Node.js runtime, worker daemon, Redis,
WebSocket service, or impression endpoint is required in production.
