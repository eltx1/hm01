# GAM-less Pilot Validation Evidence

This file records reproducible pre-PR evidence for Task 20. It does not replace
production provider approval or the PR/release CI gates.

## Targeted validation

Task20 Targeted workflow run `31656996779` executed on the Task 20 branch and
completed every validation step successfully before the final push was rejected
only because the workflow token could not modify another workflow file.

Executed results before that Git permission-only failure:

- pilot/regression PHP matrix: **72 tests passed, 557 assertions**;
- full Horus Loader browser matrix: **74 tests passed, 0 failed**;
- npm dependency audit: **0 vulnerabilities**;
- pinned Prebid build: **Prebid.js 11.14.0, 16 modules**, including optional
  `onetagBidAdapter`;
- Horus Loader minified build and Vite production build: passed;
- SQLite migration validation: `migrate:fresh --seed`, rollback of the latest
  migration, and reapply all passed.

The permanent Production Release workflow now contains the same migration
fresh/rollback/reapply gate, so the PR must prove it again before merge.

## Pilot profiles covered

- PILOT A: standalone Prebid only, no GAM;
- PILOT B: Direct JS only, no GAM;
- PILOT C: standalone Prebid + Direct JS on independent placements;
- PILOT D: existing GAM + Prebid GAM_BRIDGE;
- PILOT E: GAM + Prebid GAM_BRIDGE + Direct JS on an independent non-GAM surface.

## Provider-readiness evidence

- OneTag is optional and requires operator-supplied `pubId`; no publisher ID is
  seeded or embedded by Horus.
- ExoClick asynchronous banner import is restricted to reviewed structured
  values and the trusted serve queue action; zone/container values remain
  provider/operator supplied.
- Adsterra remains generic-reviewed/operator-tag driven; Horus does not invent a
  script origin, zone ID, or provider-specific initializer.

## Security finding repaired

A failed Direct JS candidate could previously leave its provider container in
place while fallback proceeded. A late provider render could therefore coexist
visually with the succeeding provider. Task 20 removes the failed candidate
container before starting the next Direct provider and keeps a regression gate
for that ordering.

## Final release decision

The final `READY FOR GAM-LESS CONTROLLED PILOT` decision is made only after the
Task 20 PR passes the full PHP 8.2/8.3/8.4, MySQL, browser/build, Static Edge and
Production Release workflows and is merged without a readiness blocker.
