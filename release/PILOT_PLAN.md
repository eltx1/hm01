# Production Pilot Plan

## Phase 1 — Dashboard only
Deploy the dashboard without external credentials. Verify TLS, headers, health endpoint, administrator login, mandatory 2FA, mail, cron heartbeat, database queue, audit log, private uploads, backup, and restore rehearsal.

## Phase 2 — Horus GAM
Install the protected service-account reference, create the primary `HORUS_GAM` connection, and pass a dry-run connection test.

## Phase 3 — Test website
Create one Horus Media-owned test publisher and website with verified domain ownership.

## Phase 4 — GAM ad unit
Create one display placement and idempotently synchronize one GAM ad unit.

## Phase 5 — House advertiser
Create one house advertiser, creative, campaign, budget, targeting set, order, and line item. Keep external billing disabled.

## Phase 6 — Permanent loader
Install the single permanent Horus Loader tag. Confirm hostname authorization and static configuration retrieval from `cdn.horusmedia.net`.

## Phase 7 — Reporting
Serve controlled house impressions, import finalized GAM aggregates, reconcile source totals, and verify publisher/Horus calculations.

## Phase 8 — One Prebid bidder
Enable one approved adapter and placement mapping. Verify auction targeting, timeout behavior, GAM fallback, and the Prebid kill switch.

## Phase 9 — MGID native
Enable MGID on one native placement only. Verify ads.txt, direct/GAM integration mode, safe fallback, and the native-network kill switch.

## Phase 10 — First external publisher
Onboard one low-risk publisher, verify domains and contract, deploy one placement, monitor IVT and discrepancies daily, close the first statement manually, and expand only after a successful payment cycle.
