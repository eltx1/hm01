# Standalone Prebid Runtime

This document is the runtime contract for Horus Media Prebid delivery when `deliveryMode=STANDALONE`.
It complements `MULTI_ENGINE_SERVING.md`, `PREBID_ARCHITECTURE.md`, and `PREBID_OPERATIONS.md`.

## Runtime profile ownership

`prebid_settings` is the single generic runtime-settings store. Every row has exactly one owner:

- `GAM_CONNECTION`: the existing connection-owned profile used by `GAM_BRIDGE`.
- `SITE_STANDALONE`: a site-owned profile used by `STANDALONE`.

The migration deterministically labels all pre-existing settings `GAM_CONNECTION`. Existing GAM price buckets, setup runs, templates, and remote-object mappings are not rewritten or deleted. Bidder adapters, bidder accounts, site mappings, and placement mappings remain shared normalized resources; there is no standalone copy of that graph.

Horus-managed bidder accounts may continue to be mapped to publisher sites. Tenant isolation is enforced at site and placement mapping boundaries and by the existing authorization/control plane, rather than by incorrectly requiring the bidder account owner and publisher site owner to be the same organization.

## Standalone configuration

A standalone configuration is built without a `GamConnection`. It contains only browser-safe runtime material:

- pinned Prebid build version, URL, and checksum;
- bounded auction timeout;
- bidder sequence and currency;
- consent configuration and activity controls;
- ORTB2 and supply-chain data already approved for the public config;
- public bidder parameters and placement mappings;
- ad units;
- lazy-loading and refresh policy;
- direct-render security policy.

GAM line-item buckets, GAM remote IDs, GPT slot paths, and GAM targeting are not standalone dependencies. `gamFallback` is always false in standalone mode.

The legacy schema-v2 `prebidEnabled` field remains GAM-bridge-only. Schema v3 uses `prebid.enabled`, `prebid.deliveryMode`, and placement renderer ownership to activate standalone behavior without changing old Loader semantics.

## Browser delivery flow

For a placement owned by `PREBID_STANDALONE`, the permanent Horus Loader performs this bounded sequence:

1. Confirm site, placement, operational controls, privacy state, and Click Guard permit a request.
2. Respect lazy-loading visibility when configured.
3. Load the pinned Horus Prebid build once.
4. Register only the selected placement ad unit.
5. Call `pbjs.requestBids` with a bounded timeout and the single ad-unit code.
6. Read the winning banner through `pbjs.getHighestCpmBids`.
7. Reject a winner unless its `adId` belongs to the callback bids for that auction, its auction ID is compatible, and its TTL/timestamp are still valid.
8. Create an isolated iframe and call `pbjs.renderAd(iframe.contentWindow.document, adId)`.
9. Mark the placement rendered. No GPT call or GAM fallback follows.

No synthetic CPM is generated. A no-bid, timeout, bidder exception, script failure, invalid response, stale bid, expired bid, or render exception fails closed.

## Render security and supported formats

Task 15 standalone rendering supports **banner only**.

The direct-render iframe uses the constrained Prebid no-ad-server sandbox capabilities required for common banner creatives:

- `allow-forms`
- `allow-popups`
- `allow-popups-to-escape-sandbox`
- `allow-same-origin`
- `allow-scripts`
- `allow-top-navigation-by-user-activation`

Unrestricted `allow-top-navigation` is not granted. Prebid is configured with top-window renderers disabled and expired/stale render suppression enabled.

Standalone native and video are intentionally unsupported in this task. They remain fail-closed until Horus implements and validates the renderer/cache/provider capabilities required by those media types. The GAM bridge retains its existing media behavior.

## No-GAM invariant

A pure standalone placement has no GAM ad-unit path and does not initialize GPT. It does not create a GPT slot, set GAM targeting, call a GAM endpoint, require a GAM network code, or fall back into GAM.

Standalone Prebid and Direct JS remain independent engines. Different placements may use them on the same page. A physical placement assigned to both is still rejected by the renderer-conflict invariant; Task 15 does not add a global Prebid-versus-Direct-JS auction or automatic fallback.

## Refresh, SPA, and duplicate safety

Standalone refresh:

- enforces the configured refresh policy and the platform minimum interval;
- skips hidden pages;
- obeys the placement refresh limit;
- performs a fresh Prebid auction every time;
- never reuses a prior winner that is absent from the new auction callback;
- replaces the prior render iframe only after a new render succeeds.

The Loader keeps per-placement auction/render/observer state. Concurrent rescans cannot start the same placement auction twice. SPA mutation rescans may discover newly inserted placements, while already-defined placements remain idempotent. The permanent Loader boot guard remains unchanged.

## Privacy and Click Guard

The existing Loader privacy gate applies before standalone requests. A blocking consent result prevents the auction. LIMITED_ADS behavior remains governed by the existing privacy contract and the configured Prebid consent/activity settings.

Click Guard uses the existing browser-local state. If blocked, standalone starts no new auction, refresh auction, or render request. No Click Guard event stream is sent to Laravel.

## GAM bridge compatibility

`GAM_BRIDGE` continues to resolve through the connection-owned profile. Existing GAM price buckets, setup planner/service, setup templates, dry-run behavior, idempotency, audit records, and remote mappings remain intact. The standalone renderer is selected only for placements whose resolved renderer is `PREBID_STANDALONE`; GAM placements continue through the established Prebid targeting -> GPT -> GAM flow.
