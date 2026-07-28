# Prebid Architecture

## Implemented browser ownership

Prebid.js executes entirely in the visitor's browser. The permanent Horus
loader retrieves a versioned JSON snapshot from `cdn.horusmedia.net`, loads the
selected compiled Prebid build, creates eligible ad units, runs a bounded
auction, applies Prebid targeting to GPT, and requests the GAM network selected
for that website.

Laravel is the control plane and never sits in the auction, bid request,
impression, or ad-rendering path. The browser talks directly to Prebid bidders,
Google Publisher Tag, and Google Ad Manager.

## Fixed network behavior

`HORUS_GAM` remains the default ad server. The same `GamConnectionResolver` used
by inventory determines which GAM network receives Prebid demand:

1. A `HORUS_GAM` site uses the primary Horus GAM connection and its centralized
   Prebid advertiser, targeting, order, line items, and universal creatives.
2. A site explicitly switched to `MCM_PARTNER_GAM` uses that connection's
   independent Prebid GAM template and remote-object mappings.
3. A site explicitly switched to `PUBLISHER_GAM` uses the publisher connection's
   independent setup.
4. A `PAUSED` site does not load GPT or Prebid.

The publisher installation code contains only the Horus loader URL and public
site key. Switching GAM networks, builds, bidders, timeouts, or price buckets
does not change publisher code.

## Browser sequence

1. Publisher page loads `hm-loader.js` once.
2. Loader validates the current hostname against static configuration.
3. Paused sites and disabled placements stop before loading advertising code.
4. GPT loads once and slots are defined without an initial ad request when
   Prebid is enabled.
5. The selected compiled Prebid build loads once.
6. Public bidder parameters are read from the static configuration.
7. `pbjs.requestBids` starts a placement-scoped auction with a strict timeout.
8. `pbjs.setTargetingForGPTAsync(adUnitCodes)` writes the winning bid targeting
   to the matching GPT slots.
9. GPT refreshes only those slots.
10. When Prebid is unavailable, throws, or exceeds its safety timeout, the
    loader requests the same slots from GAM without Prebid when fallback is
    enabled.

Refreshes run a new auction before the corresponding GPT refresh. SPA pages are
rescanned for newly inserted Horus placements. Bidder timeout diagnostics stay
inside the browser and are not posted to Laravel.

## Static configuration shape

Every published website configuration may contain:

```json
{
  "prebidEnabled": true,
  "prebid": {
    "enabled": true,
    "build": {
      "version": "horus-11.14.0-1",
      "prebidVersion": "11.14.0",
      "assetUrl": "https://cdn.horusmedia.net/assets/prebid/horus-prebid.min.js"
    },
    "auctionTimeoutMs": 1200,
    "priceGranularity": "dense",
    "currency": "USD",
    "bidderSequence": "random",
    "consentManagement": {},
    "userSync": {},
    "gamFallbackEnabled": true,
    "gamSetup": {
      "key": "connection-specific-hash",
      "version": 1,
      "mode": "TOP_PRICE",
      "complete": true
    },
    "adUnits": {
      "article_top": {
        "mediaTypes": { "banner": { "sizes": [[300, 250]] } },
        "bids": [
          { "bidder": "appnexus", "params": { "placementId": "12345" } }
        ]
      }
    }
  }
}
```

Only browser-public bidder identifiers and parameters are allowed. The parameter
validator rejects secret-like keys, private keys, credentials, access tokens,
refresh tokens, client secrets, passwords, and non-scalar values.

## Bidder registry and assignment

The registry separates four layers:

- `prebid_adapters`: adapter module, public schema, media types, sizes, and
  documentation.
- `prebid_bidders`: logical bidder or alias enabled by Horus Media.
- `bidder_accounts`: Horus- or publisher-owned public account identifiers.
- site and placement mappings: overrides and enable/disable state.

Final browser parameters are merged in this order:

1. bidder defaults
2. bidder account parameters
3. website parameters
4. placement parameters
5. normalized public publisher/placement identifiers

Disabling a bidder account removes it from the next published static
configuration. No publisher code edit is required.

## Reproducible custom builds

The release pins the Prebid source repository, release reference, module list,
and output manifest. `scripts/build-prebid.mjs` runs only in a development or CI
build environment:

```text
git clone --depth 1 --branch <pinned-version> prebid/Prebid.js
npm ci
npx --no-install gulp build --modules=<approved-modules>
```

The resulting JavaScript and checksum manifest are copied to
`public/assets/prebid/`. Production hosting receives static assets only and does
not require Node.js, Gulp, the Prebid source tree, or a permanent process.

## GAM automation

Each GAM connection has a separate `prebid_gam_template`. The automation plans
and reconciles:

- one generic Prebid advertiser company
- required custom targeting keys
- `hb_pb` price values
- an order
- price-priority line items
- universal creatives per active size
- line-item creative associations

The planner uses local synchronized ad-unit mappings from the selected GAM
connection. It reports missing prerequisites and exact estimated object counts
before any write.

Execution requires:

1. a dry-run preview
2. a one-time administrator confirmation code
3. a saved setup run
4. idempotent, resumable batches

Every object has a deterministic key and payload hash. Completed objects are
reused, interrupted runs resume from a saved cursor, and rerunning a completed
setup creates no duplicates.

## Price buckets

Horus Media can use predefined Prebid granularity or custom ordered buckets.
Custom price points retain the configured precision, including values such as
`1.00`, so GAM targeting matches the browser's `hb_pb` value exactly. The
planner caps generated price values to prevent an accidental unbounded object
plan.

## Reliability

- GPT and Prebid each load once.
- Initial GPT requests are disabled only when a valid Prebid configuration is
  active.
- Auctions have a configured timeout and an additional safety timeout.
- Exceptions and timeouts fall back to GAM without breaking the publisher page.
- Disabled bidders and placements are omitted before the auction.
- Paused and unauthorized sites make no advertising calls.
- Loader, Prebid bundle, and configuration are CDN-cacheable and versioned.
- Static configuration rollback also rolls back bidder and build selection.

## Privacy and data minimization

The platform stores configuration, setup state, and aggregated reporting only.
It does not receive browser auctions, bid requests, bid responses, user IDs,
pageviews, or impressions. Consent and user-sync configuration are public
browser settings. No credentials or private account material are included in
CDN artifacts.

## Explicit exclusions

This release does not implement Prebid Server, OpenRTB endpoints, server-side
bidding, raw bidstream storage, impression callbacks to Laravel, AMP bidding,
mobile SDK bidding, instream video line-item automation, or bidder analytics
adapters.
