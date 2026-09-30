# Pending verification document optimization

## Field evidence and scope

PR219 starts the isolated verification document while site/global configuration loads.
The supplied publisher trace records CF prepare at 1886ms, configuration ready at
2665ms and CF cold at 2675ms. The old implementation disposes of any document not
already known ready, including a valid navigation that is still loading.

This change retains that navigation; it does not shorten a Cloudflare challenge,
change ad demand, or guarantee field latency. The same supplied trace shows GPT
requests at 11108–11170ms before video starts at 15375ms. Do not reintroduce a
video-readiness dependency or equate GPT render with first visible paint.

## Transport ownership

1. Prepare only the fixed HTTPS verification document, with no HELLO, nonce,
   Turnstile execution or advertising before the ordinary configuration decision.
2. Validate site/script binding, age, attachment and fixed URL. A loaded document
   without script readiness is untrusted for reuse and takes the ordinary path.
3. Adopt a valid ready or pending document without moving/reappending its iframe.
   Transfer its cleanup from the speculative owner to the active gate.
4. Send one ordinary nonce-bound HELLO on the exact origin/source readiness ping.
   The original maximum wait, initial wait, privacy and server PASS checks remain.
5. A pending document with no HELLO by the existing initial wait gets one ordinary
   transport fallback. Remove its handlers and invalidate its source first. Keep
   the original nonce, attempt, start timestamp and maximum deadline.
6. Never replace a frame after HELLO: a slow SDK, challenge or verification must
   not cause duplicate challenges, token submissions or advertising requests.
7. A failed/legacy/no-referrer warmed document uses the existing cold handshake.
   Iframe load alone is not proof of successful script execution. Late callbacks
   from removed frames are inert, and deadline cleanup removes every listener.

No WAF, DNS, consent, Click Guard, campaign, VAST URL, refresh or pricing changes.
No production secrets, telemetry endpoints or dependencies were added.

## Diagnostics

`getStartupTrace()` now returns `build: startup-trace-2` and `runtimeBuild`.
The compiled build uses `sha256:<64 hex>` for the full composed Loader source before
minification, with a fixed identity placeholder to avoid a circular hash. The
uncompiled test source reports `source-inflight-1`. This is a reproducible source
identifier, not the SHA256 of the final minified bytes or a Git commit.

`CF adopt` records `mode: ready|pending`. `CF cold` records only allowlisted
reasons: binding, stale, detached, source, readiness_missing. `CF transport` records
pending_deadline or frame_error. `CF prepared` denotes the source-bound script
readiness signal; `CF document loaded` is distinct and does not authorize anything.
No token, nonce, URL, consent string or raw error is included.

## Validation / acceptance

A targeted Node regression reproduces the cancellation on PR219 and passes with
the change. Controlled tests cover ready/pending reuse, one fallback, legacy cached
gate code, missing referrer, forged messages, late callbacks, maximum deadline,
duplicate loader installation, slow server verification and cleanup. Browser tests
exercise composed and minified builds on Chromium/WebKit desktop/mobile profiles;
providers are mocked and all unmatched network traffic is blocked.

An optional `HM_PENDING_BASELINE` comparison supplies the exact old compiled Loader
and delays configuration by 500ms, document by 800ms, and SDK by 100ms. Its acceptance
is two document requests before versus one after and one verification after, not a
promise about Cloudflare performance. Record the measured timings without treating
controlled or CI time as real publisher performance.

Run npm run test:browser and the full playwright.traffic-gate.config.js matrix.
Require successful production-release, Tests and static checks before merge.
After deployment verify exact artifact identities and then a publisher trace.
The reported five-minute incident, creative render-to-load latency and upstream
VAST303/fill rate are not declared resolved by this transport optimization.

## Sources reviewed

- https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/
- https://developers.cloudflare.com/turnstile/get-started/server-side-validation/
- https://developer.mozilla.org/en-US/docs/Web/HTML/Reference/Elements/iframe
- https://developers.google.com/publisher-tag/samples/ad-event-listeners
