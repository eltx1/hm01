# Prebid Architecture

## Fixed ownership model

Prebid.js executes only in the visitor browser. Laravel is a control plane that stores public bidder configuration, builds immutable CDN snapshots, and automates Google Ad Manager setup. Auction, bid, and impression traffic never passes through Laravel.

`HORUS_GAM` is the default network and uses the central Horus Prebid advertiser, order, line items, universal creative, and targeting objects. A website explicitly switched to `MCM_PARTNER_GAM` or `PUBLISHER_GAM` resolves the separate `prebid_settings`, price buckets, GAM template, setup runs, and remote mappings for that selected connection.

Publishers install one permanent Horus Loader. They never create Prebid line items and never change their code when a bidder, placement ID, price bucket, timeout, or GAM network changes.

## Browser sequence

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

No raw auction or impression event is posted to the Horus backend. Optional timeout reporting is local browser diagnostics only.

## Data model

- `prebid_builds`: pinned custom builds generated outside production runtime.
- `prebid_adapters`: public adapter registry, module code, parameters, media types, sizes, and documentation.
- `prebid_bidders`: enabled bidder aliases exposed by Horus.
- `bidder_accounts`: organization-owned public bidder account configuration.
- `bidder_site_mappings`: website assignment, sequence, overrides, and enabled state.
- `bidder_placement_mappings`: placement ID and placement-specific public parameters.
- `prebid_settings`: one isolated configuration per GAM connection.
- `prebid_price_buckets`: configurable GAM and browser price buckets per network.
- `prebid_gam_templates`: advertiser, order, line-item, targeting, and universal creative templates.
- `prebid_setup_runs`: dry-run, confirmed execution, cursor, progress, and resume state.
- `prebid_gam_remote_objects`: idempotent mapping from local plan keys to remote GAM IDs.
- `prebid_errors`: persistent setup and configuration errors.

Only public serving parameters enter the CDN payload. Credentials, API tokens, private commercial values, and GAM credential references remain server-side.

## Custom Prebid builds

`scripts/build-prebid.mjs` posts the pinned module manifest at `resources/prebid/horus-build.json` to the official Prebid download service during CI/release build. It writes:

- `public/assets/prebid/horus-prebid.js`
- `public/assets/prebid/horus-prebid.min.js`
- `public/assets/prebid/horus-prebid.sha256`

Production PHP does not compile Prebid and has no Node.js runtime dependency. The compiled files are included in the portable release artifact.

## Central GAM automation

The setup planner creates a deterministic plan containing:

- one advertiser;
- `hb_pb`, `hb_bidder`, `hb_adid`, `hb_size`, and `hb_format` targeting keys;
- required predefined `hb_pb` values;
- one order per configured currency/template;
- price-priority line items for all configured price points;
- one universal 1×1 third-party creative;
- line-item creative associations.

Every object has a stable local key and payload hash. Preview reports total, existing, and pending object counts. Dry-run performs no external write. A non-dry run requires explicit administrator confirmation. Each successful object is mapped to its remote GAM ID before the cursor advances. A failed run resumes from the first incomplete object. Re-running a completed plan creates no duplicate objects.

## Reliability and privacy

- Strict auction timeout plus an independent safety timer.
- GPT initial load is disabled until Prebid completes or fails.
- GAM fallback remains independent of Prebid.
- Placement failures are isolated.
- Duplicate loader and script initialization are prevented.
- Bidder disable is deployed through a new static configuration version.
- Switching GAM connections changes both network code and Prebid network configuration.
- No Prebid Server is present.
- No raw browser auction or impression telemetry is sent to Laravel.
