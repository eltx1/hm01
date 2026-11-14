# Horus platform evolution — implementation plan

Authorized by Mohamed on 2026-11-14. This extends the historical Task-42 scope in
ROADMAP.md; existing architecture and security requirements remain mandatory.

## Delivery contract

Build on Site 360, Operations, static delivery, reporting, Finance, RBAC, onboarding
and support already present. First inventory each implementation and its tests;
implement only the missing behavior. No new production daemon, Redis, Docker or
Node runtime. No advertising traffic through Laravel. Preserve GAM, standalone
Prebid and Direct JS, organization isolation, verified Cloudflare admission and
Click Guard. reCAPTCHA remains optional and observational after verified PASS.

Every task has a separate reviewable implementation and tests. Read-only UI first;
new writes require existing permission checks, audit, dry-run for external writes,
idempotency and recovery. Never infer live ad rendering from a successful save or
CDN upload. No additional privacy or page-load wait enters the ad startup chain.

## Shared design

- Site ID and organization ID identify records; existing authorization is authoritative.
- Use current configuration versions, delivery items and confirmed production pointer
  as publication evidence. Keep requested, queued and confirmed versions distinct.
- Health facts contain source, observed_at, scope and evidence state. Unknown and
  stale are explicit, separate from success/failure. Derived summaries never decide
  whether an ad is allowed.
- Diagnostics have correlation IDs without tokens, secret keys or visitor identity.
- Use existing aggregated reporting. Proposed browser timing collection must be
  sampled, bounded and aggregated before Laravel ingestion; no raw impression stream.
- Financial and referral calculations use approved ledger facts and versioned rules.

## Dependency and execution order

Wave A: 02 → 01 → 09; 04 supplies protection evidence, 05 supplies observation evidence.
Wave B: 12 → 13; 03 → 10 → 11. Diagnostics consume publication facts from Wave A.
Wave C: 06 → 07; 08 permission review applies before exposing new actions or data.
Wave D: 14 and 15 consume health evidence; 16 depends on 07, 08 and 15.

These are dependencies, not permission to ship unfinished tasks together. Release
small changes after relevant checks pass; record deployed commit separately from
implementation/test status. Never mark an entire task done from one foundation PR.

## 01 — Unified site management

Extend Site 360 with consistent summaries for installation, ownership/domain,
ads.txt, serving engines, protection, publication and reporting freshness. Reuse
existing authoritative services and permission checks; avoid duplicate controls.
Steps: map existing tabs/data → add missing summaries → link each issue to its
existing resolution screen. Depends on 02/08/09. Acceptance: active GAM-less sites
remain valid, optional engines are not marked broken, inaccessible actions stay
hidden and forbidden server-side. Tests: admin/publisher/tenant isolation, empty
sites and sites using each serving mode; responsive rendering and query budget.

## 02 — Save and publication clarity

Distinguish committed settings, latest queued production version, confirmed
production version, delivery failure and retry. Use existing delivery confirmation,
not timestamps or latest version alone. Steps: read-only summary → request/audit
correlation → consistent save responses on each settings endpoint. No automatic
resubmission of secrets after a connection drop. Depends on existing outbox.
Acceptance: queued v8 with confirmed v7 displays both; failed delivery never reads
as live; inactive sites do not claim publication. Tests: pending/uploading/deployed,
legacy published, retry, superseded, rollback, missing evidence and no-write GET.
Status: first read-only Site 360 summary implemented on this branch; remaining
save-response/correlation work pending. Production incident root cause unresolved.

## 03 — Ad lifecycle diagnostics

Inventory loader hooks and synthetic probes; define phase names for loader,
Cloudflare decision, engine readiness, ad request, fill/render and terminal error.
Keep GAM/Prebid/Direct JS outcomes distinct and distinguish no-fill from failure.
Steps: local bounded debug trace → diagnostic export → aggregate integration only
under 11's contract. Depends on 02/08. Acceptance: enabling diagnostics changes no
ad decision, request order or renderer ownership. Tests: PASS/deny/timeout, no-fill,
provider failure, independent engines, Android and browser navigation lifecycle.

