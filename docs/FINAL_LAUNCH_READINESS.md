# Horus Media Final Launch Readiness Audit

**Audit date:** 2026-08-17  
**Audited starting `main`:** `c358796c91e8b28045a1b80e8e13dd7f50c4d476`  
**Latest merged PR at remediation start:** #68 — Task 42 final launch readiness audit  
**Remediation branch:** `task-43-public-application-fail-closed`

This is the current launch-readiness authority for the repository. Older completion and launch reports remain historical evidence; they are not the current production verdict.

Repository implementation and CI can prove code contracts. They cannot prove DNS, TLS, production credentials, a live Publisher `/ads.txt`, a real GAM network, a real reporting import, or a successful restore drill. Those remain external production evidence until explicitly recorded.

## Current repository inventory

- 42 migration files; latest: `2026_08_16_181500_add_dual_horus_seller_identity_and_application_ads_txt_verification.php`.
- Horus Loader source version: `2.0.0`.
- Pinned Horus Prebid build: `11.14.0`.
- Static delivery manifest schema: `1`.
- Static global control schema: `2`.
- Static delivery health schema: `1`.
- Site runtime config remains versioned through `ConfigVersion` and the static-delivery contract.
- Production release workflow: `.github/workflows/production-release.yml`.
- Main test workflow: `.github/workflows/tests.yml` with PHP 8.2/8.3/8.4, MySQL 8, browser tests, npm audit, and production build checks.
- Task 42 adds MySQL latest-migration rollback/reapply to the main test workflow; SQLite already performs fresh/rollback/reapply in production release validation.

## Current standards check

The audit rechecked primary/current sources rather than relying on task-era summaries:

- IAB Tech Lab ads.txt / sellers.json: https://iabtechlab.com/ads-txt/
- IAB OpenRTB SupplyChain Object: https://github.com/InteractiveAdvertisingBureau/openrtb/blob/main/supplychainobject.md
- IAB OpenRTB 2.6: https://github.com/InteractiveAdvertisingBureau/openrtb/blob/main/OpenRTB%20v2.6.md
- Prebid Supply Chain / ORTB2: https://docs.prebid.org/dev-docs/modules/schain.html
- Google publisher privacy/CMP requirements: https://support.google.com/admanager/answer/13554116
- Cloudflare Turnstile server-side validation: https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
- OpenAI Responses / structured outputs: https://platform.openai.com/docs/guides/structured-outputs
- Gemini structured outputs: https://ai.google.dev/gemini-api/docs/structured-output

No current standard found requires a redesign of Horus. The critical identity interpretation remains valid: one paid seller entity may have multiple seller/account IDs in one advertising system, but those IDs are not separate payment-chain hops. Horus therefore publishes HMP and HMS as two Horus identities for one Publisher entity and uses the site-specific HMS as the single Horus SupplyChain node for a Task-39 website transaction.

## Repository-verified architecture

### Authentication, authorization, and lifecycle

The suite covers customer login, dedicated staff login, email verification, password reset, TOTP/2FA, invitations, active-account middleware, applicant-only access, RBAC, organization scopes, cross-account denial, and audited impersonation. Public Publisher approval is a human Admin action and does not create a Site or activate serving.

### Public Publisher application

Repository flow:

public-registration feature switch -> fail-closed Publisher Application readiness -> `/register/publisher` -> configured Turnstile when enabled -> email verification -> applicant-only portal -> application profile/legal evidence -> HMP reservation -> website HMS reservation -> both real Horus DIRECT ads.txt records -> Horus verification -> trusted verified-domain THOTH evidence -> advisory -> human Admin decision -> existing Publisher onboarding.

Application approval does **not** automatically create a Site, placement, serving config, static ad-serving deployment, or monetization activation.

Public Publisher Application readiness is a bounded `READY` / `BLOCKED` contract. Every required legal document must have a non-blank current version and canonical HTTP(S) URL. Required legal configuration cannot disappear from enforcement when incomplete. When public registration is enabled but readiness is `BLOCKED`, new registration fails closed before Organization, Publisher, User, or Publisher Application rows are created. Existing accounts and invitations remain unaffected.

