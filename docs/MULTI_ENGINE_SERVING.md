# Multi-Engine Serving Architecture

Status: authoritative product and engineering contract.

This document defines the approved Horus Media serving architecture evolution.
It does not remove, replace, weaken, deprecate, or redesign the existing Google
Ad Manager integration. Existing GAM-enabled sites continue to use the current
GAM implementation. GAM is no longer a universal prerequisite for monetization.

## Serving mode is not serving engine

A **serving mode** describes how a website is broadly operated. A **serving
engine** is an independently enabled delivery capability used by one or more
placements.

Supported serving modes:

- `HORUS_GAM` — Horus-managed GAM; default GAM mode and fully backward compatible.
- `HORUS_DIRECT` — Horus-managed serving without a required GAM connection.
- `MCM_PARTNER_GAM` — optional partner GAM mode.
- `PUBLISHER_GAM` — optional publisher-owned GAM mode.
- `DIRECT_NATIVE_ONLY` — legacy/specialized direct-native mode retained for
  backward compatibility until a deliberate migration is approved.
- `PAUSED` — explicit paused mode.

The independent serving engines are:

- **GAM**
- **PREBID**
- **DIRECT_JS**

Engine state is not a mutually exclusive enum. A site may legitimately resolve
to any supported combination, for example:

```text
GAM=true   PREBID=true   DIRECT_JS=true
GAM=false  PREBID=true   DIRECT_JS=true
GAM=false  PREBID=false  DIRECT_JS=true
```

`SiteEngineStateResolver` is the central source of per-site engine state.
Controllers, static configuration, and readiness must not independently invent
GAM/Prebid/Direct-JS eligibility rules.

`AD_SERVING` remains the master operational control. A disabled master control
turns all engines and placement eligibility off. Engine-specific controls are
independent:

- `GAM` disables only the GAM engine.
- `PREBID` disables only Prebid.
- `DIRECT_JS` disables only direct provider JavaScript.
- `NATIVE_DEMAND` remains a backward-compatible broader alias during the
  compatibility period.

A GAM control must not implicitly disable Direct JS, and a Direct JS control
must not disable GAM-managed demand or House rendering.

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
    |             Prebid auction -> winning bid -> direct browser render
    |
    +-- Direct JS Engine
           Loader -> approved provider JS/tag
```

The permanent Loader and static CDN configuration remain the runtime control
surface. Advertising requests do not traverse Laravel.

## GAM engine contract

The GAM engine preserves the current implementation and remains a first-class
Horus product path.

For `HORUS_GAM`, `MCM_PARTNER_GAM`, and `PUBLISHER_GAM` when GAM is active:

- GPT remains the delivery layer.
- `GamConnectionResolver` remains the source of the selected GAM connection.
- inventory synchronization remains unchanged.
- direct campaign GAM deployment remains unchanged.
- Prebid-to-GAM integration remains available.
- Prebid may compete with GAM/AdX through the existing header-bidding flow.
- current dry-run, idempotency, authorization, audit, and reconciliation rules
  remain mandatory.

The schema-v3 engine resolver preserves the legacy `current_gam_network_code`
runtime fallback for established GAM modes so a previously valid deployment is
not silently invalidated during rollout. Readiness still reports a missing real
GAM connection as an operational issue. `HORUS_DIRECT` never receives that
fallback and never receives a synthetic GAM identifier.

## Prebid engine contract

Prebid remains browser-side and has two delivery contexts.

### GAM_BRIDGE

This is the existing behavior and remains unchanged:

```text
Prebid auction
  -> targeting
  -> GPT
  -> GAM
  -> GAM makes the final serving decision
```

A valid GAM context is required for the bridge. Existing GAM settings, price
buckets, line items, targeting, and remote-object idempotency remain intact.

### STANDALONE

This is the approved no-GAM behavior:

```text
Prebid auction
  -> winning bid
  -> Horus Loader direct render
