# Horus Media Control Plane — Launch Readiness Audit

Date: 2026-08-10

Scope: merged Tasks 0–11 plus Task 12 hardening. This audit reviews the current implementation directly and treats prior task claims as untrusted until corroborated by code, adversarial tests, or the final CI matrix.

## Release recommendation

**PENDING FINAL TASK 12 CI.** The code review found no unresolved BLOCKER or CRITICAL defect. Three repository-scope hardening defects were found and remediated in Task 12. Final recommendation is issued only after the Task 12 PR passes the full supported PHP/MySQL/browser/build/static/release matrix.

Severity vocabulary: BLOCKER, CRITICAL, HIGH, MEDIUM, LOW, ACCEPTED.

## Findings and evidence

| Area | Status | Tests / review performed | Finding | Severity | Remediation | Remaining risk | Release blocker |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Route / RBAC boundaries | REMEDIATED | Reviewed route providers, middleware and canonical role seeding; adversarial permission-misassignment regression added. | Global Settings and Operations/Audit routes relied on permissions but lacked an independent Horus-organization middleware boundary. | HIGH | Added `horus` middleware to Settings and Operations/Audit route groups. Test grants internal permissions to a Publisher role deliberately and proves the routes still return 403. | Normal RBAC administration remains privileged and auditable. | No |
| RBAC reseed stability | REMEDIATED | Re-ran canonical identity seeding model in adversarial test. | Task 11 settings permissions were applied by a separate seeder after `IdentityAccessSeeder`; running identity seeding alone could `sync()` roles and strip settings permissions. | MEDIUM | `IdentityAccessSeeder` now reapplies `SettingsAccessSeeder` after canonical role sync. Regression proves OperationsAdmin retains view/manage, AdOps/Finance retain view-only and Publisher retains none. | Settings permissions still intentionally live in their dedicated seeder; identity reseed now composes it safely. | No |
| Multi-tenant IDOR | PASS | Existing `CrossAccountAccessTest`, organization global scopes, site/support/finance ownership tests; direct review of notification ownership and support attachment download. | Publisher/Advertiser objects are organization scoped; notification mutations query through the authenticated user's relation; support attachment download verifies attachment-ticket graph plus ticket organization. | ACCEPTED | No defect found. | Future new routes must preserve scoped lookup/object authorization. | No |
| Finance integrity | PASS | Direct review of `PublisherPaymentService`; existing Admin finance regression suite covers eligibility, maker-checker, partial settlement, replay/idempotency, rollback and double-payout prevention. | Accounting uses integer minor units; statement/payment/profile rows are transaction locked; payment and statement currencies must match; idempotency keys and immutable settlement references prevent replay; statement balance changes only from recorded settlements. | ACCEPTED | No defect found. | External bank/payment-rail execution is intentionally outside the control plane; operators must reconcile real external references. | No |
| Payment data security | PASS | Reviewed `PublisherPaymentProfile`, finance UI/service tests and audit redaction. | Payment details and tax ID are encrypted casts and hidden; account reference is masked. Profile verification lifecycle is enforced before payout. | ACCEPTED | Audit key normalization was strengthened as described below. | Database/application key custody remains deployment responsibility. | No |
| AuditLog redaction | REMEDIATED | Nested adversarial secret test added for camelCase, kebab-case and nested payment fields. | Recursive redaction matched exact snake_case keys, allowing key-shape variants such as `clientSecret`, `access-token`, `serviceAccountJson`, `bankAccountNumber` and `taxIdentifier` to bypass matching if supplied by future callers. | HIGH | Normalize audit keys to canonical snake_case before sensitive-key matching while preserving recursive redaction. | Callers should still avoid placing unnecessary sensitive payloads in audit input. | No |
| Ads.txt SSRF | PASS | Reviewed `AdsTxtFetcher` and `RemoteUrlSafetyValidator`; adversarial test covers loopback/private/link-local/cloud metadata IPv4/IPv6, localhost, unsafe ports and unsafe schemes. Existing tests cover unauthorized redirects, private DNS, timeout/connection failure, invalid content type and oversized responses. | Fetch is restricted to verified site domains, validates all DNS answers as public, pins the validated address, disables automatic redirects, revalidates each manual redirect, blocks HTTPS downgrade and applies connection/total timeout plus response-size/redirect limits. | ACCEPTED | Added broader hostile-address regression coverage. | DNS/public-network behavior should be revalidated in production networking after deployment; no request-time dependency is added. | No |
| Supply-chain correctness | PASS | Cross-checked current IAB Tech Lab Ads.txt 1.1 material and official Ads.txt 1.0.2 empty-file guidance; reviewed sellers/schain validators and deterministic artifact tests. | OWNERDOMAIN/MANAGERDOMAIN are emitted; the existing `placeholder.example.com, placeholder, DIRECT, placeholder` record for no authorized systems is the IAB-specified placeholder and must not be removed. SupplyChain validator enforces 1.0 `complete`, ordered nodes, canonical `asi`, `sid` and `hp=1`. | ACCEPTED | No change required. | Continue monitoring finalized IAB revisions rather than implementing draft SupplyChain changes prematurely. | No |
| Loader / serving architecture | PASS | Reviewed architecture docs, permanent installation code regression, static config builder/delivery and browser suites. | `HORUS_GAM` remains default; publisher installs one permanent Horus Loader; config changes do not require integration replacement; Laravel is not in the ad-request path; Prebid/native run browser-side from static configuration. | ACCEPTED | No defect found. | CDN/static-delivery operational availability remains an infrastructure concern. | No |
| Click Guard | PASS | `tests/Browser/hm-loader-click-guard.test.js` reviewed and will run in final CI. | Coverage includes disabled behavior, rolling window, threshold block, block-before-GPT/Prebid/native, expired state, corrupt/denied localStorage fail-open, dynamic iframe handling and cross-tab storage-event blocking. No fingerprinting/PII path was introduced. | ACCEPTED | No change required. | Browser privacy/storage behavior can vary by user agent; design intentionally fails open when storage is unavailable. | No |
| Support security | PASS | Reviewed support routes, customer Blade and `SupportAttachmentService`; existing security tests cover XSS, internal-note isolation, ticket/resource/attachment IDOR, MIME mismatch, executable upload, private storage, state transitions and rate limiting. | Subjects are escaped; message bodies use `nl2br(e(...))`; uploads use server-detected MIME and an allowlist of PDF/JPEG/PNG/WebP/TXT/CSV with randomized private paths; SVG/HTML/script/executable types are not allowed. | ACCEPTED | No defect found. | Optional malware scanner remains environment-dependent defense-in-depth, not a correctness substitute for allowlisting. | No |
| Notification security | PASS | Reviewed notification routes/controller and existing Notification Center tests. | List/read/unread/read-all operate through the authenticated user's relation; action routes re-authorize targets; recipient generation excludes internal support/payment payloads and is deduplicated. | ACCEPTED | No defect found. | Email delivery infrastructure remains external. | No |
| Web application security | PASS | Reviewed CSRF web groups, authentication/active/verified/2FA boundaries, secure response headers, upload safety, CSV neutralization, password reset/invitation tests and output escaping. | Sensitive mutations use POST/PUT/PATCH/DELETE under `web`; authorization is server-side. No unsafe deserialization path or raw unparameterized SQL defect was identified in the reviewed control plane. | ACCEPTED | Added independent Horus guard to the highest-impact global routes. | Dependency advisories are gated by `npm audit`/Composer installation in release CI. | No |
| Operations safety | PASS | Reviewed Operations routes/service and Task 9 regressions. | Kill switches require internal organization, permissions, reason/password/confirmation as applicable; same-state replay is no-op; generic unsafe queue retry remains absent; only deduplicated static-delivery retry is exposed. | ACCEPTED | Added independent `horus` route boundary. | Operational misuse by an already-authorized high-trust administrator remains an audited human-control risk. | No |
| Settings security | PASS | Task 11 registry tests plus Task 12 permission-misassignment test. | Unknown keys, invalid types/enum/bounds are rejected; secret keys are not registered; DB rows with unknown keys are ignored; Publisher cannot reach global routes even if an internal permission is accidentally assigned. | ACCEPTED | Added Horus route boundary and identity-reseed stability fix. | High-impact supply-chain setting changes still require operational follow-through for public artifact publication. | No |
| Database integrity | PASS | Reviewed ULID FKs/uniqueness used by finance/support/settings; MySQL matrix will run `migrate:fresh --seed`. | Task 11's earlier MySQL audit-ID incompatibility was fixed before merge. Current Task 12 changes add no schema migration. Finance uniqueness/idempotency and locks protect duplicate financial records. | ACCEPTED | No new schema redesign. | Normal backup/restore and DB HA are infrastructure concerns. | No |
| Performance | PASS WITH FOLLOW-UP | Reviewed high-volume controllers: notifications, support, audit, monetization and finance. | Major lists are paginated. Monetization Center is paginated to 20 sites and does no synchronous third-party calls, but readiness performs several persisted queries per site, creating bounded per-page N+1-like cost. | LOW | No risky cache/Redis redesign in hardening PR. | Profile/batch readiness queries if a publisher commonly exceeds tens of sites. | No |
| Portability | PASS | Supported workflows target PHP 8.2/8.3/8.4, MySQL, SQLite validation, precompiled frontend/static assets; no production runtime Node/Redis/Supervisor/WebSockets/Docker requirement. | Shared/cloud deployment architecture remains compatible with Laravel scheduler/cron and prebuilt assets. | ACCEPTED | Final CI is release gate. | Queue throughput at very large scale may later justify optional process supervision, but it is not required to boot/run the product. | No |
| UI / brand | PASS | Reviewed shared layouts/BRAND_SYSTEM and newly added Settings/Monetization/Operations surfaces. | Existing navy/gold system, status badges, responsive table wrappers, labels and destructive-action confirmations remain consistent; no Task 12 redesign required. | ACCEPTED | No change required. | Continue manual device QA as browser matrix expands. | No |
| White-label leakage | PASS | Reviewed Publisher monetization payload/HTML tests and provider masking rules. | Publisher sees the Horus `Native Network` abstraction; internal provider/account/GAM/revenue/debug data is excluded. Required ads.txt/sellers/schain and browser network truth remain technically correct rather than hidden. | ACCEPTED | No change required. | Newly added integrations must pass the same publisher-output leakage tests. | No |