## 04 — Protection visibility

Extend Operations with aggregate Cloudflare and Click Guard outcomes by site,
device family and browser, using existing evidence before adding collection.
Steps: inventory reason codes → unknown/stale coverage → trend and abnormal-change
views. Never label blocked traffic proven fraud or change rules automatically.
Depends on 08/09; timing evidence comes from 11. Acceptance: no token/IP exposure,
no client-side PASS substitution, no added verification request. Tests: reason-code
mapping, denominator/coverage correctness, isolation and unchanged runtime tests.

## 05 — reCAPTCHA observation status

Show saved activation and published key-match separately. Runtime execution and
Google dashboard score are distinct evidence; never invent scores without Google
verification/reporting access. Steps: configuration evidence → optional bounded
local diagnostic phase → admin help. Depends on 02/08. Acceptance: secret stays
server-side; default off; only verified PASS schedules work; failure cannot delay
or block ads; publisher CAPTCHA remains untouched and Horus badge stays hidden.
Tests: disabled, non-PASS, key mismatch, provider timeout, badge scope and no secrets.

## 06 — Trustworthy reports

Map each existing source, import and reconciliation job. Add source, last successful
sync, timezone, currency, provisional/final status and discrepancy explanation.
Steps: freshness contract → report UI → reconciliation drill-down. Depends on 08.
Acceptance: partial or stale data never appears final; no double counting across
engines; original report totals preserved. Tests: delayed/partial imports, timezone
boundaries, currency mismatch, duplicate import and source-scoped permissions.

## 07 — Earnings and payment clarity

Audit current ledger and payment workflow; display estimated, approved, payable,
paid and adjustments using existing definitions. Add adjustment reasons and source
links before changing calculations. Depends on 06/08. Acceptance: balances reconcile
with ledger, payment retries cannot double pay, historical finality is retained.
Tests: ledger invariants, rounding, reversals, partial payments, duplicate requests,
restricted finance access and publisher isolation. No new payment processor.

## 08 — Task and site permissions

Map existing roles against all proposed screens/actions. Add site assignment only
where current RBAC cannot express it; default deny for new sensitive actions.
Steps: permission matrix → query scope → audited assignment changes. Acceptance:
existing valid roles retain access, a site assignment cannot bypass organization
membership, server authorization protects exports and writes. Tests: role/tenant/
site combinations, revoked assignment, direct URL/API access and audit redaction.

## 09 — Site health center

Compose publication, installation, ads.txt, protection and reporting facts into
an evidence-backed queue in existing Operations. No single vague health score.
Steps: per-site facts → severity/action mapping → paginated cross-site triage.
Depends on 01/02/08; consume 04/06/10 as delivered. Acceptance: unknown/stale is
visible, each issue has source/time/action, optional services are not failures.
Tests: empty/stale/conflicting facts, tenant visibility, pagination and bounded
queries. Manual review precedes remediation; no new ad gate.

## 10 — Check my site

Extend existing synthetic checks with a user-requested page URL and readable result.
Steps: reuse URL/origin guard → bounded fetch/check → support-safe report. Depends
on 03/08/09. Allow public HTTP(S) targets only with DNS/address validation at each
redirect, private/link-local/metadata destinations blocked, strict size/time limits
and redacted output. No protection bypass, ad clicks or artificial revenue events.
Acceptance/tests: SSRF redirects and rebinding, huge/slow responses, authentication
walls, duplicate checks, installation errors and device-specific observations.

## 11 — Real visitor speed measurement

First define timing and sampling budget; reuse browser performance and current
observability hooks. Steps: opt-in sampled measurement → edge-side aggregation →
existing aggregate ingestion → p50/p75/p95 by bounded device/browser categories.
Depends on 03/08. Store no raw impression/click streams in Laravel; no visitor IDs,
tokens or page query strings. Acceptance: no awaited network work in ad startup,
bounded queue/cardinality, explicit sample coverage; defer release if aggregation
infrastructure cannot satisfy these requirements. Tests: offline/slow/hanging
collector, tab backgrounding, Android, duplicate phase marks and latency comparison.