```

Task 14 implements the **core standalone configuration foundation** only:

- no GAM connection or network code is required;
- the active pinned Prebid build is resolved without creating a GAM-scoped
  `PrebidSetting`;
- site and placement bidder mappings are reused;
- static configuration identifies `deliveryMode: STANDALONE`;
- a standalone placement may be eligible without `adUnitPath`;
- the legacy `prebidEnabled` compatibility flag stays `false` for standalone
  mode so the existing Loader cannot accidentally run the GAM bridge path.

Direct winning-bid rendering is intentionally **not** implemented by Task 14.
Until the dedicated browser-runtime rollout, readiness reports a complete
standalone configuration as `READY`, not falsely `ACTIVE`.

No raw browser bid, impression, or click stream may be sent to Laravel. Only
approved aggregate reporting remains permitted.

## Direct JS engine contract

Direct JS is an independent serving engine for approved provider integrations,
including custom approved tags and providers such as ExoClick, Adsterra, MGID,
or future approved networks.

Direct JS:

- does not enter a Prebid auction;
- does not require GAM;
- does not need to wait for a Prebid loss;
- may operate on independent placements while Prebid is operating elsewhere on
  the same page;
- is controlled by static Horus configuration and the permanent Loader;
- must use sanitized, approved public runtime values only; credentials and
  secrets never belong in CDN configuration.

Task 14 keeps the existing `nativeDemand` payload as a schema-v2 compatibility
surface while adding explicit Direct JS engine state. The new `DIRECT_JS`
control removes only direct-provider candidates; it does not remove GAM-managed
demand or House content. The legacy `NATIVE_DEMAND` control retains its broader
historical semantics during the migration window.

Provider tags must not be represented as fake Prebid bidders merely to fit an
older architecture.

## Placement renderer ownership

Engine enablement is site-level capability. Rendering is placement-level
ownership.

Schema v3 gives each generated placement an explicit renderer:

- `GAM`
- `PREBID_STANDALONE`
- `DIRECT_JS`
- `HOUSE`
- `NONE`
- `CONFLICT`

A single physical DOM surface has exactly one active renderer at a time. If a
GAM-less placement is configured for both standalone Prebid and Direct JS,
Task 14 emits `renderer: CONFLICT`, sets `rendererConflict: true`, and disables
that placement rather than allowing accidental double rendering.

Valid examples remain:

```text
Placement A -> STANDALONE PREBID
Placement B -> EXOCLICK DIRECT JS
Placement C -> ADSTERRA DIRECT JS
Placement D -> HOUSE
```

and:

```text
Placement A -> GAM + PREBID GAM_BRIDGE
Placement B -> DIRECT JS
```

Prebid and Direct JS do not require a global arbitration winner.

## HORUS_DIRECT contract

`HORUS_DIRECT` is the first-class Horus-managed no-GAM serving mode.

A `HORUS_DIRECT` site:

- may be active without `gam_connection_id`;
- receives `gamNetworkCode: null` in generated configuration;
- must not receive a fake `network_code` or synthetic GAM identifier;
- may enable standalone Prebid;
- may enable Direct JS;
- may enable both engines across independent placements;
- remains subject to the same organization, approval, privacy, supply-chain,
  Click Guard, static-delivery, and audit rules as other sites.

`DIRECT_NATIVE_ONLY` is not automatically migrated to `HORUS_DIRECT`. Existing
records retain their current semantics until a separate safe migration is
approved and tested.

## Static configuration schema v3

Task 14 introduces schema version 3 additively:

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
      "deliveryMode": "STANDALONE"
    },
    "directJs": {
      "enabled": true
    }
  }
}
```

Schema v3 deliberately retains the schema-v2 compatibility fields used by
existing deployed Loaders, including:

- `gamNetworkCode`
- `prebidEnabled`
- `prebid`
- `nativeDemandEnabled`
- `nativeDemand`
- `gpt`

Old immutable schema-v2 configurations remain readable by the current Loader;
the browser regression suite continues exercising old-shape configurations.
New schema-v3 payloads remain rollback-safe and deterministic. GPT metadata may
remain present for compatibility, but a GAM-less placement receives no GAM
`adUnitPath`, so the existing Loader does not load GPT for that placement.

Static payloads remain public-only. Credential references, API tokens, revenue
terms, private notes, and other secrets are excluded.

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

## Implementation boundary

Task 14 establishes the centralized engine model, independent operational
controls, GAM-optional configuration generation, additive schema v3, renderer
ownership, readiness behavior, and backward compatibility. It does **not**
implement standalone Prebid winning-bid rendering or redesign every Direct JS
provider integration. Those runtime changes require separate browser-focused
work and must keep the schema-v2 compatibility window intact until safe rollout
and rollback evidence exists.