When Turnstile is enabled in production, readiness requires the real Cloudflare provider path plus site key, server secret, expected hostname, and action. The verifier independently fails closed in production if a fake/deterministic provider or required server-side verification configuration is invalid; deterministic fake behavior remains test/local-only.

### HMP/HMS dual identity

Verified invariants:

- one Publisher-level managed HMP per Publisher seller entity;
- one Website-level managed HMS per Website identity;
- multiple Sites share the HMP but have distinct HMS values;
- managed IDs are immutable and non-recyclable and do not encode DB IDs;
- HMP/HMS are not User IDs and are not `Site.public_key`;
- application verification requires the exact real records `horusmedia.net, HMP-..., DIRECT` and `horusmedia.net, HMS-..., DIRECT`;
- reservation, registration, email verification, profile completion, or submission alone never makes reserved HMP/HMS publicly eligible;
- reserved HMP/HMS remain internal and permanently stored until the corresponding **current** Publisher Application domain claim has successfully passed the real Task-39 ads.txt verification and is `CLAIMED` + `VERIFIED`;
- only then may those still-disabled pre-approval identities appear in public sellers.json as confidential entries without private applicant name/domain data;
- if the verified authorization is lost through verification failure, claim release, rejection, or withdrawal, the reserved declarations remain permanently stored but are removed from public sellers.json eligibility;
- after review/activation, HMP and each HMS resolve to the same legitimate Publisher legal name/domain;
- the site SupplyChain node uses `asi=horusmedia.net`, `sid=HMS-...`, `hp=1`;
- HMP and HMS are never emitted as sequential SChain nodes.

### Canonical ads.txt and supply chain

The canonical site composition uses the controlled union of Horus seller authorization, Platform Master records, Prebid bidder records, Direct Demand records, and reviewed canonical sources. The composer performs deterministic ordering, exact-duplicate collapse, conflict reporting, provenance retention, OWNERDOMAIN handling, conditional MANAGERDOMAIN handling, and safe empty behavior.

A successful Task-39 application-domain transition into current `CLAIMED` + `VERIFIED` eligibility automatically queues the existing global Supply Chain static publication through the Static Delivery outbox and normal batching path. Repeated `VERIFIED -> VERIFIED` checks do not create a new eligibility event. A real authorization removal is queued through the same outbox as an urgent safety revocation. Verification-state persistence, audit evidence, and the outbox trigger are transaction-safe so a rolled-back authorization transition cannot leave a surviving publication request.

Repository generation does **not** prove that a Publisher's live public `/ads.txt` or root `horusmedia.net/sellers.json` currently serves the generated payload. Those are external production checks.

### Publisher serving profiles

`HORUS_GAM` remains the default GAM-backed path. `HORUS_DIRECT` has no required GAM connection. GAM, standalone Prebid, and Direct JS are independent serving engines; Prebid and Direct JS may coexist on different placements. One physical DOM surface has one rendering owner at a time. Pure GAM-less profiles are browser-tested not to create GPT/GAM requests.

The global static edge contract exposes `adServingDisabled`, `gamDisabled`, `prebidDisabled`, and `directJsDisabled`; AD_SERVING is the master stop. Engine controls remain independent and urgent safety publication bypasses normal batching.

### Static delivery

Repository tests cover 30-minute normal boundaries, no-change no-op, manifest dedupe, budget and emergency reserve, Deploy Now, locking, retry, rollback, file limits, checksum/secret guards, public payload validation, and urgent safety publication. Deploy Now with an empty queue remains an audited no-op. Task 43 reuses this same outbox, batching, priority, retry, manifest/checksum, budget, and driver abstraction for reserved HMP/HMS publication and revocation; it does not create a parallel deployment path.

### Reporting and finance

