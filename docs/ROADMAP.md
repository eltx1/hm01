# Roadmap

The speculative implementation roadmap stops at Task 42. Current product work is governed by `FINAL_LAUNCH_READINESS.md`, `GO_LIVE_CHECKLIST.md`, and `PILOT_RUNBOOK.md`: collect missing external production evidence, remediate only observed failures, and scale only profiles whose own gates pass.

## Implemented foundation

The repository implements the Laravel control plane, identity/tenancy/RBAC, dedicated Admin authentication and TOTP, Publisher applications/onboarding/sites, HMP/HMS supply-chain identities, ads.txt/sellers.json/SChain, GAM integration, standalone Prebid, Direct JS/direct demand, Direct Advertiser Campaign workflow, reporting/finance, privacy readiness, THOTH advisory review, static delivery/Cloudflare edge controls, operations/audit/notifications/support, branding, and production release validation.

`HORUS_GAM` remains the established/default GAM-backed Publisher path. `HORUS_DIRECT` represents a Horus-managed Publisher Website with no required GAM connection. GAM, standalone Prebid, and Direct JS are independent serving engines and one physical DOM surface has one rendering owner at a time.

Direct Advertiser Campaign serving is a separate product truth: the current campaign backend is GAM-backed. `HORUS_DIRECT`, standalone Prebid, and Direct JS are not advertiser campaign backends.

## Current Task-42 launch profiles

| Profile | Status | Next work |
|---|---|---|
| Public Publisher Application | READY WITH EXTERNAL EVIDENCE | Production DNS/TLS/SMTP/Turnstile-if-enabled, real HMP/HMS ads.txt, root sellers.json, backup/recovery, optional THOTH provider evidence. |
| GAM-backed Publisher Pilot | READY WITH EXTERNAL EVIDENCE | Real GAM/network permissions, Publisher origin/Loader/privacy, reporting reconciliation, rollback and Finance sign-off. |
| GAM-less `HORUS_DIRECT` Publisher Pilot | READY WITH EXTERNAL EVIDENCE | Real Publisher/provider/privacy/reporting/static rollback evidence; no GAM prerequisite. |
| Direct Advertiser Campaign Pilot | NOT READY | First prove a real eligible production GAM campaign backend plus controlled deployment/pause/reconciliation/rollback evidence. |

## Controlled production sequence

1. Validate the public application smoke path.
2. Approve one Publisher through human review.
3. Create one Website after approval/onboarding.
4. Validate public supply-chain artifacts.
5. Activate exactly one Publisher monetization profile (GAM-backed or GAM-less).
6. Import/reconcile real aggregated reporting and prove financial finality.
7. Prove safety pause/rollback and obtain Operations/Finance sign-off.
8. Scale only after the chosen profile remains stable.
9. Start an advertiser-campaign pilot only after its production GAM backend blocker is cleared.

## External evidence, not new architecture

The next work items are operational evidence: production routing/TLS/MySQL/APP_KEY/debug state, SMTP, scheduler/cache locks, deployment credentials, Admin TOTP, backup/isolated restore/private storage backup, Turnstile hostname if enabled, provider credentials, live sellers.json and Publisher ads.txt/HMP-HMS verification, real Loader/provider IDs, live privacy probe, reporting/reconciliation, rollback drills, and Finance approval.

Failures discovered while collecting those facts should become tightly scoped remediation work. Do not invent a new subsystem merely to make a checklist green.

## Explicitly out of Task-42 scope

Task 42 does not authorize WebAuthn, a new advertiser ad server, THOTH yield optimization, an IVT engine, new bidder-provider expansion, a payment processor, browser-canary monitoring, a provider compatibility matrix, price floors, or unrelated UI work.

Historical phase-by-phase implementation detail remains available in Git history and the task-specific architecture/operations documents. A green repository does not mean Horus is already live.
