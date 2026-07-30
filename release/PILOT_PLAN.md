# Horus Media Production Pilot

## Phase 1 — Dashboard without external credentials

Deploy the application, database, mail, cron, operations dashboard, security headers, TOTP, backup process, and Cloudflare controls. Leave GAM/native credentials empty and external writes dry-run. Acceptance: `/up`, login/TOTP, password reset, scheduler heartbeat, queue, logs and all kill switches work.

## Phase 2 — Connect Horus GAM

Add the least-privilege service account and primary `HORUS_GAM` connection. Run health, network and permission discovery in dry-run. Acceptance: correct network, no credential material in logs/database/static JSON, sanitized API ledger.

## Phase 3 — Horus Media test website

Create one internal test publisher website, authorize its exact hostname, keep `HORUS_GAM`, and publish a production configuration. Acceptance: public configuration contains only browser-safe values and unauthorized hosts are rejected.

## Phase 4 — One GAM ad unit

Create one local ad unit and placement; dry-run then approve one GAM synchronization. Acceptance: one remote object mapping, idempotent re-run, no duplicate ad unit.

## Phase 5 — House advertiser and campaign

Create a Horus house advertiser, approved creative and house campaign targeting only the test placement. Acceptance: approved local workflow, invoice/report handling appropriate to HOUSE pricing, one GAM deployment instance.

## Phase 6 — Install Horus Loader

Install the permanent minified Loader on the test website. Acceptance: control JSON is read first, site config loads, hostname validation passes, GPT loads once, expected slot and targeting are defined.

## Phase 7 — Verify GAM impression reporting

Generate controlled house impressions, run daily import and reconciliation. Acceptance: aggregated totals appear once, reruns are idempotent, no raw visitor/bid/request events are stored.

## Phase 8 — One approved Prebid bidder

Enable the pinned browser build and one policy-approved bidder on one placement. Acceptance: targeting before GAM refresh, timeout/script failure falls back to GAM, global Prebid switch stops auctions without stopping direct GAM.

## Phase 9 — MGID on one native placement

Connect one MGID account using a private credential reference and one native placement. Acceptance: direct/native fallback behaves as configured, GAM-managed objects are not injected directly, global native switch works, reports reconcile.

## Phase 10 — First external publisher

Onboard one low-risk external publisher with signed contract, verified domains, payment profile, approved placements and a documented rollback contact. Start with conservative traffic. Acceptance: tenant isolation, statement/revenue share, support process, discrepancy monitoring and emergency pause all pass before expansion.

## Pilot stop conditions

Stop or roll back on credential leakage, cross-tenant access, duplicate GAM writes, unexplained financial discrepancy, sustained reporting failure, invalid traffic concern, Loader impact on page stability, policy rejection, or unavailable backup/restore path.