## 12 — Configuration history and recovery

Extend existing versions/rollback: human-readable allowlisted diff, actor, reason,
queued/confirmed status and preview. Steps: read-only history → dry-run rollback →
existing authorized/audited rollback with current safety validation. Depends on
02/08. Never reactivate a removed unsafe provider or weaken current protection
through an old payload. Acceptance: rollback produces a new version and follows
normal delivery. Tests: secret redaction, unsafe historic config, duplicate request,
concurrent edits, failed delivery and supported engine compatibility.

## 13 — Gradual releases

Use explicit internal/pilot sites and feature flags after inventorying existing
release tooling. Steps: test-only cohorts → manual canary release → comparison and
recovery controls. Depends on 12 and existing deployment checks. Never randomly
change serving behavior mid-page; pin a configuration per page session. Acceptance:
default behavior preserved, tenant cannot self-enable an internal experiment,
rollback is tested. Tests: cohort isolation, CDN cache/version selection, stale
clients, mixed release versions and bad-canary recovery. No always-on worker.

## 14 — Site-linked support

Extend current support tickets with site linkage and a minimal diagnostic snapshot:
configuration version, publication state, observation time and sanitized reason.
Steps: define allowlist → permission-aware attachment → staff/publisher views.
Depends on 08/09/10. Acceptance: site ownership verified, secrets/tokens never
attached, publisher cannot see internal staff notes. Tests: cross-tenant IDs,
snapshot redaction, stale evidence, attachments and existing ticket notifications.

## 15 — Publisher onboarding progress

Map current application, ownership, review, ads.txt and installation stages into a
clear next-action view with responsible party. Depends on 01/08/09. Steps: derive
state from existing records → progress UI → safe reminders through existing
notification preferences. Do not invent eligibility gates or require MCM/Publisher
GAM for Horus-managed or GAM-less profiles. Tests: every current serving profile,
rejected/reopened application, domain change, repeated steps and role visibility.

## 16 — Partners and referrals

Inventory existing partner/commission models first. Link authorized referral to
publisher, track onboarding and use approved revenue under versioned commission
rules. Depends on 07/08/15. Steps: attribution rules → read-only approved earnings
preview → audited commission approval/export. No retroactive attribution without
review and no payouts from estimated revenue. Acceptance: reversals reconcile,
no duplicate commission, partners see only their own authorized data. Tests:
duplicate referral, ownership dispute, rule changes, refunds, FX and payout retry.

## Release checks and recovery

1. Record baseline and changed files; no unrelated runtime changes in UI releases.
2. Run targeted unit/feature tests plus repository-required CI on the exact commit.
3. Check permission/secret handling, current engines and relevant browser coverage.
4. For runtime tasks compare cold/warm and Android timings with the feature off/on;
   agree measured overhead budget before rollout, not after collecting live data.
5. Use normal protected production path. Record deployment, migration and CDN
   evidence separately. Do not remove environment restrictions to run diagnostics.
6. Feature flags recover additive behavior; schema additions remain compatible.
   Financial changes require reconciliation before activation.

## Current execution record

- Baseline: main 4b4d211 (existing speed and reCAPTCHA releases).
- Working branch: feat/platform-health-foundation.
- Started: task 02, read-only publication evidence in existing Site 360.
- Started: task 09 foundation in the existing paginated website list, reading only
  stored monitor outcomes with explicit current/stale/unknown/inactive evidence.
  A one-hour freshness window covers four scheduled monitor intervals; one recent
  record cannot hide older or undated records. No monitor or notification runs on GET.
- Tasks 01 and 03–08 / 10–16: planned, not implemented by this change.
- Each follow-up must update this record with exact test and deployment results.