Bid price and Prebid estimates are not realized payable revenue. `PREBID_ESTIMATES` is forced to estimated finality and cannot become settlement eligible or independently generate payout-ready monthly reports/statements. Finalized-capable sources require explicit financial binding, successful imports, reconciliation, period close, and payout readiness. HMP/HMS are supply-chain identities and do not replace the financial reporting-account binding.

### Direct Advertiser Campaigns

Current campaign delivery is truthfully GAM-backed. `HORUS_DIRECT`, standalone Prebid, and Direct JS are **not** advertiser campaign backends. `advertiser_campaigns.enabled` controls the pilot. Drafts can exist without delivery capability; submit, approve, schedule/activate, resume, and deployment require `CampaignDeliveryCapabilityService` to return `AVAILABLE`. Deployment repeats the check before external GAM writes. Pause/complete remain safe lifecycle actions.

### THOTH

OpenAI uses the Responses API with strict JSON Schema and `store=false`. Gemini uses structured JSON output via `generateContent`. Both providers are tool-less advisory adapters; output is locally schema-validated. Website evidence is fetched only after current Task-39 ads.txt authorization, with SSRF/DNS/redirect/size safeguards and static-text sanitization. THOTH cannot approve or reject; human decisions remain authoritative and review runs are immutable.

### Privacy and security

Repository contracts cover TCF/GPP/GPC-facing runtime behavior, Prebid consent/storage/activity controls, explicit one-shot diagnostics, zero normal-boot diagnostic telemetry, Google CMP evidence states, CSRF, throttles, CSP/HSTS/clickjacking/nosniff/referrer/permissions headers, secret redaction, CSV safety, SSRF safety, public-static secret guards, audit logging, production debug configuration, fail-closed required Publisher legal configuration, and production Turnstile provider/configuration enforcement. Technical readiness is not a legal-compliance certification.

## Targeted final contract evidence

The following Task-42/43 requirements are explicitly mapped to executable tests:

1. one Publisher -> one HMP — `DualHorusSellerIdentityTest` and `FinalLaunchReadinessContractTest`;
2. two websites -> two different HMS — `FinalLaunchReadinessContractTest`;
3. both websites share the same HMP — `FinalLaunchReadinessContractTest`;
4. Site A ads.txt contains HMP + HMS-A — `FinalLaunchReadinessContractTest`;
5. Site B ads.txt contains HMP + HMS-B — `FinalLaunchReadinessContractTest`;
6. sellers.json maps HMP/HMS-A/HMS-B consistently — `FinalLaunchReadinessContractTest`;
7. Site A SChain uses HMS-A — `FinalLaunchReadinessContractTest`;
8. Site B SChain uses HMS-B — `FinalLaunchReadinessContractTest`;
9. HMP and HMS are not sequential SChain nodes — `DualHorusSellerIdentityTest` and `FinalLaunchReadinessContractTest`;
10. application verification requires real ads.txt records — `DualHorusSellerIdentityTest`;
11. unverified reserved HMP/HMS never enter sellers.json, including during unrelated global publication — `DualHorusSellerIdentityTest` and `Task43PublicApplicationFailClosedTest`;
12. successful real verification makes reservations publishable and queues normal global Supply Chain publication idempotently — `Task43PublicApplicationFailClosedTest`;
13. verification loss, rejection, withdrawal, and direct claim release remove public eligibility and use urgent revocation while preserving identity records — `Task43PublicApplicationFailClosedTest`;
14. rolled-back claim authorization leaves no static publication request — `Task43PublicApplicationFailClosedTest`;
15. required legal version/URL failures block new public registration before partial rows and are visible to Admin — `Task43PublicApplicationFailClosedTest`;
16. exact current legal acceptance remains version-bound — `Task43PublicApplicationFailClosedTest` plus existing Publisher Application tests;
17. production fake Turnstile fails closed while deterministic testing remains available outside production — `Task43PublicApplicationFailClosedTest`;
18. verified application domain feeds THOTH — `ThothPreApprovalWebsiteEvidenceTest`;
19. THOTH cannot approve — `ThothPreApprovalWebsiteEvidenceTest` and `ThothPublisherQualityAdvisorTest`;
20. human application approval creates no Site — `PublicPublisherApplicationTest` and `DualHorusSellerIdentityTest`;
21. GAM-less Site sends zero GAM requests — Loader browser tests plus `GamLessPilotReadinessTest` / `GamOptionalServingModelTest`;
22. advertiser campaign without eligible GAM cannot deploy — `AdvertiserCampaignCapabilityGuardrailTest`;
23. PREBID_ESTIMATES cannot create payout-ready revenue — `ProviderFinancialSourceIntegrityTest`;
24. Master ads.txt changes trigger static publication — `AutomaticSupplyChainStaticPublicationTest` / `SupplyChainPublicationTriggerTest`;
25. no cross-tenant leak — organization/cross-account/supply-chain/application tests.

