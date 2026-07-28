# Prebid Setup and Operations

## Purpose

Horus Media owns the complete browser bidding and Google Ad Manager setup
workflow. Publishers install the permanent Horus loader and do not create GAM
orders, line items, creatives, or targeting manually.

This release implements browser-side Prebid.js only. It does not deploy Prebid
Server or send auction and impression events to Laravel.

## 1. Build the approved browser bundle

The build profile is stored at:

```text
prebid-builds/horus-default.json
```

It pins:

- the official Prebid.js repository
- release `11.14.0`
- the exact adapter and feature module list
- output paths for the readable bundle, minified bundle, and checksum manifest

Build from a development machine or CI runner:

```bash
npm ci
npm run build:prebid
```

The script clones the pinned release into a temporary directory, installs that
release's exact dependencies, runs the official Gulp build, writes the static
assets below, and removes the temporary source directory:

```text
public/assets/prebid/horus-prebid.js
public/assets/prebid/horus-prebid.min.js
public/assets/prebid/manifest.json
```

Do not run this command on Hostinger production. Upload the compiled release
artifact instead.

## 2. Publish Prebid assets to the CDN

Deploy the `public/assets/prebid/` directory to the equivalent path under
`cdn.horusmedia.net`. Recommended cache policy:

```text
Versioned Prebid bundle:
Cache-Control: public, max-age=31536000, immutable

Manifest:
Cache-Control: public, max-age=300, must-revalidate
```

A new bundle must receive a new Horus build version. Never replace a versioned
bundle with different bytes while retaining its version or checksum.

## 3. Review the bidder registry

Open:

```text
Admin → Prebid
```

The initial registry includes:

- AppNexus / Xandr
- Index Exchange
- OpenX
- PubMatic
- Magnite / Rubicon

Each registry record defines only browser-public parameters, supported media
types, common sizes, module name, documentation, and enabled status.

A bidder whose adapter module is absent from the selected build is omitted from
static configuration. This prevents a configuration from referencing code that
the browser bundle does not contain.

## 4. Add a bidder account

Create one account for each approved demand relationship:

1. Select the owner organization.
2. Select the bidder.
3. Enter an internal account name.
4. Enter any public publisher/account identifier.
5. Add only public adapter parameters in JSON.
6. Keep the account enabled after approval.

Never enter:

- passwords
- access tokens
- refresh tokens
- client secrets
- private keys
- API credentials
- authorization headers

The validator rejects secret-like fields because the resulting bidder
configuration is intentionally published to browser JavaScript.

## 5. Configure a website

Open:

```text
Admin → Prebid → Website → Configure
```

Configure:

- compiled build
- auction timeout
- price granularity
- currency
- random or fixed bidder sequence
- consent-management settings
- user-sync settings
- coordination with lazy loading
- refresh behavior
- bidder-timeout diagnostics
- GAM fallback
- debug mode

Then assign bidder accounts to the website. Add placement overrides only when a
bidder requires a different zone, unit, ad slot, site, or placement identifier
for a specific Horus placement.

Final public parameters are merged from bidder, account, website, and placement
layers. The most specific layer wins.

## 6. Publish the website configuration

After every bidder or auction-setting change, publish the required environment:

```text
PREVIEW
TEST
PRODUCTION
```

The generated static JSON contains the selected build URL and public bidder
configuration. It does not contain credentials or private commercial data.

Disabling a bidder account removes it from the next published configuration.
The publisher's loader snippet remains unchanged.

## 7. Prepare the selected GAM network

Every enabled GAM connection has an independent Prebid setup template. For the
primary Horus network, the setup is centralized in `HORUS_GAM`. Optional MCM or
publisher networks receive their own setup only when websites are routed to
them.

Before planning setup, configure the GAM connection with:

```json
{
  "root_ad_unit_id": "GAM_ROOT_AD_UNIT_ID",
  "trafficker_id": "GAM_TRAFFICKER_USER_ID",
  "currency": "USD"
}
```

Synchronize all local ad units required by Prebid to that same GAM connection.
The planner refuses to mark setup complete while required ad-unit mappings are
missing.

## 8. Configure price buckets

The default dense profile creates price points with two-decimal precision.
Custom site buckets can define:

- minimum
- maximum
- increment
- precision
- priority

The GAM value must retain the same precision produced by Prebid. For example,
use `1.00`, not `1` or `1.0`, when precision is two.

Review the preview object count before executing. Finer increments produce more
line items and targeting values.

## 9. Create a dry-run preview

From the Prebid dashboard:

1. Select a GAM connection.
2. Optionally select one website routed to that connection.
3. Click **Create dry-run preview**.
4. Review missing prerequisites and estimated object counts.

The preview plans:

- one Prebid advertiser
- targeting keys
- `hb_pb` targeting values
- one order
- one line item per price point
- one universal creative per size
- line-item creative associations

Creating a preview performs no external GAM write.

## 10. Confirm and execute

A complete preview generates a one-time confirmation code. Enter it to authorize
external writes. The code is stored only as a SHA-256 hash and is removed after
confirmation.

Execution occurs in administrator-selected batches. Each successful object is
mapped by a deterministic key and payload hash. On interruption:

1. Open the same setup run.
2. Click **Resume setup batch**.
3. Processing continues from the saved cursor.

Re-executing a completed run or creating a new preview with unchanged payloads
reuses existing objects instead of creating duplicates.

## 11. Browser delivery sequence

For an enabled placement:

```text
Horus Loader
→ load GPT once
→ define GPT slot and disable its initial request
→ load selected Prebid build once
→ create Prebid ad unit
→ request bids with strict timeout
→ apply Prebid GPT targeting
→ request the GAM slot
```

When Prebid throws, fails to load, has no eligible bidders, or exceeds the
safety timeout, the loader requests GAM directly when fallback is enabled.
Publisher page execution continues even when both advertising libraries fail.

## 12. Testing

Run backend and browser tests:

```bash
php artisan test
npm ci
npm run test:browser
npm run build
```

Validate the full custom bundle separately:

```bash
npm run build:prebid
```

Release CI verifies PHP 8.2, 8.3, and 8.4, MySQL migrations, browser auction
ordering, timeout/failure fallback, frontend audit, asset compilation, and
Prebid manifest output.

## 13. Operational checks

Before production activation confirm:

- the site's resolved GAM network is correct
- every required ad unit is synchronized to that network
- the Prebid build contains every enabled adapter
- bidder parameters are public and approved
- consent settings match the publisher's CMP deployment
- the GAM preview is complete
- the administrator confirms the exact preview
- the production JSON references the expected build and setup key
- GPT Console shows the expected `hb_` targeting
- GAM fallback works with Prebid blocked in the browser
