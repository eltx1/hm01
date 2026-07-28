# Prebid Architecture

## Fixed ownership model

Prebid.js executes only in the visitor browser. The Horus Loader retrieves a
versioned public configuration from the CDN, loads a pinned custom Prebid build,
runs a bounded auction, applies Prebid targeting to GPT, and then requests ads
from the selected Google Ad Manager network.

Laravel is the control plane. It creates and publishes configuration snapshots,
manages bidder accounts and mappings, and automates GAM setup. Laravel is not in
the auction, impression, or ad-request path. Horus does not collect every
auction or impression event.

Prebid Server is intentionally not implemented.

## GAM network selection

The site serving mode resolves exactly one GAM connection:

- `HORUS_GAM` uses the primary central Horus GAM connection and its centralized
  Prebid advertiser, orders, line items, creatives, targeting keys, values, and
  local-to-remote mappings. A bidder mapping with the `DEFAULT` scope is allowed
  only as a Horus central default.
- `MCM_PARTNER_GAM` uses only the selected partner GAM connection and mappings
  explicitly scoped to that connection.
- `PUBLISHER_GAM` uses only the selected publisher GAM connection and mappings
  explicitly scoped to that connection.
- `PAUSED` loads neither GPT nor Prebid.

Switching the selected GAM connection changes only the static configuration.
The publisher keeps the same Horus Loader and placement markup.

## Browser sequence

1. Publisher page loads one permanent Horus Loader using a stable site key.
2. Loader resolves a cacheable configuration snapshot from the Horus CDN.
3. Hostname, pause state, placement eligibility, consent settings, and network
   scope are evaluated in the browser.
4. GPT is loaded and eligible slots are defined with initial loading disabled.
5. The pinned Prebid.js build is loaded only when the selected network has an
   enabled Prebid configuration and eligible bidders.
6. Horus placements are transformed into Prebid ad units.
7. Prebid runs once per eligible request or refresh, bounded by the configured
   timeout.
8. Prebid key-values are applied to the matching GPT slots.
9. GPT requests the selected GAM network.
10. If no bid exists, the auction times out, the build fails, or an adapter
    throws, GAM continues safely when fallback is enabled.

Bidder disablement takes effect through a newly published static configuration;
publisher code does not change.

## Public configuration

Only browser-safe values are published:

- selected GAM network code and serving mode;
- Prebid build URL, version, and checksum;
- auction timeout, granularity, currency, consent, lazy-load, refresh, timeout
  diagnostics, bidder order, and GAM fallback behavior;
- enabled bidder codes and registered public bidder parameters;
- placement media types, sizes, bidder placement IDs, and public parameters.

Credentials, service-account data, private API tokens, margins, internal notes,
GAM credential references, and setup-run internals never enter CDN payloads.
Parameter names containing secret, token, password, private, or credential are
rejected from bidder public configuration.

## Custom builds

Prebid.js is pinned to `11.26.0`. CI checks out that exact upstream ref, installs
its dependencies, and runs the documented custom build command with the Horus
module manifest. The release contains versioned unminified, minified, and JSON
manifest files under `public/assets/prebid/`.

Production hosting never compiles Prebid and does not need Node.js. A missing or
invalid build leaves Prebid disabled and cannot break GAM delivery.

## Centralized GAM setup

The setup planner creates deterministic object keys for:

- one Prebid advertiser per GAM connection;
- required custom targeting keys and predefined values;
- one or more Prebid orders, split before GAM order limits;
- one price-priority line item per configured price bucket;
- universal creatives for configured creative sizes;
- creative-to-line-item associations.

Every successful external write creates a `prebid_gam_remote_objects` mapping
with the GAM connection, object type, deterministic key, remote ID, payload
hash, status, and sync time. Re-running the planner skips mapped objects, so it
cannot create duplicate orders or line items.

A dry-run creates no GAM object. It calculates the plan, missing requirements,
existing mappings, estimated external writes, and incomplete setup state.
External writes require the administrator confirmation phrase. Each write
updates a persisted cursor; interrupted or failed runs resume from the last
successful object. Bulk preview and execution operate across explicitly
selected GAM connections.

See `docs/PREBID_GAM_AUTOMATION.md` for operating instructions.

## Reliability guarantees

- GPT initial loading is disabled before display and released only after the
  Prebid auction completes or fails.
- Timeout and build failures are caught per request and fall back to GAM.
- Browser timeout reporting uses diagnostics and a local browser event; it does
  not send each auction to the Horus backend.
- Static configuration remains versioned, cacheable, and rollback-capable.
- Duplicate loader, GPT, Prebid build, ad-unit, slot, and refresh initialization
  are guarded.
- Paused sites and disabled placements make no advertising calls.
