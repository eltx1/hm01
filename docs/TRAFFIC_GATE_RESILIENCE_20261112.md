# Traffic gate resilience

## Behavior

- Default and supported maximum verification deadline: 25 seconds. Existing custom shorter values remain supported. An audited, idempotent migration upgrades persisted 15000 ms defaults only. Static contracts must be republished using the existing deployment reconciliation.
- A fresh verification attempt gets a new runtime, nonce, iframe, promise and full configured deadline. Duplicate boot calls, SPA navigation, progress messages and internal Turnstile resets do not extend a running deadline.
- Explicit refresh can retry finished technical failures at most twice per document. When enabled, online, visible-page return and persisted pageshow trigger the same bounded recovery after the configured retry interval. They share the budget; interactions cannot authorize ads. The existing single early-preparation fallback remains separate, so the upper bound is four full gate attempts including preparation.
- Site-config unsuccessful HTTP responses (including edge 403/404, 408, 429 and 5xx) retry once, with a fresh 5-second HTTP timeout. Persistent transient failures emit ERROR/STATIC_CONFIG_UNAVAILABLE, not DENIED. An HTTP error is not treated as a host authorization decision. Decoded host mismatch and server token rejection remain terminal. These HTTP requests remain inside the full gate deadline.
- Loader static JSON fetches bound headers and body decoding to five seconds per request, retry once for transient failures, ignore late completion and abort when supported. Exhausted global-control fetches close serving. Manifest/versioned-config/alias fallback remains, so 25 seconds is not a total page-to-ad SLA.
- Secure UUIDv4 fallback uses getRandomValues when randomUUID is unavailable. No secure crypto means no verification and no ads.
- PHP accepts only the canonical verification origin, optionally a root slash normalized before publication. Query, fragment, port, credentials, mixed-case and non-root paths cannot publish a READY contract.
- Restoring protection from DISABLED or changing a pending or successful gate's authorization configuration requires fresh verification. An unchanged successful document still shares one PASS across placements; no token is persisted, and ordinary navigation to a new document verifies anew. No arbitrary periodic challenge was added.

- Updated the locked follow-redirects dependency from 1.16.1 to 1.16.3; npm audit reports zero vulnerabilities. Unrelated optional dependency entries are preserved.

## Security invariants

Exact origin/source/protocol/nonce binding, server verification, hostname/action/cData checks, worker replay rejection, per-attempt absolute deadline, privacy prerequisites and no ad requests before PASS are preserved. No Cloudflare rule, production secret, production environment permission or worker validation was weakened. DENIED and VERIFICATION_REJECTED are not recoverable. Other permanent setup/crypto/browser errors are not activity-retried.

## Verification and delivery

Local full Node suite: 1035 passed, 0 failed, 36 skipped (1071 total), before final remote validation. Build succeeds. PHP and four-profile Playwright validation runs in the isolated workflow; CI results are authoritative for final counts. Pixel 5/iPhone 13 projects are browser emulations, not physical devices or real Cloudflare challenges.

Rollout is backward compatible: public configs retain a capped legacy `maxWaitMs` (at most 15000), and add `attemptWaitMs` with the actual configured budget. New parent/child clients validate and prefer the added field; cached old clients retain their previous 15-second behavior instead of rejecting a 25-second contract. New clients still accept older contracts. Rolling back the client does not require weakening protection or stripping the new field. The migration does not overwrite later operator settings on rollback.

Live publisher root cause, current WAF rules and real-device behavior remain unverified because the audit environment was blocked from the publisher page. This change addresses reproduced code defects without treating the cloud-environment block as evidence about all Android users.

## Changed files

- config/traffic_gate.php
- app/Http/Controllers/Admin/TrafficQualityController.php
- app/Services/Settings/TypedSettingsRegistry.php
- app/Services/TrafficGate/TrafficGateAdminOverviewService.php
- app/Services/TrafficGate/TrafficGateConfigurationResolver.php
- app/Services/TrafficGate/TrafficGateSettingsValidator.php
- database/migrations/2026_11_12_130000_extend_traffic_gate_wait_to_twenty_five_seconds.php
- public/assets/hm-loader.js and public/assets/hm-loader.min.js
- public/assets/traffic-gate/horus-traffic-gate.js
- scripts/transform-loader-traffic-gate.mjs
- resources/views/admin/operations/traffic-quality.blade.php
- tests/Browser/hm-loader-traffic-gate.test.js
- tests/Browser/traffic-gate.test.js
- tests/Browser/traffic-gate-loader.playwright.spec.js
- tests/Feature/TrafficGateFoundationTest.php
- .github/workflows/traffic-gate-resilience.yml
- docs/TRAFFIC_GATE_RESILIENCE_20261112.md

- package-lock.json (follow-redirects security patch only)

- app/Services/TrafficGate/TrafficGateConfiguration.php (backward-compatible timing contract)
- tests/Browser/hm-loader.test.js (bounded manifest retry)
- .github/workflows/tests.yml (25-second boundary selector)
