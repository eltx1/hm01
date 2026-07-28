# Roadmap

## Phase 0 — Foundation

Laravel, MySQL/environment configuration, health endpoint, secure sessions,
CSRF, exceptions, structured logs, database queue/scheduler, audit records,
responsive admin shell, tests, and portable deployment documentation with a
Hostinger profile.

## Phase 1 — Identity and tenancy

Organizations, users, invitations, MFA, roles and permissions, organization
scoping, administrator cross-tenant access, policies, and audit coverage.

Status: complete. Organization isolation, system RBAC, invitations,
authentication, password reset, email verification, account states, session
invalidation, audited impersonation, TOTP with recovery codes, account/contact
management, white-label branding, dashboard shells, and secure administrator
bootstrap are implemented.

## Phase 2 — Publisher inventory

Status: complete for onboarding and website control-plane scope. Publisher
contracts, encrypted payment profiles, seven-step onboarding, websites, stable
public site keys, authorized domains, four verification methods, review/status
workflows, placement planning, revenue-share controls, notes, and complete
serving/status histories are implemented. `HORUS_GAM` is the database and
application default with no activation blocker. MCM and Publisher GAM remain
optional.

## Phase 3 — Loader and configuration delivery

Versioned CDN publication, permanent loader, configuration schema, atomic
promotion and rollback, browser telemetry limited to operational needs, GPT
integration, and `PAUSED` behavior.

## Phase 4 — GAM control-plane integration

Credential vaulting, `HORUS_GAM` account setup, dry-run plans, idempotent GAM
writes, reconciliation, ad-unit mapping, and audit trails. Add optional MCM and
Publisher GAM adapters behind the same connector interface.

## Phase 5 — Prebid and optional demand

Pinned Prebid build pipeline, consent integration, bidder configuration,
timeouts, targeting, native connector contract, and controlled demand rollout.

## Phase 6 — Direct sales

Advertisers, campaigns, creatives, approvals, pacing, GAM synchronization, and
financial terms. All external writes remain dry-runnable and idempotent.

## Phase 7 — Reporting and finance

Scheduled GAM/network imports, aggregate fact model, reconciliation, dashboards,
revenue-share ledger, statements, payment approvals, exports, and corrections.

## Phase 8 — Production hardening

Load and security testing, observability, backup/restore drills, retention,
access reviews, incident runbooks, CDN failover, performance budgets, and staged
multi-provider release validation, including the Hostinger profile.
