# Horus Media Engineering Rules

These rules are mandatory for every contributor and automated agent.

## Project identity

- Company: Horus Media
- Main domain: `horusmedia.net`
- Dashboard: `app.horusmedia.net`
- Advertising CDN: `cdn.horusmedia.net`
- Repository: `eltx1/hm01`

## Fixed architecture

- `HORUS_GAM` remains the default, first-class Horus-managed GAM serving mode for
  new publisher websites that use the GAM engine. Existing `HORUS_GAM`,
  `MCM_PARTNER_GAM`, and `PUBLISHER_GAM` behavior must remain backward compatible.
- GAM is optional at the product architecture level. A website may monetize
  without any GAM connection when it uses the `HORUS_DIRECT` serving mode.
- Serving mode and serving engine are different concepts. A serving mode describes
  how a site is broadly operated; the independent serving engines are `GAM`,
  `PREBID`, and `DIRECT_JS`.
- Standalone Prebid is a supported architecture: browser-side Prebid may operate
  without GAM and render its winning bid directly through the permanent Horus
  Loader when the standalone runtime is enabled.
- Direct JS demand is an independent engine. Approved provider tags must not be
  converted into fake Prebid bidders and must not require GAM or a lost Prebid
  auction before they may run.
- Prebid and Direct JS may operate simultaneously on the same site/page across
  independent placements. Do not create a global winner between them.
- A single physical placement/container must have one clear renderer at a time
  unless a deliberately isolated composite-placement design is implemented.
  Never double-render multiple engines into the same DOM surface.
- GAM-enabled sites continue using the existing GAM architecture: GPT remains the
  GAM delivery layer, the current GAM connection resolver and inventory sync remain
  authoritative, direct campaigns continue using the current GAM deployment path,
  and Prebid-to-GAM header bidding remains supported.
- Do not introduce any blocker, gate, authorization requirement, ownership
  check, compliance rule, or business restriction that prevents valid
  `HORUS_GAM` activation or breaks the existing GAM path.
- Do not make MCM mandatory.
- Do not make Publisher GAM mandatory.
- `DIRECT_NATIVE_ONLY` remains a supported legacy/specialized direct-native mode
  until a deliberate, separately tested migration removes or replaces it.
- `PAUSED` remains the explicit paused serving mode.
- The publisher installs one permanent Horus Media loader. Configuration changes
  must not require publisher code changes.
- Advertising requests must not pass through the Laravel backend.
- Prebid remains browser-side; do not route raw bid/impression/click streams to
  Laravel.
- Runtime serving configuration is published as static CDN configuration.
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
