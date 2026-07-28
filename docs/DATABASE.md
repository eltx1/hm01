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
be scoped by explicit scopes, policies, or repositories. Unique constraints
should generally include `organization_id`. Cross-organization administration
must be an explicit, authorized, audited path.

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
store the TOTP secret and hashed, single-use recovery codes. Horus Media
administrators must complete enrollment and a challenge before entering the
administrative control plane.

Organizations contain dashboard title, logo path, primary color, support email,
status, and Horus-only internal notes. Publisher and advertiser contacts are
separate organization-scoped records. The Horus Media organization type is
immutable and only one such organization may exist.

## Identifier and time conventions

- Prefer ULIDs for internal externally visible records.
- Use stable random public keys distinct from database IDs for loader URLs.
- Store timestamps in UTC and present them in user-selected time zones.
- Store money in integer minor units plus ISO 4217 currency.
- Store percentages as fixed-precision decimals, never floating point.
- Store source identifiers as strings to avoid upstream integer-width coupling.

## Publisher onboarding and website tables

Phase 2 adds `publisher_contracts`, `publisher_payment_profiles`, `sites`,
`site_domains`, `site_verifications`, `site_reviews`, `site_notes`,
`site_status_history`, `site_serving_settings`, and `serving_mode_changes`.
Every tenant table carries `organization_id`; route-model binding therefore
inherits the same publisher isolation as queries.

Payment account details and tax identifiers use encrypted Laravel casts.
Contract files are referenced by private-disk paths and never served directly
from the public directory. Monetary thresholds use fixed-precision decimals,
and revenue shares use `DECIMAL(5,2)`.

`sites.public_key` is a random stable loader identifier. Both the site and its
one-to-one serving settings default to `HORUS_GAM`. Serving changes increment a
configuration version and append a row with administrator, reason, timestamp,
and optional rollback reference. Status history and verification attempts are
append-oriented rather than overwritten.

## Google Ad Manager tables

The GAM integration adds:

- `gam_connections`: driver, type, selected network, health, primary Horus flag,
  default dry-run policy, and non-secret configuration.
- `gam_credentials`: one encrypted `env:` or `file:` reference per connection,
  plus non-secret identity hints and scopes. Raw keys and tokens are prohibited.
- `gam_networks`: accessible network metadata synchronized from Google.
- `gam_connection_permissions`: validated capabilities such as API access and
  network read access.
- `gam_api_operations`: the idempotency ledger and sanitized request/response
  audit for every connector operation.
- `gam_remote_objects`: stable mappings from local Horus records to upstream GAM
  object IDs.
- `gam_sync_runs`: synchronization summaries and counters.
- `gam_sync_logs`: structured events belonging to synchronization runs.
- `gam_errors`: categorized, retryable, resolvable upstream failures.

`sites.gam_connection_id` is nullable. When absent, the resolver automatically
uses the enabled primary `HORUS_GAM` connection for a `HORUS_GAM` website. An
explicit assignment changes only that website.

The operation idempotency key is unique per connection. A successful operation
is returned as a duplicate on repeat execution. Failed or dry-run operation
records are safely reused, allowing an administrator to preview an operation
and later perform that exact write without creating a second ledger row.

Request and response payloads are sanitized recursively before storage.
Credential references are encrypted with the Laravel application key; resolved
credential files and OAuth access tokens are not stored in these tables.

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
