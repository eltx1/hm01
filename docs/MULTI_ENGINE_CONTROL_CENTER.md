# Unified Multi-Engine Serving Control Center

Task 19 adds one Horus Admin operational projection over the existing independent `GAM`, `PREBID`, and `DIRECT_JS` engines. It does not introduce a fourth serving path and does not move advertising requests into Laravel.

## Site 360

Admin Site 360 exposes one **Serving Control Center** with:

- `MASTER AD SERVING` — ON/OFF;
- `GAM` — ON/OFF/NOT CONFIGURED, serving mode, resolved connection and health;
- `PREBID` — ON/OFF, configured mode, concrete resolved mode, pinned build and health;
- `DIRECT JS` — ON/OFF, eligible provider networks, placement count and health;
- a production placement matrix showing the actual published renderer owner;
- source-aware aggregated financial-reporting health.

The controls reuse `PlatformControlService` and the existing Operations control endpoint. Site-scoped changes therefore keep the existing password/reason requirement, audit event, static production republish and permanent publisher Loader code.

Operations Center also exposes four explicit global edge states: **All Ad Serving**, **GAM**, **Prebid**, and **Direct JS**. Each state is shown as `ON` or `OFF · DISABLED`, with the persisted reason, actor, and timestamp. Disabling any platform engine requires an engine-specific typed confirmation in addition to the current password and reason.

Platform engine disables use `URGENT` static-delivery priority. Their outbox items are immediately available and bypass `static-delivery.batch_delay_seconds`, including future normal batching windows such as thirty minutes. This priority is durable data on the outbox and batch, so later batching work must preserve the urgent bypass and emergency deployment reserve.

## Engine independence

`AD_SERVING` remains the master stop. Engine controls are independent:

- GAM OFF does not stop standalone Prebid or Direct JS;
- PREBID OFF does not stop GAM or Direct JS;
- DIRECT_JS OFF does not stop GAM or Prebid;
- AD_SERVING OFF stops every engine.

A GAM-capable website can still use an independent Direct JS surface, but a placement that remains GAM-eligible continues to belong to GAM. Direct JS must own a distinct non-GAM-managed placement surface; Horus never silently steals a GAM placement.

## Placement matrix

The matrix is derived from the latest production static configuration rather than a second renderer-decision implementation. Columns are:

`Placement | GAM | Prebid | Direct JS | Renderer | Status`

`CONFLICT` remains fail-closed. A standard physical placement never has simultaneous standalone-Prebid and Direct-JS ownership.

## GAM-optional readiness

For `HORUS_DIRECT`, GAM is an optional module and appears as **NOT CONFIGURED**, not an error. Standalone Prebid or Direct Monetization can make the website operationally ACTIVE without GAM. If no monetization engine remains available, overall readiness becomes **ACTION REQUIRED**.

GAM-required serving modes retain their existing critical GAM dependency.

## Source-aware reporting health

Reporting health is derived only from aggregated reporting data already stored in the reporting ledger. Active sources are identified by their runtime dimensions:

- GAM connection;
- Prebid bidder;
- Direct Demand network.

Each source is shown as `FRESH`, `STALE`, `MISSING`, or `ERROR` according to its persisted reports and report-source connection. Direct Demand continues to use provider API/CSV/manual imports. Prebid financial reporting remains bidder/provider aggregated reporting. No raw browser impression, bid, or click finance pipeline is introduced.

## Action Center and transition notifications

A small `monetization_health_states` table stores only the last observed operational state/fingerprint for transition detection. It is not a reporting or finance ledger.

The scheduled `monetization:health-check` observes active websites every fifteen minutes and supports Action Center conditions for:

- invalid Header Bidding configuration;
- rejected Direct Demand mapping;
- unsafe/unrenderable approved Direct Demand recipe;
- suspended provider account;
- no active monetization engine;
- renderer conflict;
- stale/missing material financial reporting.

The first observation seeds state silently. Notifications are emitted only when a stored state changes (healthy → broken or broken → healthy), using the existing notification dedupe system. Optional GAM on `HORUS_DIRECT` does not create an Action Center incident.

Action Center remains aggregate-only. Task 19 adds one bounded snapshot query and the regression ceiling is fixed at 16 queries; the test still fails on unbounded/N+1 growth.

## Publisher boundary

Publisher health remains product-safe:

- Display Monetization;
- Header Bidding;
- Direct Monetization.

Provider identities, GAM network codes, bidder accounts, credentials, commercial terms and Admin reporting-source diagnostics remain internal.

## Targeted validation

The Task 19 targeted matrix covers no-GAM Prebid/Direct combinations, GAM Bridge plus an independent Direct JS surface, engine-specific pauses, master pause, production placement ownership, source-aware reporting, Action Center conditions, transition notification dedupe, and Publisher white-label output. Browser regression coverage explicitly boots standalone Prebid plus two Direct JS placements concurrently and asserts that no GPT/GAM script is loaded.
