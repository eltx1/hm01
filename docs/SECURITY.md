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
- Public Publisher applicants are the single explicit pre-activation exception:
  the same Laravel identity may authenticate only when a same-user,
  same-organization application exists. Applicant middleware exposes no
  operational Control Plane route, role, finance, site, GAM, Prebid, Direct JS,
  provider, Admin, or cross-tenant access.
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
  and reserved addresses, limits response size and time, and follows only the
  explicitly bounded redirect policy of the verification mechanism in use.
- Site reviews, status changes, serving changes, revenue-share changes, manual
  verification, internal notes, and emergency pauses are audited.
- THOTH provider keys are either environment-managed or stored with Laravel
  authenticated encryption under `APP_KEY`; they are hidden from serialization,
  HTML, logs, audit values, Publisher payloads, and static delivery artifacts.
- THOTH treats verified-site HTML as untrusted data. Operational Publisher
  fetches require a verified SiteDomain. Pre-approval application fetches require
  the current Task 39 ads.txt-verified application claim. Stale application
  verification is refreshed only through the canonical Task 39 verifier using
  already-reserved HMP/HMS identities; THOTH cannot reserve or mutate seller IDs.
- THOTH website fetching revalidates every destination through public-IP/DNS
  safety and DNS pinning. Redirects are tightly bounded and allowed only within
  the verified site's exact/www scope; arbitrary cross-domain redirects,
  private/reserved/loopback destinations, unsafe DNS results, excessive
  responses, invalid MIME types, and redirect-limit violations fail closed.
- THOTH static extraction never executes JavaScript, iframes, forms, objects,
  embeds, or browser automation. Script/style/hidden/aria-hidden content is
  removed before evidence reaches an AI provider.
- AI providers receive a field allowlist and no tools, browsing, internal APIs,
  serving controls, finance access, application decision actions, or write
  capability. HMP/HMS are not included in the application AI evidence envelope.
  Human Horus Admins are the sole Publisher/application decision authority,
  protected by separate RBAC permissions.
- Public application transitions, submitted revisions, decisions, and
  information requests are append-oriented and audited. Domain claims and row
  locks prevent duplicate applications and duplicate approval handoffs. Public
  registration and application writes use dedicated rate limits.
- The application-specific THOTH action is Horus-only, requires
  `publisher_quality.ai.run`, Admin 2FA, active/verified authentication and the
  sensitive-action throttle. It is allowed only for submitted, under-review, or
  more-information-required applications and never auto-runs on applicant edits.

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
