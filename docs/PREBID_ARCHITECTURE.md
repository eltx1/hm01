# Prebid Architecture

## SupplyChain Object integration

The permanent loader reads only the validated public
`supplyChain.schain` value from the static site configuration and applies it to
Prebid first-party data at `ortb2.source.ext.schain` before the auction. The
object is derived from the canonical seller identity; publishers do not add or
change page code when it changes. Missing or ambiguous identity omits the
object and fails safely. No bid or impression event is routed through Laravel.

The field semantics, lifecycle, and cross-validation rules are documented in
[`SUPPLY_CHAIN_COMPLIANCE.md`](SUPPLY_CHAIN_COMPLIANCE.md).

## Ownership model

Prebid.js executes only in the visitor browser. Laravel is a control plane that
stores public bidder configuration, builds immutable CDN snapshots, and, for the
GAM bridge context, automates Google Ad Manager setup. Auction, bid, impression,
and click traffic never passes through Laravel.

Prebid is an independent serving engine with two supported delivery contexts:

- `GAM_BRIDGE` — the existing implementation. The auction sets targeting on GPT,
  and the selected GAM connection makes the final serving decision.
- `STANDALONE` — approved GAM-less architecture. The auction winner is rendered
  directly by the permanent Horus Loader and no GAM connection or GPT request is
  required for that standalone placement.

`HORUS_GAM` remains the default GAM mode and continues using the central Horus
Prebid advertiser, order, line items, universal creative, and targeting objects.
A website explicitly switched to `MCM_PARTNER_GAM` or `PUBLISHER_GAM` continues
to resolve the separate `prebid_settings`, price buckets, GAM template, setup
runs, and remote mappings for that selected connection.

`HORUS_DIRECT` may use `STANDALONE` Prebid with no GAM connection. Standalone
runtime rendering is introduced incrementally by dedicated browser-runtime work;
it must not be simulated with a fake GAM network or by converting Direct JS
providers into fake Prebid bidders.

Publishers install one permanent Horus Loader. They never create Prebid GAM line
items manually and never change their page code when a bidder, placement ID,
price bucket, timeout, delivery context, or GAM network changes.

See [`MULTI_ENGINE_SERVING.md`](MULTI_ENGINE_SERVING.md) for the authoritative
multi-engine contract.

## GAM_BRIDGE browser sequence

This is the existing sequence and remains backward compatible:

1. Horus Loader fetches the immutable public configuration from the CDN.
2. GPT is loaded and initial ad loading is disabled.
3. GPT slots are created from Horus placements.
4. The pinned custom Prebid build is loaded when the selected network and website enable it.
5. Public bidder parameters are converted into Prebid ad units in the browser.
6. A bounded auction runs with the configured timeout.
7. Prebid targeting is applied to the corresponding GPT slots.
8. GPT requests ads from the selected GAM network.
9. No-bid, timeout, script failure, adapter failure, or configuration failure falls back to GAM when enabled.
10. Refresh runs repeat the bounded auction before refreshing the affected GPT slot.

No raw auction or impression event is posted to the Horus backend. Optional
timeout reporting is local browser diagnostics only.

## STANDALONE browser contract

The approved standalone sequence is:

1. Horus Loader fetches immutable public configuration from the CDN.
2. No GPT request is required for a standalone Prebid placement.
3. The pinned custom Prebid build and public bidder configuration are loaded in the browser.
4. A bounded auction runs with the configured timeout.
5. The winning bid is selected by Prebid.
6. The Horus Loader renders that winning bid directly into the placement owned by the standalone Prebid renderer.

The implementation must preserve one renderer per physical placement and must
not make Direct JS wait for, enter, or lose the standalone Prebid auction.
Prebid and Direct JS may run simultaneously on separate placements.

The complete direct-render implementation is outside the architecture-contract
task and is guarded by future browser-runtime tests.

## Data model

- `prebid_builds`: pinned custom builds generated outside production runtime.
- `prebid_adapters`: public adapter registry, module code, parameters, media types, sizes, and documentation.
- `prebid_bidders`: enabled bidder aliases exposed by Horus.
- `bidder_accounts`: organization-owned public bidder account configuration.
- `bidder_site_mappings`: website assignment, sequence, overrides, and enabled state.
- `bidder_placement_mappings`: placement ID and placement-specific public parameters.
- `prebid_settings`: existing isolated GAM-bridge configuration per GAM connection.
- `prebid_price_buckets`: existing configurable GAM/browser price buckets per GAM network.
- `prebid_gam_templates`: GAM-bridge advertiser, order, line-item, targeting, and universal creative templates.
- `prebid_setup_runs`: GAM-bridge dry-run, confirmed execution, cursor, progress, and resume state.
- `prebid_gam_remote_objects`: idempotent mapping from local plan keys to remote GAM IDs.
- `prebid_errors`: persistent setup and configuration errors.

Standalone Prebid must reuse public bidder/site/placement configuration where
appropriate without requiring synthetic GAM IDs. Its core runtime configuration
may use a GAM-independent build/default policy while keeping existing
GAM-connection settings untouched for bridge mode.

Only public serving parameters enter the CDN payload. Credentials, API tokens,
private commercial values, and GAM credential references remain server-side.

## Custom Prebid builds

`scripts/build-prebid.mjs` posts the pinned module manifest at
`resources/prebid/horus-build.json` to the official Prebid download service
during CI/release build. It writes:

- `public/assets/prebid/horus-prebid.js`
- `public/assets/prebid/horus-prebid.min.js`
- `public/assets/prebid/horus-prebid.sha256`

Production PHP does not compile Prebid and has no Node.js runtime dependency.
The compiled files are included in the portable release artifact.

## Central GAM automation

This section applies only to `GAM_BRIDGE` and is unchanged.

The setup planner creates a deterministic plan containing:

- one advertiser;
- `hb_pb`, `hb_bidder`, `hb_adid`, `hb_size`, and `hb_format` targeting keys;
- required predefined `hb_pb` values;
- one order per configured currency/template;
- price-priority line items for all configured price points;
- one universal 1×1 third-party creative;
- line-item creative associations.

Every object has a stable local key and payload hash. Preview reports total,
existing, and pending object counts. Dry-run performs no external write. A
non-dry run requires explicit administrator confirmation. Each successful object
is mapped to its remote GAM ID before the cursor advances. A failed run resumes
from the first incomplete object. Re-running a completed plan creates no
duplicate objects.

## Reliability and privacy

- Strict auction timeout plus an independent safety timer.
- GAM bridge preserves GPT initial-load and GAM fallback behavior.
- Standalone Prebid does not require GPT/GAM.
- Placement failures are isolated.
- A physical placement has one renderer at a time.
- Duplicate loader and script initialization are prevented.
- Bidder disable is deployed through a new static configuration version.
- Switching GAM connections changes the GAM bridge network configuration only;
  it must not invent a GAM dependency for standalone Prebid.
- No Prebid Server is present.
- No raw browser auction, impression, or click telemetry is sent to Laravel.
