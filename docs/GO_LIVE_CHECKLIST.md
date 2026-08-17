# Horus Media Go-Live Checklist

This checklist is profile-aware. A repository check does not substitute for live production evidence. The authoritative current verdict is `FINAL_LAUNCH_READINESS.md`.

## Common Gate 1 — Release

- [x] PHP 8.2 / 8.3 / 8.4 repository test matrix exists.
- [x] MySQL 8 fresh migration + full suite exists.
- [x] MySQL latest migration rollback/reapply is part of Task 42 CI.
- [x] SQLite fresh + latest rollback/reapply is validated by production release workflow.
- [x] Loader browser tests, npm audit, Vite/Prebid/Loader production build are release gates.
- [x] Production Composer install, static analysis, release validation, secret guards, and archive build are release gates.
- [x] Static Edge deterministic snapshot workflow exists.
- [ ] Record the exact production release SHA, artifact checksum, artifact ID, and release owner.

## Common Gate 2 — Infrastructure

- [ ] `app.horusmedia.net` DNS/routing reaches the Laravel production public root.
- [ ] `cdn.horusmedia.net` is the intended Cloudflare Pages custom domain.
- [ ] TLS is valid on all production origins.
- [ ] Production MySQL and least-privilege credentials are configured.
- [ ] Production `APP_KEY` is stable and private.
- [ ] `APP_DEBUG=false` is externally verified.
- [ ] SMTP delivery is verified.
- [ ] Scheduler heartbeat is verified.
- [ ] Production database cache lock is verified.
- [ ] GitHub/Cloudflare deployment credentials are exercised by one controlled production deployment without exposing values.

## Common Gate 3 — Security / recovery

- [ ] First Admin bootstrap and TOTP are verified.
- [ ] Security headers are checked on the live origin.
- [ ] No credential file is in a public document root.
- [ ] Database backup is current.
- [ ] Private storage is in backup scope.
- [ ] An isolated restore drill succeeded.
- [ ] Rollback owner, incident owner, and escalation path are named.
- [ ] Live tenant-isolation smoke checks pass.

## Profile A — Public Publisher Application

**Repository decision:** READY WITH EXTERNAL EVIDENCE.

- [x] Public registration route and application-only identity model are implemented.
- [x] Email verification and applicant-only authorization are enforced.
- [x] Legal evidence is versioned.
- [x] HMP and HMS are reserved before approval without creating a Site.
- [x] Application verification requires both real Horus DIRECT ads.txt records.
- [x] Verified website evidence can feed THOTH without exposing HMP/HMS to the model.
- [x] THOTH remains advisory; human Admin decision is authoritative.
- [x] Approval does not create Site, placement, serving config, static deployment, or monetization activation.
- [ ] Production SMTP proves verification/decision delivery.
- [ ] If Turnstile is enabled, production hostname + Siteverify pass.
- [ ] One real application publishes and verifies both HMP/HMS ads.txt records.
- [ ] Root `horusmedia.net/sellers.json` live origin is validated.
- [ ] If THOTH is enabled, one real provider credential/model connection test passes.

## Profile B — GAM-backed Publisher monetization

**Repository decision:** READY WITH EXTERNAL EVIDENCE.

- [x] Approved/onboarded Publisher and Site lifecycle are implemented.
- [x] Supply-chain HMP/HMS, ads.txt, sellers.json, and SChain contracts are tested.
- [x] `HORUS_GAM` remains the established GAM path.
- [x] Prebid `GAM_BRIDGE` is optional and independently controlled.
- [x] Direct JS is optional and independently controlled.
- [x] Edge kill switches and static rollback are tested.
- [ ] Real selected GAM connection/network/permissions are eligible.
- [ ] Real Publisher domain authorization and live `/ads.txt` pass.
- [ ] Live root sellers.json and site SChain are cross-consistent.
- [ ] Permanent Loader runs on the real Publisher hostname.
- [ ] Browser network evidence shows direct GPT/GAM traffic and no ad traffic proxy through Laravel.
- [ ] Privacy/CMP live evidence is current where applicable.
- [ ] Real reporting import, reconciliation, rollback drill, and Finance sign-off pass.

Prebid and Direct JS are **not** mandatory when unused.

## Profile C — GAM-less `HORUS_DIRECT` Publisher monetization

**Repository decision:** READY WITH EXTERNAL EVIDENCE.

- [x] `HORUS_DIRECT` requires no GAM connection or fake network code.
- [x] Standalone Prebid can operate without GAM.
- [x] Direct JS can operate without GAM or a Prebid auction.
- [x] One physical slot has one rendering owner at a time.
- [x] Pure GAM-less browser profiles are tested for zero GPT/GAM requests.
- [x] GAM/PREBID/DIRECT_JS global controls are independent under AD_SERVING master control.
- [ ] Real Publisher domain and permanent Loader deployment are verified.
- [ ] Real bidder/provider IDs and reviewed provider tags are configured only for engines used.
- [ ] Live ads.txt/sellers.json/SChain requirements are satisfied.
- [ ] Live privacy probe passes.
- [ ] Source-aware reporting import/reconciliation passes.
- [ ] Static CDN rollback and Operations/Finance sign-off pass.

**Do not add GAM as a Profile-C gate.**

## Profile D — Direct Advertiser Campaign delivery

**Repository decision:** NOT READY until a production GAM campaign backend is externally verified.

- [x] Current campaign delivery truth is GAM-backed.
- [x] `HORUS_DIRECT`, standalone Prebid, and Direct JS are not represented as advertiser campaign backends.
- [x] `advertiser_campaigns.enabled` is a high-impact runtime control.
- [x] Drafts may exist without capability.
- [x] Submit/approve/schedule/activate/resume/deploy fail closed without `AVAILABLE` capability.
- [x] Deployment repeats capability validation before external GAM writes.
- [x] Pause/complete remain safe lifecycle actions.
- [ ] Product owner intentionally records the production feature-flag state.
- [ ] A real selected GAM campaign network/connection is eligible and healthy.
- [ ] Planner/inventory mappings are verified against production IDs.
- [ ] Controlled preview/dry-run then external GAM campaign deployment succeeds idempotently.
- [ ] Retry and emergency remote pause are proven.
- [ ] Advertiser reporting/invoice and Publisher-side revenue reconciliation pass.
- [ ] Rollback plus Ad Ops/Finance sign-off pass.

**Do not claim no-GAM advertiser campaign serving. No such backend exists in current architecture.**

## Final go/no-go rule

A profile may be enabled only when its Common gates and its own external evidence are complete. A green repository does not make another profile ready and does not mean Horus is already live. Record production evidence in the matrix in `FINAL_LAUNCH_READINESS.md` using `VERIFIED`, `NOT_VERIFIED`, `NOT_APPLICABLE`, or `FAILED`.
