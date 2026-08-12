# Multi-Engine Serving Architecture

Status: authoritative product and engineering contract.

This document defines the approved Horus Media serving architecture. It does not remove, replace, weaken, deprecate, or redesign the existing Google Ad Manager integration. Existing GAM-enabled sites continue to use the current GAM implementation. GAM is not a universal prerequisite for monetization.

## Serving mode is not serving engine

A **serving mode** describes how a website is broadly operated. A **serving engine** is an independently enabled delivery capability used by one or more placements.

Supported serving modes:

- `HORUS_GAM` — Horus-managed GAM; default GAM mode and fully backward compatible.
- `HORUS_DIRECT` — Horus-managed serving without a required GAM connection.
- `MCM_PARTNER_GAM` — optional partner GAM mode.
- `PUBLISHER_GAM` — optional publisher-owned GAM mode.
- `DIRECT_NATIVE_ONLY` — legacy/specialized direct-native mode retained for backward compatibility until a deliberate migration is approved.
- `PAUSED` — explicit paused mode.

The independent serving engines are:

- **GAM**
- **PREBID**
- **DIRECT_JS**

Engine state is not a mutually exclusive enum. Supported combinations include:

```text
GAM=true   PREBID=true   DIRECT_JS=true
GAM=false  PREBID=true   DIRECT_JS=true
GAM=false  PREBID=false  DIRECT_JS=true
```

`SiteEngineStateResolver` is the central source of per-site engine state. Controllers, static configuration, and readiness must not independently invent GAM/Prebid/Direct-JS eligibility rules.

`AD_SERVING` remains the master operational control. A disabled master control turns all engines and placement eligibility off. Engine-specific controls are independent:

- `GAM` disables only the GAM engine.
- `PREBID` disables both Prebid delivery modes.
- `DIRECT_JS` disables only direct provider JavaScript.
- `NATIVE_DEMAND` remains a backward-compatible broader alias during the compatibility period.

A GAM control must not implicitly disable Direct JS. A Direct JS control must not disable GAM-managed demand or House rendering.

## Runtime topology

```text
Publisher page
    |
    v
Permanent Horus Loader
    |
    +-- GAM Engine
    |      +-- GPT
    |      +-- selected GAM connection
    |      +-- existing inventory / direct-campaign deployment
    |
    +-- Prebid Engine
    |      +-- GAM_BRIDGE
    |      |      Prebid auction -> targeting -> GPT -> GAM
    |      |
    |      +-- STANDALONE
    |             Prebid auction -> valid winning banner -> isolated iframe render
    |
    +-- Direct JS Engine
           Loader -> approved provider JS/tag
```

The permanent Loader and static CDN configuration remain the runtime control surface. Advertising requests do not traverse Laravel.

## GAM engine contract

The GAM engine preserves the established implementation and remains a first-class Horus product path.

For `HORUS_GAM`, `MCM_PARTNER_GAM`, and `PUBLISHER_GAM` when GAM is active:

- GPT remains the delivery layer.
- `GamConnectionResolver` remains the source of the selected GAM connection.
- inventory synchronization remains unchanged.
- direct campaign GAM deployment remains unchanged.
- Prebid-to-GAM integration remains available.
- current dry-run, idempotency, authorization, audit, and reconciliation rules remain mandatory.

The schema-v3 engine resolver preserves the legacy `current_gam_network_code` runtime fallback for established GAM modes so a previously valid deployment is not silently invalidated. Readiness still reports a missing real GAM connection as an operational issue. `HORUS_DIRECT` never receives that fallback and never receives a synthetic GAM identifier.

Horus contains no AdX-specific competition simulation. If a GAM account contains AdX/Open Auction demand, normal `GAM_BRIDGE` header bidding supplies Prebid targeting to GPT/GAM and GAM retains its normal final serving decision.

## Prebid configured mode and resolved delivery mode

Website administration exposes three configured values:

- `AUTO`
- `GAM_BRIDGE`
- `STANDALONE`

The browser has only two concrete delivery modes:

- `GAM_BRIDGE`
- `STANDALONE`

`AUTO` is resolved centrally before static configuration is built. It resolves to `GAM_BRIDGE` when the website is GAM-capable and a real enabled GAM connection is eligible and GAM is not operationally disabled; otherwise it resolves to `STANDALONE`. `AD_SERVING` can pause all delivery without changing the mode AUTO would use when serving resumes.

