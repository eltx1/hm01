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
| 09 Site health | PR306 paginated stored-check evidence, oldest-observation freshness, no monitor runs on GET | Cross-source triage, evidence actions and query-budget checks |
| 10 Check my site | Existing synthetic probe storage and schedule | Public URL guard review, explicit bounded on-demand check and report |
| 11 Visitor speed | Existing local timings; analyzer supports one page only | Sample contract, edge aggregation and population p50/p75/p95; not implemented |
| 12 Recovery | Existing versions and rollback; follow-up preserves current CF/Click Guard/domain/pause/control settings | Safe diff/dry-run UI and current-provider compatibility validation |
| 13 Gradual releases | Existing protected release/deployment pipeline | Explicit pilot cohort flags, pinned versions, comparisons and recovery tests |
| 14 Support | Existing tenant-safe website linking; follow-up immutable minimal creation-time snapshot for staff | Integration/permission tests and production evidence |
| 15 Onboarding | Existing simplified dashboard → sites → ads.txt → review flow; legacy writes retired | Derived next-action progress UI without restoring the retired wizard |
| 16 Referrals | Existing PublisherAffiliateService, attribution, finalized-period commission ledger and views | Commission/partner visibility review and onboarding integration |

## PR306 baseline validation

An initial feature assertion failed on PHP/SQLite and MySQL because it compared
an inserted in-memory record with a database-normalized record. The only mismatch
was key order / JSON spacing. It was corrected to compare database snapshots both
before and after the GET, retaining strict immutability verification. Full CI is
running again on d57e5e710c81d53f587fb59813803762e7483439.

## Follow-up validation

- Local PHPUnit 8.4: observation publication and rollback policy, 4 tests / 18 assertions pass.
- Node: analyzer + existing trace + observation suites, 37 tests pass.
- Vite production build passes. Generated build artifacts are not part of the change;
  production release tooling rebuilds them.
- PHP syntax and diff checks pass for edited files.
- Cloud Browser could not reach the isolated local preview (connection refused).
  No successful visual/browser check is claimed from that attempt.
- Laravel feature tests and required full CI are still required on the follow-up commit.
- Follow-up modifies no Loader source or siteverify worker and adds no browser-to-server
  trace transmission. It does change rollback payload protection and support metadata,
  so integration tests must pass before release.
