# Horus Media Engineering Rules

These rules are mandatory for every contributor and automated agent.

## Project identity

- Company: Horus Media
- Main domain: `horusmedia.net`
- Dashboard: `app.horusmedia.net`
- Advertising CDN: `cdn.horusmedia.net`
- Repository: `eltx1/hm01`

## Fixed architecture

- `HORUS_GAM` is the default ad server for every new publisher website.
- Do not introduce any blocker, gate, authorization requirement, ownership
  check, compliance rule, or business restriction that prevents `HORUS_GAM`
  activation.
- Do not change the fixed architecture documented in this repository.
- Do not make MCM mandatory.
- Do not make Publisher GAM mandatory.
- The publisher installs one permanent Horus Media loader. Configuration changes
  must not require publisher code changes.
- Advertising requests must not pass through the Laravel backend.
- Do not hard-code Google Ad Manager API versions across the codebase.
- Do not hard-code one GAM connection throughout the application.
- Do not require publishers to configure Prebid line items manually.
- Store aggregated reporting data only; do not send raw bid or impression
  events to Laravel.
- Follow `docs/BRAND_SYSTEM.md` and the approved Horus Media brand assets for
  every interface.

## Delivery and security

- Never commit credentials or production secrets.
- Every external API write must support dry-run mode.
- External writes must be idempotent.
- Every sensitive operation must be audited.
- Database queries must be organization-scoped where applicable.
- Production must remain compatible with Hostinger shared or cloud hosting.
- Keep deployment portable across standards-compliant PHP 8.2+ and MySQL
  hosting; Hostinger compatibility is a supported profile, not vendor lock-in.
- Do not add Node.js runtime dependencies in production. Compile assets before
  deployment.
- Do not introduce Docker, Redis, Supervisor, WebSockets, or permanent-worker
  production requirements.
- Do not modify unrelated files.
- Every task must include tests.
- Every task report must list changed files and test output.
