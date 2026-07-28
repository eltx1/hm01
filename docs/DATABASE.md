# Database

## Production and test engines

Production uses MySQL through Laravel's PDO driver. Tests use in-memory SQLite
where behavior is portable. MySQL-specific behavior must receive an integration
test before the related feature ships.

## Foundation tables

Laravel's foundation migrations provide:

- `users` and password reset tokens
- `sessions`
- `cache` and cache locks
- `jobs`, job batches, and failed jobs

The Horus foundation adds `audit_logs` with ULID primary keys, optional
organization, actor and subject identifiers, event name, request metadata,
before/after values, metadata, and creation time.

## Tenant isolation

Business tables carry an `organization_id`. Publisher and advertiser models
apply an authenticated organization global scope; Horus Media administrators
are the explicit cross-organization exception. Other application queries must
be scoped by explicit scopes, policies, or repositories. Unique constraints should generally include
`organization_id`. Cross-organization administration must be an explicit,
authorized, audited path.

`audit_logs.organization_id` is nullable only for platform-wide and pre-
authentication events.

## Identity and account tables

Phase 1 adds `organizations`, organization-bound `users`, `roles`,
`permissions`, `role_permissions`, `user_roles`, `user_invitations`,
`login_events`, `publishers`, `publisher_contacts`, `advertisers`, and
`advertiser_contacts`. Users and account records use soft deletion where a
recoverable lifecycle is required. Invitations store only a SHA-256 hash of a
256-bit random, single-use token and expire after 48 hours.

Administrator two-factor fields are encrypted through Laravel casts. They
establish storage for a future challenge flow; a complete TOTP/WebAuthn
challenge is a separate security task.

## Identifier and time conventions

- Prefer ULIDs for internal externally visible records.
- Use stable random public keys distinct from database IDs for loader URLs.
- Store timestamps in UTC and present them in user-selected time zones.
- Store money in integer minor units plus ISO 4217 currency.
- Store percentages as fixed-precision decimals, never floating point.
- Store source identifiers as strings to avoid upstream integer-width coupling.

## Reporting storage

Later reporting migrations will store imported, aggregated dimensions and
metrics only. No raw ad requests, bidstream, or visitor event logs belong in
the Laravel database. Imports should use source, report date, dimension key, and
import version for idempotent upserts.

## Migrations and retention

Migrations are forward-only in production and run with `php artisan migrate
--force`. Backups are required before material schema changes. Audit retention
defaults to seven years (2,555 days) and must be reviewed against applicable
legal requirements before launch.
