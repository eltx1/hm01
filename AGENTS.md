# AGENTS.md

## Project identity

- Company: Horus Media
- Main domain: `horusmedia.net`
- Dashboard: `app.horusmedia.net`
- Advertising CDN: `cdn.horusmedia.net`
- Repository: `eltx1/hm01`

## Fixed architecture decisions

1. `HORUS_GAM` is the primary and default ad server.
2. Optional serving modes are:
   - `HORUS_GAM`
   - `MCM_PARTNER_GAM`
   - `PUBLISHER_GAM`
   - `DIRECT_NATIVE_ONLY`
   - `PAUSED`
3. New publisher websites default to `HORUS_GAM`.
4. Serving mode can be changed per website without changing publisher installation code.
5. Prebid.js runs in the visitor browser.
6. Google Ad Manager remains the main ad server for direct campaigns.
7. MCM partner GAM and publisher GAM are optional connectors, not platform requirements.
8. Native demand connectors are optional and modular.
9. The Horus Media application is the control plane; ad delivery must not depend on the PHP backend per impression.
10. The publisher installs one permanent Horus loader.

## Production constraints

- Hostinger shared or cloud hosting.
- PHP and MySQL.
- Laravel and Composer are allowed.
- Cron jobs are allowed.
- No root access requirement.
- No Docker requirement in production.
- No Node.js runtime requirement in production.
- No Redis requirement.
- No Supervisor requirement.
- No WebSockets requirement.
- No persistent background workers.
- Frontend and Prebid assets must be compiled before release.

## Engineering rules

- Never commit secrets or private credentials.
- Every external API write must support dry-run mode.
- External write operations must be idempotent.
- Every sensitive change must be recorded in audit logs.
- Data access must be organization-scoped where applicable.
- Do not hard-code Google Ad Manager API versions across the codebase.
- Do not hard-code one GAM connection throughout the application.
- Do not require publishers to manually configure Prebid line items.
- Do not send raw bid or impression events to the Laravel backend.
- Store aggregated reporting data only.
- Do not modify unrelated files.
- Every implementation task must add automated tests.
- Every task report must include changed files, commands, tests, failures, and unresolved risks.
