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
- Horus Media administrators must enroll RFC 6238 TOTP and pass a challenge;
  challenge sessions expire after 12 hours and recovery codes are single-use.
- White-label branding changes require the dedicated `branding.manage`
  permission and are audited.
- Publisher roles do not receive internal-margin permissions; advertiser roles
  do not receive publisher-finance permissions.
- Publisher site, domain, contract, and payment-profile queries are organization
  scoped. Viewer roles cannot mutate onboarding or websites.
- Contract documents use private storage and authorized controller downloads.
- Payment and tax references are encrypted at rest and omitted from audit data.
- Automated domain verification validates public DNS targets, rejects private
  and reserved addresses, limits response size and time, and does not follow redirects.
- Site reviews, status changes, serving changes, revenue-share changes, manual
  verification, internal notes, and emergency pauses are audited.
- THOTH provider keys are either environment-managed or stored with Laravel
  authenticated encryption under `APP_KEY`; they are hidden from serialization,
  HTML, logs, audit values, Publisher payloads, and static delivery artifacts.
- THOTH treats verified-site HTML as untrusted data. Fetches reuse public-IP
  validation, DNS pinning, redirect blocking, MIME/size/time limits, and static
  text extraction; remote JavaScript is never executed or embedded in Admin.
- AI providers receive a field allowlist and no tools, browsing, internal APIs,
  serving controls, finance access, or write capability. Human Horus Admins are
  the sole Publisher decision authority, protected by separate RBAC permissions.

## Required future controls

- optional WebAuthn support and step-up authentication for the highest-risk actions
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

Publisher website forms treat revenue share as read-only agreement data. The
server derives it from the applicable contract, and only Horus Media roles with
`sites.serving.manage` can change it through an audited operation.

## Data minimization

The control plane stores aggregate reporting only. Do not route ad requests
through Laravel or store visitor-level bidstream data. IP address and user-agent
fields in security audit records require a documented retention and access
policy before launch.

## Incident readiness

Use request IDs across logs and audit entries, keep UTC timestamps, back up
MySQL before releases, document recovery objectives, and maintain rollback
packages for both Laravel and CDN configuration.
