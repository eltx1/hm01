# Publisher Application Experience

## Current product rule

Publisher approval and website approval are separate decisions. New applicants
complete a compact account-first form without a domain, ads.txt installation,
or traffic-percentage arithmetic. Once approved, they add websites individually
inside the Publisher workspace. Each website receives one complete ads.txt
installation block, while verification is limited to its two Horus HMP/HMS
`DIRECT` records. Existing applications with a reserved domain continue on the
legacy evidence path without changing their immutable history.

## Scope

Task 29 extends the Task 27 public Publisher application lifecycle and Task 28 Horus brand foundation. It does not create another Publisher account, onboarding, traffic-profile, review, or serving system.

The public entry point is:

`https://app.horusmedia.net/register/publisher`

The application experience is five short stages: Account, Website / Publisher, Quality & traffic, Agreements, and Review & Submit. Approval continues into the existing seven-step post-approval Publisher onboarding flow. Public application approval still does not approve a website or enable production monetization.

## Cloudflare Turnstile

Production may enable Turnstile with:

- `TURNSTILE_ENABLED=true`
- `TURNSTILE_SITE_KEY`
- `TURNSTILE_SECRET_KEY`
- `TURNSTILE_EXPECTED_HOSTNAME=app.horusmedia.net`
- `TURNSTILE_ACTION=publisher_registration`

The browser widget is only a token producer. The backend always validates enabled Turnstile tokens with Cloudflare Siteverify at `https://challenges.cloudflare.com/turnstile/v0/siteverify` before creating an application. The implementation does not send `remoteip` to Siteverify. Successful tokens are also represented by a short-lived SHA-256 replay key in the application cache; raw Turnstile tokens and the secret are never logged or audited and are excluded from validation-session flashing.

The implementation follows Cloudflare's current Turnstile guidance: server validation is mandatory, tokens expire after five minutes and are single-use, and CSP requires the narrow `https://challenges.cloudflare.com` origin for `script-src` and `frame-src`. Local and test environments use a deterministic provider so automated tests never depend on Cloudflare.

## Legal document configuration

Horus does not ship invented legal text. Production must provide official current document URLs and explicit version identifiers. Supported configured types are:

- `TERMS_OF_SERVICE`
- `PRIVACY_POLICY`
- `PUBLISHER_TERMS`

Environment variables:

- `PUBLISHER_TERMS_OF_SERVICE_VERSION`, `PUBLISHER_TERMS_OF_SERVICE_URL`
- `PUBLISHER_PRIVACY_POLICY_VERSION`, `PUBLISHER_PRIVACY_POLICY_URL`
- `PUBLISHER_TERMS_VERSION`, `PUBLISHER_TERMS_URL`

A document only enters the acceptance UI when both its version and canonical URL are configured. Required configured versions must be explicitly accepted before submission. Evidence is append-only and stores application, user, document type, exact version, canonical URL, acceptance time, a minimal request-evidence hash, and a deterministic evidence hash. Changing the configured current version never rewrites old acceptance evidence.

Optional marketing consent is a separate unchecked checkbox and a separate append-only evidence stream. Transactional application communications do not depend on it.

## Application communications

Email verification uses a signed Laravel verification URL rendered inside the Horus branded email shell. Application received/resubmitted, information requested, approved, and decision notifications reuse the existing Horus notification and email delivery architecture. Applicant messages never contain THOTH reasoning, provider credentials, internal staff notes, passwords, or 2FA secrets.

## Public help

Set `HORUS_PUBLIC_SUPPORT_URL` to the official Horus public support/contact destination. If absent, the configured public supply-chain contact email is used as a mailto fallback. Public pages do not expose authenticated Support ticket data.

## Marketing website handoff

The marketing CTA contract is:

`horusmedia.net` → **Join as Publisher** → `https://app.horusmedia.net/register/publisher`

The marketing website source is not modified by this Control Plane task unless it is explicitly present in this repository. Deployment owners should update the public CTA separately and verify that the destination returns the branded public registration page before enabling `PUBLIC_PUBLISHER_REGISTRATION_ENABLED` in production.

## Deployment checklist

1. Publish the current official legal documents and choose immutable version identifiers.
2. Configure the three legal version/URL pairs that are required for public application acceptance.
3. Create a Cloudflare Turnstile widget restricted to `app.horusmedia.net` and configure site/secret keys plus expected hostname/action.
4. Configure `HORUS_PUBLIC_SUPPORT_URL`.
5. Run migrations and the production Vite build.
6. Verify `/register/publisher` renders the Horus shell, Turnstile, legal flow after login, and Need help link.
7. Update the external HorusMedia.net **Join as Publisher** CTA to the exact URL above.
8. Enable `PUBLIC_PUBLISHER_REGISTRATION_ENABLED` only after the above checks pass.
