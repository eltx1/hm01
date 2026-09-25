# Early preparation with verified ad startup

Publisher reports described 6–10 second ad startup. Those field timings have
not yet been decomposed into page, network, challenge and auction time. This
change removes avoidable serial preparation without changing the challenge
decision or claiming a measured field improvement.

## Behavior

- While the HTML parser is still running, fetch site configuration and global
  controls concurrently. Capture the current Loader script before DOM readiness.
- After validating the configured hostname, active status, engine controls and
  gate readiness, add connection hints for the existing verification origins.
- For an active GAM placement, preload the configured official GPT script as soon
  as privacy permits it. Consent-required/strict/BLOCK_ADS configurations wait for
  the existing privacy decision; other sites may download before DOM readiness. This
  downloads bytes only: it does not execute GPT or publisher-owned command queues.
  The subsequent classic script uses the same URL and fetch mode to reuse it.
- After the early config/control checks, start the isolated verification frame
  using the available body/document root without waiting for DOMContentLoaded.
  Config is not exposed to the ad runtime and no monetization callback is attached
  at this point. CMP discovery retains its DOM boundary so late-installed CMPs
  are still detected. All ad engines wait for both privacy and server verification.
- Inside the frame, fetch its own authoritative site config and the official
  Turnstile library concurrently, after a valid source-bound HELLO. Library loading
  is preparation only: parent hostname, site key, gate configuration and deadline
  are checked before rendering any challenge or verifying a token. A rejected
  parent can download the public library but cannot start a challenge or Siteverify.
- A matching pending/successful early attempt is adopted at DOM readiness with
  its original timer and nonce. A transient technical failure of early preparation
  gets one normal DOM-time attempt with the same configured limits and a fresh
  nonce; this preserves availability when early loading failed or consumed the
  deadline. Explicit denial/server rejection and unclassified errors do not retry.
  A failed normal attempt cannot restart on subsequent boot/refresh calls.
  A refreshed or replaced configuration must match the exact captured snapshot,
  script and site key; otherwise the old frame/listener/PASS is retired before a
  new attempt. Failed config refreshes also discard early verification.
  No automatic ad refresh or new telemetry is introduced.
- The central request predicate also explicitly requires a resolved privacy
  decision. Regression testing exposed a pre-existing direct `scan()` call path
  that could run after gate PASS while CMP was still pending; it is now closed.
- Reuse the preparation only within five seconds of its start. Long parser stalls,
  forced refreshes and failed early fetches read fresh configuration and controls.
  An unchanged fresh snapshot can keep the same in-page verification attempt;
  no token, PASS result or user authorization is persisted across page visits.
- Preparation generations invalidate old in-flight responses, so they cannot
  emit hints or replace current controls after a newer refresh/emergency stop.
- The verification frame also hints its existing Cloudflare connections.
- OPTIONS allows browser caching of its exact-origin POST/Content-Type permission
  for 600 seconds. Every POST still validates its token upstream and remains
  `Cache-Control: no-store`. Origin, hostname, action, nonce, timeout and retry
  checks are unchanged.

Unsupported/blocked preload hints fall back to the existing script-loading path.
Only official GPT URLs are eligible for speculative downloading; custom URLs
retain their existing post-verification loading behavior. Pages without GAM do
not preload GPT. Protection settings and the Cloudflare plan are unchanged.

## Validation

- `npm run test:browser`: final result recorded in PR validation.
- `npm test --prefix tests/Workers`: one native workerd integration test passed,
  including permission-cache headers, uncached POSTs and redirect rejection.
- Chromium/WebKit desktop/mobile coverage includes parser blocking, a CMP installed
  after early verification finishes, parallel library download while the frame's
  config is deliberately held, server rejection, slow verification, one-time GPT
  execution, request reuse, and zero monetization before prerequisites succeed.
  Browser execution is required in CI before merge.
- Early verification follow-up: Node results are recorded in the PR, including stale
  PASS/frame rejection, domain revocation, config refresh failure, preserved
  pending deadlines, one bounded technical fallback and handled parallel library errors. Final CI/deployment results are
  recorded in its PR rather than presented as publisher field measurements.
- Existing all-engine, forged-message, replay, bounded-timeout, duplicate-start,
  privacy and global-stop tests remain enabled.

## Changed files (initial preparation change)

- `scripts/transform-loader-traffic-gate.mjs`
- `public/assets/hm-loader.min.js` (generated)
- `public/traffic-gate/index.html`
- `workers/siteverify/index.mjs`
- `tests/Browser/hm-loader-traffic-gate.test.js`
- `tests/Browser/traffic-gate-loader.playwright.spec.js`
- `tests/Browser/siteverify-worker.test.js`
- `tests/Workers/siteverify.test.mjs`
- `docs/VERIFIED_AD_STARTUP.md`

## Changed files (early verification follow-up)

- `scripts/transform-loader-traffic-gate.mjs`
- `public/assets/hm-loader.min.js` (generated)
- `public/assets/traffic-gate/horus-traffic-gate.js`
- `tests/Browser/hm-loader-traffic-gate.test.js`
- `tests/Browser/traffic-gate.test.js`
- `tests/Browser/traffic-gate-loader.playwright.spec.js`
- `tests/Browser/traffic-gate.playwright.spec.js`
- `docs/VERIFIED_AD_STARTUP.md`

## References

- [Google: control ad loading](https://developers.google.com/publisher-tag/guides/control-ad-loading)
- [Cloudflare: early execution and resource hints](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/)
- [MDN: CORS permission cache](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Access-Control-Max-Age)
