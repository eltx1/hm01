# Multi-Engine Serving Architecture

Status: authoritative product and engineering contract.

This document defines the approved Horus Media serving architecture after Tasks 13–16. It preserves the established Google Ad Manager path while making GAM optional for Horus-managed serving.

## Serving mode is not serving engine

A **serving mode** describes the website-level operating model. A **serving engine** is an independently controlled delivery capability used by placements.

Supported serving modes remain:

- `HORUS_GAM`
- `HORUS_DIRECT`
- `MCM_PARTNER_GAM`
- `PUBLISHER_GAM`
- `DIRECT_NATIVE_ONLY`
- `PAUSED`

Independent serving engines are:

- **GAM**
- **PREBID**
- **DIRECT_JS**

Valid states include all three engines enabled, standalone Prebid plus Direct JS with GAM disabled, or Direct JS alone. Engine enablement is never modeled as a global Prebid-versus-Direct-JS winner.

`SiteEngineStateResolver` is the central source of effective engine state. Controllers, readiness, and static configuration must consume that result rather than duplicate serving eligibility rules.

## Fixed runtime and deployment invariants

The architecture continues to require:

- one permanent Horus Loader;
- browser-side Prebid;
- static CDN runtime configuration;
- no advertising request through Laravel;
- aggregated reporting only;
- no raw browser bid/impression/click ingestion;
- no credentials or secrets in public configuration;
- PHP 8.2+ and MySQL portability;
- no Redis, Supervisor, WebSockets, Docker, permanent worker, or production Node runtime requirement;
- organization isolation;
- audit trails for sensitive control-plane changes;
- dry-run and idempotency for external writes.

## Engine controls

`AD_SERVING` is the master operational control. When disabled, all ad delivery stops.

Engine controls remain independent:

- `GAM` stops the GAM engine.
- `PREBID` stops both Prebid delivery modes.
- `DIRECT_JS` stops direct-provider JavaScript.
- `NATIVE_DEMAND` remains a legacy compatibility control during the transition period.

Disabling GAM must not inherently stop standalone Prebid or Direct JS. Disabling Direct JS must not disable GAM-managed demand.

## GAM contract

`HORUS_GAM`, `MCM_PARTNER_GAM`, and `PUBLISHER_GAM` retain the existing GAM implementation.

When GAM is active:

- GPT remains the browser delivery layer;
- `GamConnectionResolver` selects the eligible connection;
- inventory synchronization remains unchanged;
- direct-campaign GAM deployment remains unchanged;
- existing GAM identifiers and remote mappings remain authoritative;
- the legacy network-code fallback may preserve established GAM display delivery but does not manufacture a Prebid bridge relationship.

No AdX-specific competition code is introduced. If GAM contains AdX/Open Auction demand, the normal GAM decision remains authoritative after Prebid targeting is supplied through the bridge.

## Prebid configured mode and resolved delivery mode

Admin exposes a configured preference stored on `SiteServingSetting.prebid_configured_mode`:

- `AUTO`
- `GAM_BRIDGE`
- `STANDALONE`

Browser runtime receives only a concrete delivery mode:

- `GAM_BRIDGE`
- `STANDALONE`

`AUTO` is deterministic. The central resolver chooses:

1. `GAM_BRIDGE` when a real eligible GAM bridge is available;
2. otherwise `STANDALONE` when an enabled site-owned standalone profile exists;
3. `HORUS_DIRECT` may resolve to the standalone path, which remains incomplete until its standalone profile is configured;
4. otherwise the unresolved GAM-capable path remains `GAM_BRIDGE` and readiness reports the missing dependency.

An explicit `GAM_BRIDGE` selection never silently changes to standalone. If its GAM dependency later becomes unavailable, Prebid stops safely and readiness reports **Action Required**.

An explicit `STANDALONE` selection does not become GAM merely because a GAM connection exists.

The operational GAM switch participates in AUTO resolution: when GAM is disabled, an already configured standalone profile may continue through standalone Prebid. The master `AD_SERVING` control pauses delivery without changing the configured preference.

## Prebid runtime profiles

`prebid_settings` is the single generic runtime-settings table. Each row has exactly one owner:

- `GAM_CONNECTION` — connection-owned profile for `GAM_BRIDGE`;
- `SITE_STANDALONE` — site-owned profile for `STANDALONE`.

The Task 15 migration deterministically backfilled existing settings rows to `GAM_CONNECTION`, made the connection nullable only for the standalone owner, and added the site owner constraint.

The following remain shared and are **not duplicated** for standalone mode:

- bidder adapters;
- bidders;
- bidder accounts;
- bidder-site mappings;
- bidder-placement mappings.

Existing GAM price buckets, setup runs, templates, and remote-object mappings are not deleted or converted into standalone objects.

## GAM_BRIDGE runtime

The established flow remains:

```text
Prebid auction
  -> set Prebid targeting for GPT
  -> GPT
  -> GAM refresh/request
  -> GAM makes its normal final serving decision
```

The existing connection-owned Prebid profile and GAM setup planner/service remain responsible for GAM-specific setup. Line-item/creative setup remains dry-run capable, idempotent, audited, and resumable according to the existing control-plane contract.

A placement resolved to GAM must not use the standalone direct renderer.

## STANDALONE runtime

Standalone Prebid is a real no-ad-server browser path and requires no `GamConnection`.

The runtime sequence is:

```text
eligible PREBID_STANDALONE placement
  -> master / PREBID control gate
  -> privacy / consent gate
  -> Click Guard gate
  -> lazy visibility gate when configured
  -> load pinned Horus Prebid build
  -> add the placement ad unit
  -> bounded pbjs.requestBids auction
  -> select pbjs.getHighestCpmBids winner
  -> validate winner membership / auction / media type / TTL / timestamp
  -> create isolated iframe
  -> pbjs.renderAd(iframe document, adId)
  -> mark rendered
```

No synthetic CPM is generated.

A pure standalone placement must not:

- require `gamNetworkCode`;
- create a GPT slot;
- initialize GPT solely for that placement;
- set GAM targeting;
- call GAM;
- fall back into GAM.

## Supported standalone formats

Standalone direct rendering currently supports **banner only**.

Native and video standalone direct rendering are intentionally unsupported and fail closed until Horus implements and validates the provider renderer/cache behavior required by those media types.

GAM bridge media behavior is not reduced by this standalone limitation.

## Standalone render security

The direct-render iframe is isolated and grants only the bounded capabilities required by the approved banner path. Unrestricted top-window navigation is not granted.

Standalone Prebid configuration disables top-window renderers and enables stale/expired rendering protections. A winner that is not part of the current callback auction, is expired, is stale, has an invalid media type, or otherwise cannot be safely rendered is rejected.

## Refresh, lazy loading, SPA, and duplicate safety

Standalone refresh:

- honors the platform minimum refresh interval;
- honors placement refresh limits;
- skips hidden pages;
- performs a new auction for each refresh;
- does not reuse an old winning bid as the next auction winner;
- replaces the previous render surface only after the new render succeeds;
- remains gated by privacy, Click Guard, PREBID, and AD_SERVING controls.

SPA mutation rescans may discover dynamically inserted placements. Already-defined DOM elements remain idempotent.

Generated element IDs use an independent Loader instance sequence rather than the number of GPT slots. Multiple DOM containers using the same placement code therefore receive distinct auction/render state even on a page with no GAM slots.

Duplicate Loader boot protection remains unchanged.

## Click Guard and privacy

The existing browser privacy gate executes before standalone bidding. A blocking consent outcome prevents auctions. Consent settings and activity controls remain part of the public Prebid runtime configuration.

Click Guard gates standalone initial auctions, refresh auctions, and render requests exactly as it gates the existing ad paths. Click Guard state remains browser-local; it is not streamed to Laravel.

## Direct JS independence

Direct JS remains an independent engine:

- it is not represented as a fake Prebid bidder;
- it does not require GAM;
- it does not wait for standalone Prebid to lose;
- it may run on a different placement while standalone Prebid runs elsewhere on the same page.

There is no automatic Direct JS fallback from standalone Prebid in Tasks 15–16.

## Placement renderer ownership

Each generated placement has one renderer owner:

- `GAM`
- `PREBID_STANDALONE`
- `DIRECT_JS`
- `HOUSE`
- `NONE`
- `CONFLICT`

One physical placement must not have two renderers. A standalone-Prebid/direct-JS conflict emits `CONFLICT` and disables the placement rather than double-rendering.

Examples of valid independent placement routing:

```text
Placement A -> PREBID_STANDALONE
Placement B -> DIRECT_JS
```

and:

```text
Placement A -> GAM with PREBID GAM_BRIDGE
Placement B -> DIRECT_JS
```

## Static schema v3

Schema v3 is additive and continues to retain legacy schema-v2 fields for rollback compatibility.

The engine payload exposes both the configured and concrete Prebid state:

```json
{
  "engines": {
    "gam": { "enabled": false },
    "prebid": {
      "enabled": true,
      "configuredMode": "AUTO",
      "deliveryMode": "STANDALONE"
    },
    "directJs": { "enabled": true }
  }
}
```

The legacy top-level `prebidEnabled` remains bridge-only. The schema-v3 nested `prebid.enabled`, concrete `deliveryMode`, and placement renderer ownership activate standalone runtime without redefining schema-v2 semantics.

Static output may contain public bidder parameters required by browser adapters. It must never contain credential references, secrets, private notes, or sensitive control-plane data.

## Admin control and readiness

The existing Admin Prebid screen is the single management surface. It controls:

- website Prebid ON/OFF;
- configured mode and visible resolved mode;
- build;
- timeout;
- currency;
- bidder sequence;
- consent behavior;
- lazy loading;
- refresh;
- bidder accounts;
- site mappings;
- placement mappings;
- standalone behavior;
- GAM bridge behavior when relevant.

The GAM setup workbench is shown only when the resolved bridge and eligible GAM connection make it relevant.

A new explicit `GAM_BRIDGE` save is rejected if no eligible enabled GAM connection exists, so Admin never receives a false “settings saved” message for runtime values that cannot be attached to a bridge profile. If an already-configured bridge loses GAM later, the stored preference remains explicit and readiness reports Action Required.

Changes to website-level Prebid enablement/configured mode are audited separately from runtime-profile edits.

Publisher Monetization Health remains white-labelled. It exposes safe module status/reason/action fields only. Bidder account details, GAM network identifiers, credentials, and Admin diagnostics are excluded.

Admin diagnostics may include configured mode, resolved mode, build, mapping count, the relevant GAM connection when applicable, and last static-delivery state.

## Current implementation boundary

Tasks 15–16 deliver:

- GAM-independent standalone Prebid configuration;
- production standalone banner rendering;
- deterministic AUTO/GAM_BRIDGE/STANDALONE Admin control;
- independent GAM/PREBID/DIRECT_JS operational switches;
- publisher-safe readiness;
- preserved GAM bridge behavior;
- production hardening for refresh, SPA, duplicate placement instances, consent, Click Guard, and renderer ownership.

Standalone native/video direct rendering remains the primary intentional limitation.