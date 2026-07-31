# Roadmap

The implementation roadmap is complete through the application and control-plane
layers. The remaining work is production evidence and disciplined commercial
operation.

## Phase 0 — Foundation

Status: complete.

Laravel, MySQL/environment configuration, health endpoint, secure sessions,
CSRF, exceptions, structured logs, database queue/scheduler, audit records,
responsive admin shell, tests, and portable deployment documentation with a
Hostinger profile are implemented.

## Phase 1 — Identity and tenancy

Status: complete.

Organization isolation, system RBAC, invitations, authentication, password
reset, email verification, account states, session invalidation, audited
impersonation, TOTP with recovery codes, account/contact management,
white-label branding, dashboard shells, and secure administrator bootstrap are
implemented.

## Phase 2 — Publisher inventory

Status: complete.

Publisher contracts, encrypted payment profiles, seven-step onboarding,
websites, stable public site keys, authorized domains, four verification
methods, review/status workflows, placement planning, revenue-share controls,
notes, and complete serving/status histories are implemented. HORUS_GAM is the
database and application default with no activation blocker. MCM and Publisher
GAM remain optional.

## Phase 3 — Loader and configuration delivery

Status: complete.

Versioned CDN publication, permanent loader, configuration schema, atomic
promotion and rollback, operationally limited browser behavior, GPT integration,
Prebid integration, and PAUSED behavior are implemented. CDN headers and cache
rules are documented in CLOUDFLARE_SETUP.md.

## Phase 4 — GAM control-plane integration

Status: complete in code; live activation pending.

Credential reference validation, HORUS_GAM setup, dry-run plans, idempotent GAM
writes, reconciliation, ad-unit mapping, audit trails, and optional MCM and
Publisher GAM adapters are implemented. A real network, credential, permission,
and root-ad-unit smoke test remains an external gate.

## Phase 5 — Prebid and optional demand

Status: complete in code; controlled rollout pending.

Pinned Prebid builds, bidder configuration, consent settings, timeouts, targeting,
native connector contracts, direct-JS/GAM fallback, ads.txt, and aggregated
reporting are implemented. Provider approvals, real account IDs, and a staged
pilot remain external gates.

## Phase 6 — Direct sales

Status: complete in code; commercial pilot pending.

Advertisers, campaigns, creatives, approvals, budgets, targeting, GAM
synchronization, billing profiles, invoices, delivery reports, and financial
terms are implemented. A real advertiser, approved creative, bounded budget,
and finance sign-off remain external gates.

## Phase 7 — Reporting and finance

Status: complete in code; reconciliation and payment operations pending.

Scheduled GAM/network imports, aggregate facts, reconciliation, dashboards,
revenue-share calculations, statements, adjustments, partial payments,
advertiser reports, invoice balances, exports, and corrections are implemented.
Real source reports, agreed variance thresholds, payment approvals, and a
repeatable restore-backed finance review remain external gates.

## Phase 8 — Production hardening

Status: operational readiness in progress.

Load/security testing, observability, backup/restore drills, retention, access
reviews, incident runbooks, CDN failover, performance budgets, and staged
multi-provider release validation are tracked in
SECURITY_OPERATIONS.md and GO_LIVE_CHECKLIST.md.

## Phase 9 — Controlled pilot and scale

Status: next execution phase.

Run one publisher and one capped advertiser campaign using PILOT_RUNBOOK.md.
Exit after seven stable days, reconciled reporting, no high-severity findings,
reproducible statements/invoices, current backup/restore evidence, and written
publisher/advertiser/finance sign-off. Only then expand demand, publishers,
provider connections, and payment volume.