## Four independent launch decisions

### PUBLIC PUBLISHER APPLICATION — READY WITH EXTERNAL EVIDENCE

Repository flow and security boundaries are implemented and tested. The production configuration itself must also resolve the runtime Publisher Application readiness contract to `READY` before public registration is enabled. Public Publisher Registration remains OFF by default.

External blockers before public production enablement:

- production `app.horusmedia.net` DNS/TLS/routing;
- production MySQL/APP_KEY/APP_DEBUG=false/cache lock/scheduler;
- configured current required Terms of Service, Privacy Policy, and Publisher Terms versions and canonical URLs;
- SMTP delivery for verification and decisions;
- production Turnstile real-provider/site-key/secret/hostname/action validation if Turnstile is enabled;
- Admin TOTP/bootstrap evidence;
- backup + isolated restore evidence;
- at least one real HMP/HMS application ads.txt verification;
- root production `horusmedia.net/sellers.json` origin validation;
- real THOTH provider credential/model connection if THOTH is enabled for the flow.

### GAM-BACKED PUBLISHER PILOT — READY WITH EXTERNAL EVIDENCE

Repository architecture and regression suite are ready. External blockers:

- a real eligible production GAM connection/network and permissions;
- live Publisher domain authorization and real `/ads.txt`;
- public root sellers.json/SChain consistency;
- Loader deployed on the real Publisher hostname with browser-direct GPT evidence;
- live privacy/CMP evidence where required;
- real reporting import, reconciliation, finance sign-off;
- static CDN deployment evidence and rollback drill.

### GAM-LESS HORUS_DIRECT PILOT — READY WITH EXTERNAL EVIDENCE

Repository architecture proves no mandatory GAM dependency and preserves independent standalone Prebid/Direct JS ownership. External blockers:

- real Publisher domain and Loader deployment;
- real bidder/provider IDs and reviewed provider tags for the engines actually used;
- live ads.txt/sellers.json/SChain where applicable;
- live privacy probe;
- source-aware reporting import and reconciliation;
- static CDN publication/rollback evidence;
- operations and finance sign-off.

GAM is explicitly **not** a Profile-C prerequisite.

### ADVERTISER CAMPAIGN PILOT — NOT READY

Repository code is ready to enforce the current GAM-backed capability truth, but Task 42 requires this profile to remain NOT READY until the required production GAM campaign backend is externally verified. Required evidence:

- intentional production state of `advertiser_campaigns.enabled`;
- real selected GAM connection/network capability for a pilot campaign;
- real campaign planner/inventory mappings;
- dry-run/preview and controlled external GAM campaign deployment;
- idempotency/retry evidence and emergency remote pause;
- advertiser reporting/invoice and publisher-side reconciliation;
- rollback and operations/finance sign-off.

No no-GAM advertiser campaign backend exists in current architecture.

## External production evidence register

`VERIFIED` below is reserved for actual production evidence, never CI substitutes. At audit time no production evidence package was supplied to this repository audit.

