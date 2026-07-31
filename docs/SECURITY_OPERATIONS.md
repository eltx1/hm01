# Security and Recovery Operations

This guide turns the remaining production-hardening items into recurring
operational controls.

## Before every release

- Review dependency lockfiles and run Composer and npm audits.
- Run the full PHP, MySQL, browser, and production-package validation workflows.
- Inspect the release archive for secrets, .env, development files, and missing assets.
- Back up MySQL and private storage.
- Record the previous release, new commit SHA, checksum, migration list, and rollback owner.
- Confirm the CDN current-alias and versioned configuration policy.
- Announce a maintenance window when migrations or external writes are involved.

## Monthly access review

Review:

- Horus administrators and MFA enrollment;
- organization memberships, roles, invitations, and suspended accounts;
- GAM connection permissions and credential references;
- provider account assignments and approval states;
- support/finance access to payments, exports, and internal notes;
- audit-log access and retention.

Remove stale access, rotate credentials through the provider, and preserve an
audited record of the review.

## Backup and restore

Back up:

- MySQL, including failed jobs and audit records;
- private contract files and payment-related storage;
- the active release package;
- CDN loader, Prebid assets, and versioned site configurations.

A restore drill must use an isolated target. Verify migrations, login/TOTP,
tenant isolation, private downloads, static configuration publication, and a
read-only reporting query before declaring success. Never test restoration by
overwriting the only production copy.

## Incident response

1. Assign an incident owner and record UTC start time.
2. If delivery or configuration is unsafe, use the audited emergency site pause.
3. Preserve request IDs, audit IDs, GAM operation IDs, report import IDs, and relevant checksums.
4. Revoke or rotate exposed credentials.
5. Determine affected tenants, sites, campaigns, and reporting periods.
6. Restore the last known-good release or configuration where appropriate.
7. Reconcile external state before resuming traffic.
8. Record root cause, customer impact, corrective action, and follow-up owner.

Do not delete audit logs, failed jobs, operation records, or report history to
hide or simplify an incident.

## Required future hardening

Track these explicitly if they are not supplied by the hosting or edge provider:

- WebAuthn or step-up authentication for high-risk actions;
- rate limiting for authentication, exports, loader configuration, and admin writes;
- CSP, HSTS, clickjacking, and MIME-sniffing headers;
- secret scanning and dependency update automation;
- CDN origin restriction and failover;
- documented performance budgets and load tests;
- periodic least-privilege review and credential rotation.