## Sensitive route boundaries reviewed

The audit covered global Settings, Operations/Audit, GAM, inventory/config publication, demand/campaign deployment, supply-chain compliance, reporting/finance, support internal/customer flows, notification state/preferences and Publisher monetization routes. Authorization is enforced by middleware and/or service/controller ownership checks, not by navigation visibility alone. Task 12 specifically added an organization boundary to the global Settings and Operations/Audit route groups.

## Financial integrity notes

- Monetary accounting uses integer minor units; percentage/rate calculations use basis points/micros where applicable.
- Payout creation locks the statement and checks unreserved balance.
- Maker and approver must differ.
- Verified payment-profile organization, publisher and currency must match the statement.
- Settlements lock payment and statement rows, are immutable/reference-idempotent, cannot exceed remaining payout or statement balance, and recompute statement paid/balance from settlement rows.
- A held/failed state does not itself reduce earned balance.
- Existing finance tests attempt replay, partial payment, maker-checker violations, duplicate payout and closed-period/rule-history mutation.

## Ads.txt / SSRF notes

The verifier does not allow arbitrary URLs. It derives `/ads.txt` from a verified site domain, permits only verified domains during redirect handling, validates DNS results against private/reserved ranges, pins the validated address with cURL resolve, rejects non-HTTP(S)/unsafe ports/localhost, limits redirects and bytes, and applies connection/total timeouts. Task 12 adds explicit IPv4/IPv6 hostile-address tests including `127.0.0.1`, RFC1918 space, `169.254.169.254`, `::1`, link-local IPv6 and ULA IPv6.

