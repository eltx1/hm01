# Platform improvement progress

Each row separates existing functionality from new code and remaining work. No
row is considered deployed without a recorded production release.

| Task | Evidence / implemented work | Remaining acceptance work |
|---|---|---|
| 01 Site management | Existing Site 360; new publication and stored-health summaries (PR306) | Unified action queue and responsive evidence views |
| 02 Save/publication | PR306 publication evidence; follow-up save operation references reuse audited request IDs | Consistent references across remaining settings endpoints; production incident evidence |
| 03 Ad diagnostics | Existing bounded local Loader trace; follow-up local Operations analyzer separates config/CF/request/response/render/no-fill | Full independent-engine coverage and UI/browser verification |
| 04 Protection visibility | Existing verified admission and Click Guard retained | Aggregate outcomes, coverage denominators, device/browser trends |
| 05 Observation status | Follow-up saved versus confirmed configuration/key-match state, no secret/score exposure | Integration and browser verification; real Google dashboard evidence is external |
| 06 Reports | Existing source-aware health, timezone/currency and finality/reconciliation UI | Full freshness/coverage review across every report surface |
| 07 Earnings/payments | Existing statement balances, provisional earnings, finality and idempotent payout workflow | Reconciliation and reversal coverage review before financial changes |
| 08 Permissions | Existing Horus/admin RBAC and organization isolation; new feature tests deny publishers | Site-assignment model only where existing roles cannot meet scope |
| 09 Site health | PR306 paginated stored-check evidence, oldest-observation freshness, no monitor runs on GET | PR308 merged after complete CI; stored finding/stale/missing filters and one-query-per-page regression pass. Production deployed as main 59439b3; canonical Loader cache verification remains open. Cross-source issue queue remains. |
| 10 Check my site | Existing synthetic probe storage and schedule | Bounded on-demand HTML inspection implemented on this branch: public DNS revalidation and explicit cURL address/connection pinning per authorized redirect, 10-second total deadline, 512-KiB response limit, no scripts executed or HTML/URL stored. PR309 merged after complete CI; DNS deadline, public IP classification, redirect/body limits, IDOR and inert-markup regression pass. Production deployed as main 59439b3; canonical Loader cache verification remains open. Real browser execution remains separate evidence. |
| 11 Visitor speed | Existing local timings; analyzer supports one page only | Implemented on this branch: optional sampled post-PASS observer, separate bounded Edge collector, aggregate current-version p50/p75/p95 admin report and audited per-site opt-in. Default OFF. Local tests pass; required CI, Worker/app/static deployment and controlled live pilot pending. GPT display render evidence only; independent-engine coverage remains. |
| 12 Recovery | Existing versions and rollback; follow-up preserves current CF/Click Guard/domain/pause/control settings | Safe diff/dry-run UI and current-provider compatibility validation |
| 13 Gradual releases | Existing protected release/deployment pipeline | Explicit pilot cohort flags, pinned versions, comparisons and recovery tests |
| 14 Support | Existing tenant-safe website linking; follow-up immutable minimal creation-time snapshot for staff | Integration/permission tests and production evidence |
| 15 Onboarding | Existing simplified dashboard → sites → ads.txt → review flow; legacy writes retired | Next-action guidance added for every current lifecycle state; integration CI pending, no retired wizard restored |
| 16 Referrals | Existing PublisherAffiliateService, attribution, finalized-period commission ledger and views | Referral page now has aggregate active/setup website counts, scoped to authorized referral parents with child organization parity and soft deletes retained; PR308 merged after complete CI. Production pending; further partner-role review remains. |

## PR306 baseline validation

An initial feature assertion failed on PHP/SQLite and MySQL because it compared
an inserted in-memory record with a database-normalized record. The only mismatch
was key order / JSON spacing. It was corrected to compare database snapshots both
before and after the GET, retaining strict immutability verification. Full CI passed on d57e5e710c81d53f587fb59813803762e7483439. Review then found that retry/progress delivery-item state must override a historical DELIVERY_FAILED version status; this is fixed with regression cases and CI is rerunning.

## Follow-up validation

- Local PHPUnit 8.4: observation publication and rollback policy, 4 tests / 18 assertions pass.
- Node: analyzer + existing trace + observation suites, 37 tests pass.
- Vite production build passes. Generated build artifacts are not part of the change;
  production release tooling rebuilds them.
- PHP syntax and diff checks pass for edited files.
- Cloud Browser could not reach the isolated local preview (connection refused).
  No successful visual/browser check is claimed from that attempt.
- Follow-up PHP 8.2/8.3/8.4 and Chromium/WebKit passed on its first commit. MySQL found an order-sensitive JSON-key assertion; it now compares the sorted exact key allowlist. Review also found config/controls readiness ambiguity; timing now pairs resource and attempt, with regression tests. Full CI is rerunning.
- Follow-up modifies no Loader source or siteverify worker and adds no browser-to-server
  trace transmission. It does change rollback payload protection and support metadata,
  so integration tests must pass before release.

