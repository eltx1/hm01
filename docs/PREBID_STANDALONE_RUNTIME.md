# Standalone Prebid Runtime

This document is the runtime contract for Horus Media Prebid delivery when `deliveryMode=STANDALONE`.
It complements `MULTI_ENGINE_SERVING.md`, `PREBID_ARCHITECTURE.md`, and `PREBID_OPERATIONS.md`.

## Configured mode versus resolved delivery mode

The Admin-facing website setting is `prebid_delivery_mode` and accepts:

- `AUTO`
- `GAM_BRIDGE`
- `STANDALONE`

The browser never receives an ambiguous AUTO execution path. `SiteEngineStateResolver` resolves AUTO to one of the two concrete delivery modes:

- `GAM_BRIDGE` when the website is in a GAM-capable serving mode and a real enabled GAM connection is eligible and the GAM operational control is on.
- `STANDALONE` otherwise.

A master `AD_SERVING` pause stops requests without changing the concrete mode AUTO would use when serving resumes. `GAM OFF` may therefore move AUTO from GAM_BRIDGE to STANDALONE, while `PREBID OFF` stops either mode. Explicit `GAM_BRIDGE` never silently falls back to standalone; when its GAM dependency is unavailable the engine is disabled and readiness reports ACTION REQUIRED.

Existing websites are migrated conservatively: existing `HORUS_DIRECT` sites are backfilled to explicit `STANDALONE`, while other existing websites are backfilled to explicit `GAM_BRIDGE`. New websites default to AUTO.

Static schema v3 publishes both `engines.prebid.configuredMode` and the concrete `engines.prebid.deliveryMode`. Runtime code consumes only the concrete delivery mode.

## Runtime profile ownership

`prebid_settings` is the single generic runtime-settings store. Every row has exactly one owner:

- `GAM_CONNECTION`: the existing connection-owned profile used by `GAM_BRIDGE`.
- `SITE_STANDALONE`: a site-owned profile used by `STANDALONE`.

The migration deterministically labels all pre-existing settings `GAM_CONNECTION`. Existing GAM price buckets, setup runs, templates, and remote-object mappings are not rewritten or deleted. Bidder adapters, bidder accounts, site mappings, and placement mappings remain shared normalized resources; there is no standalone copy of that graph.

Horus-managed bidder accounts may continue to be mapped to publisher sites. Tenant isolation is enforced at site and placement mapping boundaries and by the existing authorization/control plane, rather than by incorrectly requiring the bidder account owner and publisher site owner to be the same organization.

## Admin control

The existing Admin Prebid screen is the single management surface for both modes. It shows:

- website Prebid ON/OFF;
- configured mode and concrete resolved mode;
- selected build and generic runtime settings for the resolved profile;
- bidder accounts, site mappings, and placement mappings;
- standalone behavior when standalone is resolved;
- GAM setup preview/history only when GAM_BRIDGE is relevant and an eligible connection exists.

An explicit GAM_BRIDGE selection without an eligible GAM connection remains visible as ACTION REQUIRED. The screen can still be opened; Horus does not require GAM merely to manage or switch Prebid mode. AUTO with GAM unavailable edits the site-owned standalone profile, so the fallback path is a real production configuration rather than a test-only path.

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

Standalone rendering supports **banner only**.

The direct-render iframe uses the constrained Prebid no-ad-server sandbox capabilities required for common banner creatives:

- `allow-forms`
- `allow-popups`
- `allow-popups-to-escape-sandbox`
- `allow-same-origin`
- `allow-scripts`
- `allow-top-navigation-by-user-activation`

Unrestricted `allow-top-navigation` is not granted. Prebid is configured with top-window renderers disabled and expired/stale render suppression enabled.

Standalone native and video are intentionally unsupported. They remain fail-closed until Horus implements and validates the renderer/cache/provider capabilities required by those media types. The GAM bridge retains its existing media behavior.

## No-GAM invariant

A pure standalone placement has no GAM ad-unit path and does not initialize GPT. It does not create a GPT slot, set GAM targeting, call a GAM endpoint, require a GAM network code, or fall back into GAM.

Standalone Prebid and Direct JS remain independent engines. Different placements may use them on the same page. A physical placement assigned to both is still rejected by the renderer-conflict invariant; there is no global Prebid-versus-Direct-JS auction or automatic fallback.

## Refresh, SPA, and duplicate safety

Standalone refresh:

- enforces the configured refresh policy and the platform minimum interval;
- skips hidden pages;
- obeys the placement refresh limit;
- performs a fresh Prebid auction every time;
- never reuses a prior winner that is absent from the new auction callback;
- replaces the prior render iframe only after a new render succeeds.

The Loader keeps per-render-surface auction/render/observer state. Concurrent rescans cannot start the same element auction twice. SPA mutation rescans may discover newly inserted placements, while already-defined elements remain idempotent. Multiple DOM containers carrying the same placement code must still receive distinct Horus element IDs and distinct auction state. The permanent Loader boot guard remains unchanged.

## Privacy and Click Guard

The existing Loader privacy gate applies before standalone requests. A blocking consent result prevents the auction. LIMITED_ADS behavior remains governed by the existing privacy contract and the configured Prebid consent/activity settings.

Click Guard uses the existing browser-local state. If blocked, standalone starts no new auction, refresh auction, or render request. No Click Guard event stream is sent to Laravel.

## Readiness and diagnostics

Publisher output remains white-labelled and exposes only safe module status/reason/action fields. A complete standalone runtime profile with an enabled build and mappings reports `Header Bidding / ACTIVE`; an incomplete or unavailable required path reports `ACTION_REQUIRED`. Bidder account identifiers, GAM network IDs, account names, and profile diagnostics are not included in publisher output.

Admin diagnostics may show configured mode, resolved mode, build, mapping count, relevant GAM connection identity, and the last static-config delivery state. These diagnostics are read-only and do not make synchronous remote health calls.

## GAM bridge compatibility

`GAM_BRIDGE` continues to resolve through the connection-owned profile. Existing GAM price buckets, setup planner/service, setup templates, dry-run behavior, idempotency, audit records, and remote mappings remain intact. The standalone renderer is selected only for placements whose resolved renderer is `PREBID_STANDALONE`; GAM placements continue through the established Prebid targeting -> GPT -> GAM flow.

Horus adds no AdX-specific auction code. If GAM contains AdX/Open Auction demand, the existing GAM_BRIDGE flow continues to pass Prebid targeting into GPT/GAM and GAM remains responsible for its normal final ad-server decision.