## IAB supply-chain review

Current IAB Tech Lab Ads.txt 1.1 guidance adds `OWNERDOMAIN` and `MANAGERDOMAIN`; Horus emits both. The official Ads.txt 1.0.2 specification explicitly defines `placeholder.example.com, placeholder, DIRECT, placeholder` for a file with no authorized advertising-system records, so Horus keeps that standards-defined line. OpenRTB 2.6 describes SupplyChain node `asi` and `sid` as required and tied to advertising-system/seller identities; Horus validates canonical domains, seller IDs, `complete`, version 1.0 and `hp=1` without guessing draft future fields.

## Serving architecture invariants

- `HORUS_GAM` remains the default serving mode.
- No Task 12 change adds a prerequisite that blocks normal HORUS_GAM activation.
- Publisher integration remains one permanent `hm-loader.js` script with a site key.
- Static configuration remains authoritative for runtime behavior.
- Ad requests do not traverse Laravel.
- Prebid auctions remain browser-side.
- Raw bid/impression/click event streams are not routed to Laravel by this work.
- Click Guard remains browser-local and does not add fingerprinting.

## Task 12 defects repaired

1. **HIGH — Internal/global route defense-in-depth:** added `horus` middleware to Settings and Operations/Audit routes.
2. **HIGH — Audit secret key-shape leakage:** normalized key names before recursive redaction and added nested camel/kebab-case tests.
3. **MEDIUM — Settings permission drift on identity-only reseed:** reapply Settings access after canonical role `sync()` and test the intended role matrix.

No unresolved BLOCKER or CRITICAL issue is known before final CI.

## Full validation gate

The Task 12 PR must pass the repository-supported commands, without inventing alternate tooling:

```bash
composer install --prefer-dist --no-interaction --no-progress
php artisan test

npm ci
npm audit --audit-level=moderate
npm run test:browser
npm run build

# MySQL CI
php artisan migrate:fresh --seed --force
php artisan test

# Production validation
find app bootstrap config database routes tests scripts -name '*.php' -print0 | xargs -0 -n1 php -l
php scripts/static-analysis.php
npm run test:browser
npm run build
php artisan migrate:fresh --seed --force
php artisan route:list
php artisan schedule:list
php artisan test
composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader
./scripts/build-release.sh
./scripts/validate-release.sh

# Static edge validation
php artisan static-delivery:build cloudflare-pages-dist
php scripts/validate-static-delivery.php cloudflare-pages-dist
```

Final CI run IDs/results and the final release recommendation will replace the pending section before merge.
