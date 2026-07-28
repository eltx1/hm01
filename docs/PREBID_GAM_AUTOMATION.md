# Prebid GAM Automation Operations

## Prerequisites

Each GAM connection must be enabled and have:

- a network code;
- a tested credential reference outside the public web directory;
- `configuration.root_ad_unit_id` for line-item inventory targeting;
- `configuration.trafficker_id` for Prebid orders.

The platform detects missing prerequisites during dry-run and blocks external
writes until they are complete.

## Bidder onboarding

1. Open the website and choose **Prebid**.
2. Select the GAM connection scope being configured.
3. Add a bidder account from the registry.
4. Enter the public publisher/account ID and only browser-safe parameters.
5. Assign the account to the website, individual placements, or both.
6. Put placement IDs or zone IDs in placement overrides.
7. Enable Prebid settings for that website and selected GAM connection.
8. Save to publish a new production configuration.

The publisher does not edit Prebid.js, add bidder code, or create GAM line items.

## Price buckets

A price bucket is scoped to the organization that owns the GAM connection. Its
ranges contain `min`, `max`, `increment`, and `precision`. Horus expands these
ranges deterministically into `hb_pb` values and one GAM price-priority line
item for each value.

Changing a price bucket changes the estimated number of GAM objects. Always run
a new dry-run before external writes.

## Dry-run

Select **Dry-run and estimate GAM objects**. The result records:

- total planned objects;
- existing local-to-remote mappings;
- remaining external writes;
- counts by object type;
- missing GAM prerequisites;
- incomplete setup details.

Dry-run does not call a GAM create/update method.

## Confirmed execution

External writes require a user with `prebid.gam_setup` and the exact configured
confirmation phrase. The default phrase is:

`CREATE PREBID OBJECTS`

Execution is batched. Each successful object is mapped before the cursor moves.
A failed call stores a `prebid_errors` row and leaves the cursor on the failed
object.

## Resume

Use **Resume safely** for `PARTIAL` or `FAILED` runs. The setup service checks
existing mappings before every operation, so resuming or re-running skips
objects already created.

## Bulk setup

Bulk preview accepts explicitly selected GAM connections and creates a separate
plan for each network. Bulk execution accepts selected preview runs and the same
administrator confirmation phrase. Networks never share remote mappings.

## Verification

Setup is complete when the planner reports zero missing objects. The local
mapping table should contain the expected advertiser, targeting keys, targeting
values, orders, line items, creatives, and associations for that GAM
connection.

After setup:

1. enable a bidder on a test website;
2. publish a test or production static configuration;
3. verify the selected GAM network ID and Prebid mapping in the config preview;
4. load the publisher page with Horus debug enabled;
5. confirm auction, Prebid targeting, then GAM request order;
6. disable the bidder and republish; confirm no publisher code change is needed.

## Rollback and failure behavior

Static configuration rollback affects browser bidder settings and mappings. It
does not delete GAM objects. GAM automation never deletes remote objects during
normal setup. Disable obsolete line items through an explicit, separately
audited operation after review.

A browser-side Prebid timeout, adapter exception, or failed script load does not
pause the site and does not prevent the GAM fallback request.
