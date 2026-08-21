# Production Security Report

## Implemented controls

- Laravel CSRF protection on all state-changing web routes.
- Parameterized Eloquent/query-builder database access; no user-controlled raw SQL introduced.
- Organization global scopes and explicit ownership checks retained.
- Email verification and administrator TOTP 2FA remain implemented as configuration-controlled capabilities; current Horus production defaults disable both mandatory steps for a simpler email + password journey.
- Password reset strengthened to 14+ characters with mixed case, numbers, and symbols; sessions are invalidated after reset.
- Login rate limiting plus database account lockout after repeated failures.
- Session regeneration after successful primary authentication and, when enabled, two-factor authentication.
- Dedicated staff authentication remains restricted to Horus Media identities even when mandatory 2FA is disabled.
- CSP, HSTS, frame denial, MIME sniffing protection, referrer policy, permissions policy, COOP, and CORP headers.
- Private upload storage with server-detected MIME allowlists, size limits, SHA-256 names, and audit metadata.
- GAM/native credentials remain encrypted references to files or environment values outside public roots.
- Apache rules deny `.env`, logs, storage internals, vendor, database, tests, manifests, and directory listing.
- Audited, password-confirmed kill switches and loader rollback.
- Database queue, failed-job visibility, failed-import visibility, and scheduler heartbeat.
- Immutable configuration versions and financial-period protections retained.

## Authentication configuration

Current production defaults are:

```dotenv
AUTH_EMAIL_VERIFICATION_REQUIRED=false
AUTH_ADMIN_2FA_REQUIRED=false
```

These switches remove mandatory verification screens from the normal Horus staff and Publisher journeys. The underlying verification and TOTP implementations remain available for future re-enablement. Password policy, lockout, throttling, session regeneration, tenant isolation, authorization, audit logging, and dedicated staff identity checks remain active.

## Review findings

No advertising architecture change was made: HORUS_GAM is still the default direct server, Prebid remains browser-side, and MCM/Publisher GAM/native demand remain optional. The Laravel application is not in the ad request path.

## Manual production obligations

- Restrict hosting-panel, SSH, database, SMTP, Cloudflare, Google Cloud, and GAM accounts appropriately; use provider-level MFA where operationally suitable.
- Keep production secrets out of Git and backups shared with developers.
- Verify TLS before enabling HSTS preload.
- Configure external error notifications and test them.
- Review WAF events to prevent blocking GAM/Prebid administrative operations.
- Perform dependency and application security review before every release.