| Item | Status | Owner | Evidence required | Last verified | Notes |
|---|---|---|---|---|---|
| app.horusmedia.net DNS/routing | NOT_VERIFIED | Platform Ops | DNS + live routing capture | — | Repository config is insufficient |
| cdn.horusmedia.net Cloudflare Pages custom domain | NOT_VERIFIED | Platform Ops | Pages custom-domain + live origin evidence | — | Must not be inferred from workflow |
| Production TLS | NOT_VERIFIED | Platform Ops | live certificate/HTTPS evidence | — | all public origins |
| Production MySQL | NOT_VERIFIED | Platform Ops | production connection/migration evidence | — | CI MySQL is not production |
| Stable production APP_KEY | NOT_VERIFIED | Security/Ops | secret-management evidence | — | do not expose the value |
| APP_DEBUG=false | NOT_VERIFIED | Security/Ops | production config evidence | — | secret-safe proof only |
| SMTP | NOT_VERIFIED | Platform Ops | verification/reset/notification delivery | — | required for Profile A |
| Scheduler heartbeat | NOT_VERIFIED | Platform Ops | current heartbeat/job evidence | — | once-per-minute scheduler |
| Database cache lock | NOT_VERIFIED | Platform Ops | production lock-store evidence | — | static delivery concurrency |
| GitHub/Cloudflare deploy credentials | NOT_VERIFIED | Platform Ops | successful controlled deployment evidence | — | values remain secret |
| First Admin TOTP | NOT_VERIFIED | Security | enrollment/login evidence | — | no seed secret in evidence |
| Database backup | NOT_VERIFIED | Security/Ops | backup ID/timestamp | — | before production migration |
| Isolated restore drill | NOT_VERIFIED | Security/Ops | restore record | — | must not target production DB |
| Private storage backup | NOT_VERIFIED | Security/Ops | backup coverage evidence | — | contracts/uploads |
| Publisher required legal contract | NOT_VERIFIED | Legal/Ops | current versions + canonical production URLs + readiness capture | — | required before public registration is enabled |
| Turnstile production hostname/provider | NOT_VERIFIED | Security/Ops | real Cloudflare provider + hostname/action + successful Siteverify | — | if enabled |
| OpenAI/Gemini credential test | NOT_VERIFIED | Operations | provider connection test | — | if THOTH enabled |
| horusmedia.net/sellers.json public origin | NOT_VERIFIED | Ad Ops | live HTTPS body + validation | — | repository generation alone is not enough |
| Real Publisher /ads.txt | NOT_VERIFIED | Ad Ops | live Publisher origin body | — | per pilot site |
| Real HMP/HMS verification | NOT_VERIFIED | Ad Ops | canonical Task-39 verification result | — | both DIRECT records |
| Loader on real Publisher domain | NOT_VERIFIED | Ad Ops | browser network/runtime evidence | — | Profile B/C |
| Real bidder/provider IDs | NOT_VERIFIED | Ad Ops | provider account evidence | — | only engines used |
| Privacy live probe | NOT_VERIFIED | Privacy/Ops | one-shot diagnostic + CMP evidence | — | no legal conclusion implied |
| Reporting import | NOT_VERIFIED | Finance/Ops | real source import | — | aggregated data only |
| Revenue reconciliation | NOT_VERIFIED | Finance | source comparison/reconciliation | — | finalized source required |
| Rollback drill | NOT_VERIFIED | Platform/Ad Ops | controlled rollback evidence | — | site/static/campaign as applicable |
| Finance sign-off | NOT_VERIFIED | Finance | named approval record | — | before payable production activity |
| Production GAM campaign backend | NOT_VERIFIED | Ad Ops | real eligible GAM campaign deployment evidence | — | hard blocker for Profile D |

## Release recommendation

The repository may proceed to **controlled external production evidence collection** for Profiles A, B, and C only after the applicable runtime readiness/configuration gates are satisfied. This is not evidence that Horus is already live. Profile D remains NOT READY until its real production GAM campaign backend is verified.

No speculative subsystem should be added to change these decisions. If an external check fails, record it as `FAILED`, stop the affected profile, and open a separately scoped remediation task if the fix requires a new subsystem.
