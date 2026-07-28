# Security

## Foundation controls

- Laravel CSRF middleware protects state-changing web requests.
- Production sessions use database storage, encryption, Secure and HttpOnly
  cookies, and `SameSite=Lax`.
- Application exceptions are centrally reported; JSON clients receive a
  non-sensitive production message.
- Daily application logs use structured JSON and configurable retention.
- Sensitive actions have an append-oriented audit-log foundation.
- Production configuration is environment-based; credential values are absent
  from repository templates.
- Active user and active organization state are required at login and on every
  authenticated request.
- Publisher and advertiser account models apply organization global scopes.
- Invitations accept only roles valid for the destination organization type.
- Password reset and suspension revoke database sessions; login successes and
  failures are recorded separately.
- Permission changes and administrator impersonation are explicitly audited.
  Audit payloads redact passwords, tokens, and two-factor secrets.
- Publisher roles do not receive internal-margin permissions; advertiser roles
  do not receive publisher-finance permissions.

## Required future controls

- complete TOTP/WebAuthn challenge and recovery flow for administrators (the
  encrypted storage structure is present)
- periodic least-privilege authorization review and custom-role design if needed
- encryption for connector secrets using a key outside the database
- credential rotation and connection health checks
- rate limiting for authentication, API, loader configuration, and exports
- CSP, HSTS, clickjacking protection, MIME sniffing protection, and a reviewed
  CDN origin policy
- signed or unguessable site configuration references without embedding secrets
- dependency, static analysis, secret scanning, backup, and restore validation

## External writes

All future GAM, native network, MCM, publisher GAM, CDN, and payment writes must
support dry-run mode and idempotency. Audit records must capture actor, tenant,
operation, sanitized change, request correlation, and result. Secrets, tokens,
full payment details, and sensitive personal data must be redacted.

## Data minimization

The control plane stores aggregate reporting only. Do not route ad requests
through Laravel or store visitor-level bidstream data. IP address and user-agent
fields in security audit records require a documented retention and access
policy before launch.

## Incident readiness

Use request IDs across logs and audit entries, keep UTC timestamps, back up
MySQL before releases, document recovery objectives, and maintain rollback
packages for both Laravel and CDN configuration.