## Production and current branch evidence (2026-11-14)

- PR306 and PR307 deployed; main EA044 live verification 43624873312 confirms
  application origin HTTP 200 and exact Pages assets. Canonical public Loader
  and sellers URLs returned Cloudflare WAF 403 to that server-side verifier;
  this is not proof of browser ad rendering and no WAF exception was added.
- PR308 / PR309 merged. Main 59439b3 tests and production validation passed, deployment 43647399769
  succeeded. Live verification 43647583804 failed at the canonical Loader cache
  contract: direct read returns correct Loader SHA256 7fcc7a38… but max-age=14400
  instead of required immediate revalidation. No protection rule was changed.
- Read-only save incident probe: PR310. No production settings POST or publish
  experiment; main-only production environment guard remains in place.
- Current performance branch changes configuration builder, optional Loader
  bootstrap, separate observer asset, snapshot delivery, admin report/settings,
  aggregate SQL reader, separate Worker, deployment dry-run/ownership logic
  and regression tests. See workers/diagnostics/README.md for exact fields,
  coverage limits, rollout and recovery. There is no automatic site opt-in.
- Initial PHP regression caught recursive merge retaining stale performance
  fields while disabled. Reserved performance configuration now replaces that
  namespace with an allowlisted policy; the regression verifies private fields
  cannot escape and disabled is exactly `{enabled:false}`.
- Local combined PHP regression: 22 tests / 193 assertions passed before the
  additional immutable-history regression. All Node Browser tests: 1106 pass,
  36 intentionally skipped, 0 failed. New deploy dry-run/idempotence tests: 5 pass.
- New Chromium/Pixel 5/WebKit/iPhone CI cases exercise observer failure and
  stalled loading after ad startup; they have not yet been executed or passed.

## Resume checkpoint (2026-11-15)

The Worker/browser collector, API reader and per-site policy are implemented and
tested locally, but not merged, deployed or enabled on a publisher. Separate
immutable observer archives are committed and retained in snapshots so an asset
refresh cannot strand an older stored site configuration. The standard CI matrix
will verify PHP, MySQL and Chromium/Pixel/WebKit/iPhone before any merge.

PR310 review correctly identified JSON production log formatting. The parser now
supports JSON/text, converts explicit offsets to UTC, redacts raw content, and
reports recognized/unrecognized coverage; 6 deployment/parser tests pass locally.
The original browser ERR_CONNECTION_CLOSED cause is still unproven.

## Follow-up checkpoint — 2026-11-16 (Cairo)

This checkpoint supersedes earlier pending/deployment statements above. Completion
still requires implementation, tests, deployment and appropriate live evidence.

- Last verified application release: main `7a3d9bc94f9700f412cbd998619df36dd7edbd99`,
  deploy 43725174147 and live verification 43725324653 succeeded. PR306–310/313
  are included. Canonical permanent Loader cache now respects origin revalidation;
  direct HTTP 200 and exact unchanged Loader bytes were verified.
- PR311 performance observations and PR312 consistent save references are merged,
  but application deployment has not yet been confirmed. Main `1b686f4` production
  validation 43772982616 remained in progress at this checkpoint.
- Optional separate diagnostics Worker deployment failed with Cloudflare 403,
  code 10089, Analytics Engine availability. No site was automatically opted in.
  Account availability must be resolved before a controlled live pilot. No WAF or
  admission change was used to work around this dependency.
- PR315 configuration history remains open. Effective Direct JS state now uses
  `engines.directJs.enabled`, not the legacy demand flag. Local PHPUnit: 3 tests,
  15 assertions passed; updated required CI pending. No deployment claimed.
- PR316 attention queue remains open. It now includes current production failures
  and scheduled retries, excludes historical failures and retries already uploading,
  and displays queued versus confirmed versions. Local attention/health tests:
  3 tests, 44 assertions passed; updated CI pending. No deployment claimed.
- This reconciliation presentation change shows missing source totals and absent
  comparisons as unavailable, preserves genuine zero, and explains partial versus
  missing comparison evidence. Recorded workflow status is not independently
  verified equality. Financial calculations, import status, ledger, readiness and
  payout rules are unchanged. Local finance/source-integrity suite: 37 tests,
  343 assertions passed. Required CI and deployment pending.

Remaining acceptance work: full independent ad-engine diagnostics; aggregate
protection outcomes with valid denominators; report-surface coverage review;
finance/reversal and partner-role review; site assignment where existing RBAC
cannot express scope; remaining installation/ads.txt/protection/reporting queue
sources; current-provider-safe rollback preview and concurrency; actual immutable
canary version selection and recovery; preference-aware onboarding reminders;
controlled live observation and real publisher/device rendering verification.
The original browser connection-closed incident remains unexplained because the
available production logs did not cover the incident window. Browser emulation
and successful static delivery are not evidence of live physical Android ads.
