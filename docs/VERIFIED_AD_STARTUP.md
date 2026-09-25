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
- For an active GAM placement, preload the configured official GPT script. This
  downloads bytes only: it does not execute GPT or publisher-owned command queues.
  The subsequent classic script uses the same URL and fetch mode to reuse it.
- DOM/CMP discovery, iframe authorization, Turnstile, server-side verification,
  slot definition, bidding, provider initialization and ad requests retain their
  existing ordering. No automatic ad refresh or new telemetry is introduced.
- The central request predicate also explicitly requires a resolved privacy
  decision. Regression testing exposed a pre-existing direct `scan()` call path
  that could run after gate PASS while CMP was still pending; it is now closed.
- Reuse the preparation only within five seconds of its start. Long parser stalls,
  forced refreshes and failed early fetches use a fresh normal boot. No verification
  token, PASS result or user authorization is persisted.
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

- `npm run test:browser`: 244 passed, zero failed.
- `npm test --prefix tests/Workers`: one native workerd integration test passed,
  including permission-cache headers, uncached POSTs and redirect rejection.
- Chromium/WebKit desktop/mobile coverage includes parser blocking, a CMP installed
  after preparation, server rejection, slow verification, one-time GPT execution,
  request reuse, and zero monetization before all prerequisites succeed.
  Browser execution is required in CI before merge.
- Existing all-engine, forged-message, replay, bounded-timeout, duplicate-start,
  privacy and global-stop tests remain enabled.

## Changed files

- `scripts/transform-loader-traffic-gate.mjs`
- `public/assets/hm-loader.min.js` (generated)
- `public/traffic-gate/index.html`
- `workers/siteverify/index.mjs`
- `tests/Browser/hm-loader-traffic-gate.test.js`
- `tests/Browser/traffic-gate-loader.playwright.spec.js`
- `tests/Browser/siteverify-worker.test.js`
- `tests/Workers/siteverify.test.mjs`
- `docs/VERIFIED_AD_STARTUP.md`

## References

- [Google: control ad loading](https://developers.google.com/publisher-tag/guides/control-ad-loading)
- [Cloudflare: early execution and resource hints](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/)
- [MDN: CORS permission cache](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Access-Control-Max-Age)
