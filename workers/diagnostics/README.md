# Optional performance observations

This independent Worker never imports the admission Worker and never decides
whether an advertisement may load. Every site is disabled by default. Only an
explicit per-site setting in its normal confirmed CDN configuration permits
collection. The Loader schedules a sampled, separate asset after invoking ad
startup and after verified Cloudflare PASS; it never waits for this service.
The disabled path creates no observation timer, script fetch or request.

## Contract and limits

- Canonical registry: `https://cdn.horusmedia.net/configs/{siteKey}/production.json`.
  A WAF refusal stops observations. There is no alternate registry or bypass.
- Only registered exact publisher origins and active, non-paused sites are
  accepted. This and CORS are abuse controls, not proof of a human or PASS.
- Browser sampling: 1–1000 basis points (0.01–10%), default saved value 100 (1%).
  Config version and sample policy must match the registry; mismatches are
  discarded without retry. Registry caching can temporarily discard fresh data.
- Body limit 2 KiB; body read deadline 1.5 seconds; registry fetch deadline
  2 seconds. Best-effort per-isolate 10 requests/IP/minute; this is not a global
  quota. Registry and transient rate maps are bounded to 256/512 entries.
- Only bounded numeric timings, config version and coarse device/browser
  families reach `horus_platform_diagnostics_v1`. No URL, referrer, IP, raw UA,
  visitor ID, ad identifier, nonce, token, or raw trace is stored there or sent
  to Laravel. Cloudflare's transport infrastructure still handles requests.
- Data points: `pages`, one `outcome:*`, and up to four duration measurements.
  `double1` is milliseconds; `double2` is inverse browser sampling probability.
  `blob1..5` are site key, device, browser, metric, configuration version.
  `index1` is site key. Writes are best effort and can be partial on failures.
- These are **client-reported observations**, not authoritative fraud, verified
  admission outcomes, billable events, traffic totals or financial records.
  Aborted pages, unsupported browsers and failed observers reduce coverage.
- Render / after-PASS request measurements currently describe **GPT display
  slots**. Standalone Prebid/Direct JS/video without GPT evidence are incomplete,
  never automatically classified as delivery failures. Missing is never zero.

The admin report queries aggregate rows for the **current confirmed production
version** over 24 hours, with 60-second caching, 3-second API timeout and maximum
200 groups. Weighted p50/p75/p95 use Analytics Engine `_sample_interval`.
Browser sample probability is fixed within a version, so its constant weight
cancels from the percentiles. Versions are not combined. Stored row count,
internally adjusted sample count, and estimated observation count are distinct.
The estimates are neither audited pageviews nor an IVT rate.

## Deployment and recovery

`node scripts/deploy-diagnostics.mjs` is a read-only ownership dry-run.
`--apply` uploads only `horus-performance-diagnostics`, binds its dataset and
provisions `metrics.horusmedia.net`. It checks existing Worker tags, domain
ownership, unexpected bindings and read-back. An unchanged source/binding/domain
causes no writes. It never updates Siteverify, Turnstile, WAF or existing DNS
records. Native binding compilation is checked by the upload API; a failed
upload cannot grant advertising permission.

The production-environment workflow tests the contract, runs dry-run, then
applies. It uses existing scoped deployment credentials. Account Analytics Read
is separately needed for dashboard queries. Do not broaden permissions silently.
Without that permission, the dashboard reports UNAVAILABLE / SETUP_REQUIRED.

Application configuration: `HORUS_DIAGNOSTICS_COLLECTOR_URL` defaults to the
fixed canonical origin. An empty/other value disables public collection.
`HORUS_DIAGNOSTICS_ACCOUNT_ID` and `HORUS_DIAGNOSTICS_TOKEN_REFERENCE` optionally
override existing static-delivery account / secret references. These are server
configuration only and never appear in public payloads. References support the
existing `env:` / private `file:` resolver.

Admin: website → Performance observations → opt in and choose sample rate.
Saving uses normal audited static publication; repeated identical saves do not
create new versions. Disabling or a configuration rollback retains the current
opt-in policy. Previously published content-addressed assets stay available for
historical references. No site is enabled automatically by this deployment.

Before marking the feature live: required CI, successful independent Worker
upload/read-back, app deployment, static asset parity, and a controlled opted-in
browser check are separate evidence. Unit tests alone do not establish them.