An explicit `GAM_BRIDGE` selection never silently becomes standalone. If its GAM dependency is unavailable, Prebid is stopped and readiness reports Action Required. An explicit `STANDALONE` selection remains standalone even when unrelated GAM capability exists.

Existing websites are migrated conservatively: pre-existing `HORUS_DIRECT` sites are backfilled to explicit `STANDALONE`, while other pre-existing sites are backfilled to explicit `GAM_BRIDGE`. New websites default to `AUTO`.

Schema v3 publishes both `engines.prebid.configuredMode` and the concrete `engines.prebid.deliveryMode`. Runtime execution consumes the concrete mode only.

## Prebid runtime profiles

`prebid_settings` is the single generic runtime-settings store with exactly one owner per row:

- `GAM_CONNECTION` — existing connection-owned profile used by `GAM_BRIDGE`.
- `SITE_STANDALONE` — site-owned profile used by `STANDALONE`.

The migration that introduced standalone ownership deterministically backfilled every pre-existing settings row to `GAM_CONNECTION`. It did not rewrite or delete GAM price buckets, GAM setup runs, templates, or remote object mappings.

Bidder adapters, bidder accounts, site mappings, and placement mappings remain one normalized shared graph. Standalone mode does not duplicate them.

## Prebid GAM_BRIDGE contract

The established behavior remains:

```text
Prebid auction
  -> Prebid targeting
  -> GPT
  -> GAM
  -> GAM makes the final serving decision
```

A real eligible GAM connection is required for the bridge. Existing connection-owned settings, price buckets, line items, targeting, setup planner/service, dry-run behavior, audit records, and remote-object idempotency remain intact. The standalone renderer is never invoked for a placement owned by GAM.

## Prebid STANDALONE contract

The no-ad-server behavior is:

```text
eligible placement
  -> privacy / Click Guard gate
  -> pinned Horus Prebid build
  -> add placement ad unit
  -> bounded requestBids auction
  -> select getHighestCpmBids winner
  -> validate callback membership / auction / media type / TTL / timestamp
  -> isolated sandboxed iframe
  -> pbjs.renderAd(iframe document, adId)
```

Standalone requires no `GamConnection`, `gamNetworkCode`, GAM targeting, GAM line-item buckets, GPT slot, or GAM fallback. No synthetic CPM is generated.

Standalone direct rendering currently supports **banner only**. Native and video fail closed until Horus explicitly implements and validates the required renderer/cache/provider behavior.

The standalone render frame grants only the constrained capabilities approved by the runtime policy. Unrestricted top navigation is not granted. Prebid top-window renderers are disabled and stale/expired rendering suppression is enabled.

A no-bid, timeout, bidder exception, build failure, invalid response, stale/expired winner, unsupported media type, or render exception fails closed.

Standalone refresh requests a fresh auction, respects the platform minimum interval, placement refresh limit, document visibility, lazy-loading rules, Click Guard, and the site/master controls. It does not reuse an old winning bid merely because a prior creative is still present.

SPA rescans and repeated scans are idempotent per DOM element. Dynamically inserted placements are discovered. Each generated element instance receives an independent Loader sequence ID, so two containers using the same placement code do not share auction/render state.

No raw browser bid, impression, or click stream may be sent to Laravel. Only approved aggregate reporting remains permitted.

## Direct JS engine contract

Direct JS is an independent serving engine for approved provider integrations.

Direct JS:

- does not enter a Prebid auction;
- does not require GAM;
- does not need to wait for a Prebid loss;
- may operate on independent placements while Prebid is operating elsewhere on the same page;
- is controlled by static Horus configuration and the permanent Loader;
- must use sanitized, approved public runtime values only; credentials and secrets never belong in CDN configuration.

Provider tags must not be represented as fake Prebid bidders merely to fit an older architecture.

## Placement renderer ownership

Engine enablement is site-level capability. Rendering is placement-level ownership.

Schema v3 gives each generated placement an explicit renderer:

- `GAM`
- `PREBID_STANDALONE`
- `DIRECT_JS`
- `HOUSE`
- `NONE`
- `CONFLICT`

