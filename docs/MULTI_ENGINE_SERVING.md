# Multi-Engine Serving Architecture

Status: authoritative product and engineering contract.

This document defines the approved Horus Media serving architecture evolution.
It does not remove, replace, weaken, deprecate, or redesign the existing Google
Ad Manager integration. Existing GAM-enabled sites continue to use the current
GAM implementation. The change is that GAM is no longer a universal prerequisite
for monetization.

## Serving mode is not serving engine

A **serving mode** describes how a website is broadly operated. A **serving
engine** is an independently enabled delivery capability used by one or more
placements.

Supported serving modes remain:

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

`AD_SERVING` remains the master operational control. A disabled master control
turns all engines off. Engine-specific operational controls may disable one
engine without implicitly disabling unrelated engines.

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

No multi-engine change may require replacement of the established GAM
implementation or make a previously valid GAM site invalid merely because
non-GAM engines now exist.

## Prebid engine contract

Prebid remains browser-side and has two delivery contexts.

### GAM_BRIDGE

This is the existing behavior:

```text
Prebid auction
  -> targeting
  -> GPT
  -> GAM
  -> GAM makes the final serving decision
```

A valid GAM connection is required for the bridge context. Existing GAM line
item/targeting behavior is preserved.

### STANDALONE

This is the approved no-GAM behavior:

```text
Prebid auction
  -> winning bid
  -> Horus Loader direct render
```

Standalone Prebid requires no GAM connection and no GPT request for that
standalone placement. The full direct-render runtime is implemented only by a
dedicated runtime task; the architecture contract does not silently emulate it
through GAM or Direct JS.

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

Provider tags must not be represented as fake Prebid bidders merely to fit an
older architecture.

## Placement renderer ownership

Engine enablement is site-level capability. Rendering is placement-level
ownership.

A single physical DOM surface has exactly one active renderer at a time unless
a future, explicitly isolated composite-placement design defines separate DOM
surfaces and lifecycle rules.

Valid examples:

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

Prebid and Direct JS do not require a global arbitration winner. If configuration
would make two independent renderers own the same physical placement, the safe
behavior is to reject, disable, or surface that conflict rather than
accidentally double-render.

## HORUS_DIRECT contract

`HORUS_DIRECT` is the first-class Horus-managed no-GAM serving mode.

A `HORUS_DIRECT` site:

- may be active without `gam_connection_id`;
- must not receive a fake `network_code` or synthetic GAM identifier;
- may enable standalone Prebid;
- may enable Direct JS;
- may enable both engines across independent placements;
- remains subject to the same organization, approval, privacy, supply-chain,
  Click Guard, static-delivery, and audit rules as other sites.

`DIRECT_NATIVE_ONLY` is not automatically migrated to `HORUS_DIRECT`. Existing
records retain their current semantics until a separate safe migration is
approved and tested.

## Static configuration evolution

The current schema remains supported. The next compatible schema may expose
engine state explicitly, conceptually:

```json
{
  "engines": {
    "gam": {
      "enabled": false
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

During a compatibility period, legacy fields such as `gamNetworkCode`,
`prebidEnabled`, and `nativeDemandEnabled` remain available while deployed
Loaders/configurations still consume them. Old schema-v2 configurations must
continue to work. Static build, publication, promotion, rollback, and
cache behavior remain deterministic.

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

This architecture contract establishes representation and future invariants.
It does not by itself implement standalone Prebid winner rendering or redesign
all Direct JS runtime behavior. Those changes must be introduced incrementally
with backward-compatible static configuration, browser tests, and explicit
placement renderer ownership.