A single physical DOM surface has exactly one active renderer at a time. If a GAM-less placement is configured for both standalone Prebid and Direct JS, configuration emits `renderer: CONFLICT`, sets `rendererConflict: true`, and disables that placement rather than allowing accidental double rendering.

Valid examples:

```text
Placement A -> STANDALONE PREBID
Placement B -> DIRECT JS
Placement C -> HOUSE
```

and:

```text
Placement A -> GAM + PREBID GAM_BRIDGE
Placement B -> DIRECT JS
```

Prebid and Direct JS do not require a global arbitration winner and neither is an automatic fallback for the other.

## HORUS_DIRECT contract

`HORUS_DIRECT` is the first-class Horus-managed no-GAM serving mode.

A `HORUS_DIRECT` site:

- may be active without `gam_connection_id`;
- receives `gamNetworkCode: null` when no GAM exists;
- must not receive a fake network code or synthetic GAM identifier;
- may enable standalone Prebid;
- may enable Direct JS;
- may enable both engines across independent placements;
- remains subject to organization, approval, privacy, supply-chain, Click Guard, static-delivery, and audit rules.

`DIRECT_NATIVE_ONLY` is not automatically migrated to `HORUS_DIRECT`.

## Static configuration schema v3

Schema version 3 is additive. Example:

```json
{
  "schemaVersion": 3,
  "gamNetworkCode": null,
  "engines": {
    "gam": {
      "enabled": false,
      "networkCode": null
    },
    "prebid": {
      "enabled": true,
      "configuredMode": "AUTO",
      "deliveryMode": "STANDALONE"
    },
    "directJs": {
      "enabled": true
    }
  }
}
```

Schema v3 deliberately retains schema-v2 compatibility fields used by deployed Loaders, including:

- `gamNetworkCode`
- `prebidEnabled`
- `prebid`
- `nativeDemandEnabled`
- `nativeDemand`
- `gpt`

The legacy top-level `prebidEnabled` remains GAM-bridge-only during the compatibility window. The schema-v3 nested Prebid object and placement renderer ownership activate standalone runtime safely.

Old immutable schema-v2 configurations remain readable by the current Loader. New schema-v3 payloads remain rollback-safe and deterministic. GPT metadata may remain present for compatibility, but a page with only standalone/no-GAM placements exits through the independent-engine path before GPT initialization.

Static payloads remain public-only. Credential references, API tokens, revenue terms, private notes, and other secrets are excluded.

## Readiness and administration

The existing Admin Prebid screen is the single management surface for both delivery modes. It exposes Prebid ON/OFF, configured mode, resolved mode, build/runtime settings, bidders, accounts, site mappings, placement mappings, lazy loading, refresh, consent, standalone behavior, and applicable GAM bridge behavior. GAM setup preview/history is shown only when the bridge is relevant.

Publisher Monetization Health remains white-labelled and safe. A complete active standalone profile can report `Header Bidding / Active`; an incomplete required path or unavailable explicit bridge reports `Header Bidding / Action Required`. Publisher output does not expose bidder credentials, bidder-account diagnostics, or sensitive GAM identifiers.

Admin diagnostics may show configured/resolved mode, build, mapping count, relevant GAM connection, and the last static-config state.

## Fixed deployment and security invariants

The multi-engine architecture does not change these rules:

- one permanent Horus Loader;
- no publisher code replacement after configuration changes;
- no ad request through Laravel;
- browser-side Prebid;
- static CDN runtime configuration;
- aggregated reporting only;
- no raw browser bid/impression/click ingestion;
- no credentials or secrets in static configuration;
- no Redis requirement;
- no Supervisor requirement;
- no WebSockets requirement;
- no Docker requirement;
- no permanent worker requirement;
- no production Node runtime;
- PHP 8.2+ and MySQL portability;
- organization isolation;
- audited sensitive operations;
- dry-run/idempotency for external writes;
- approved Horus Media brand rules.

## Current implementation boundary

Tasks 15 and 16 complete the GAM-independent standalone **banner** runtime, mode-aware administration, deterministic AUTO resolution, readiness integration, and GAM bridge regression hardening. Native/video standalone direct rendering remains intentionally unsupported and fail-closed. Direct JS remains an independent engine rather than a fallback inside standalone Prebid.
