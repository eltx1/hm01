# Horus Loader Client Traffic Gate Runtime

Status: Task 50 browser enforcement built on the Task 48 control-plane configuration and Task 49 pure-static Turnstile gate.

## Runtime boundary

The Client Traffic Gate is a **client-only soft traffic filter**. It is not human verification, valid-traffic verification, bot verification, fraud detection, or IVT clearance. The Loader never sends a gate outcome, visitor identifier, Turnstile token, activity signal, or gate telemetry to Laravel, a Worker, analytics, or reporting.

The Publisher integration remains unchanged: the Publisher installs only the permanent Horus Loader. When the effective public Site configuration requires the gate, the Loader creates one non-visible iframe at the canonical `https://verify.horusmedia.net/traffic-gate/` origin.

## Pre-monetization invariant

Before the canonical Traffic Gate state allows serving, the Loader's central `canRequestAds()` contract returns false. This protects every engine before routing rather than placing separate checks inside GAM, Prebid, or Direct JS.

Until allowed, Horus does not start GPT/GAM monetization, a Prebid auction or bidder request, Direct JS/native provider execution, ad refresh, or another impression-producing monetization request.

The existing platform `adServingDisabled` master kill remains authoritative and suppresses monetization without bothering to run the Traffic Gate.

## Parallel preparation

Boot preparation is deliberately parallel where dependencies permit it:

1. static Site configuration and the global control artifact begin together;
2. once the Site configuration is available, privacy/CMP resolution and the Traffic Gate begin independently in the same boot phase; and
3. monetization starts only when normal Horus configuration/privacy prerequisites and the Traffic Gate resolver both permit it.

A fast PASS is never delayed by `initialWaitMs` or `maxWaitMs`. The timers are failure/recovery bounds, not minimum ad-start delays.

## In-memory document singleton

Traffic Gate state is stored only in the existing Loader document singleton (`window.__HORUS_MEDIA_LOADER_STATE__`). PASS is not stored in cookies, `localStorage`, `sessionStorage`, IndexedDB, a Laravel session, or a database.

At most one Traffic Gate challenge is started in one browser document. Placement count, GAM/Prebid auctions, ad refresh, MutationObserver scans, SPA `pushState`/`replaceState` navigation, and accidental duplicate Loader evaluation do not create a second challenge. A full page load creates a fresh Loader state and therefore a fresh gate.

## Handshake validation

The Loader generates the page nonce with `crypto.getRandomValues()` and never uses `Math.random()` as the nonce source.

The parent accepts Task 49 messages only when all of the following match:

- `event.origin` equals the configured canonical gate origin;
- `event.source` equals the exact Traffic Gate iframe `contentWindow`;
- `protocolVersion` is supported; and
- `pageNonce` exactly equals the nonce generated for the current document.

The Loader sends only the versioned HELLO envelope with `pageNonce` and the public Horus Site identifier. It never sends a Publisher-supplied domain. The Task 49 frame remains responsible for independently authorizing the actual parent origin against the static Site configuration.

The PASS message contains no token in the Task 49 protocol. If an unexpected extra field is present, Task 50 does not read, forward, persist, report, or expose it.

## Canonical states

The Loader uses one explicit state machine:

- `DISABLED`
- `BOOTING`
- `PENDING`
- `PASSED`
- `ERROR`
- `TIMEOUT`
- `UNAVAILABLE`
- `WAITING_FOR_ACTIVITY`
- `SOFT_ALLOWED`
- `BLOCKED`

Only `DISABLED`, `PASSED`, and `SOFT_ALLOWED` authorize monetization. `DENIED` maps to `BLOCKED` and never fails open under any policy.

## Policy behavior

### STRICT

PASS allows immediately. ERROR, TIMEOUT, UNAVAILABLE, or another unresolved technical failure does not allow monetization. The page itself remains usable; only Horus monetization is suppressed.

### BALANCED

PASS allows immediately. A technical failure or an initial-wait stall may move the state to `WAITING_FOR_ACTIVITY` when `activityRecoveryEnabled` is true.

Recovery accepts only meaningful browser activity. Native events whose `isTrusted` property is available must have `isTrusted === true`. Supported signals are pointer down, touch start, a meaningful non-modifier keydown, and a meaningful scroll displacement. Programmatic `dispatchEvent()` does not qualify in modern browsers because the generated event is not trusted.

After accepted activity the state becomes `SOFT_ALLOWED`. Without accepted activity the document remains non-monetized; the failure is not labelled as bot or invalid traffic.

### PERMISSIVE

PASS allows immediately. Technical ERROR/TIMEOUT/UNAVAILABLE or a stall remains blocked until the configured bounded `maxWaitMs`, then becomes `SOFT_ALLOWED`. DENIED remains `BLOCKED`.

## Timer and cleanup behavior

`initialWaitMs` is the slow/stall recovery threshold. It never delays PASS. `maxWaitMs` bounds strict/permissive waiting and frame/listener cleanup. The parent does not retain an unbounded Promise waiting forever for a gate response.

Final PASS, SOFT_ALLOWED, BLOCKED, or DISABLED decisions remove unnecessary frame/message/timer/activity resources. BALANCED activity listeners exist only while the state is intentionally waiting for a trusted recovery signal.

## Emergency disable and Site override

The global static `controls.trafficGateDisabled` switch bypasses the Traffic Gate immediately and returns the Loader to the normal serving lifecycle, subject to the existing ad-serving and engine controls. A force refresh can release a previously pending gate after the new global control artifact reaches the browser.

An effective Site Traffic Gate `DISABLED` state creates no iframe and follows the normal Loader serving lifecycle.

## CSP failure

If Publisher CSP or another browser condition prevents the gate iframe from loading, the Loader treats the condition as `UNAVAILABLE`, never as bot/fraud/invalid traffic. STRICT, BALANCED, and PERMISSIVE then apply their normal technical-failure semantics.

Publishers that enable the Traffic Gate must allow framing of the canonical `verify.horusmedia.net` origin. The Turnstile CSP itself is owned by the Task 49 static gate page and does not require weakening unrelated Publisher or Horus pages.

## Build and verification

`scripts/transform-loader-traffic-gate.mjs` applies the Task 50 runtime at Loader build time from the permanent base Loader source. `scripts/build-loader.mjs` minifies that transformed runtime into `public/assets/hm-loader.min.js`, which remains the artifact used by Static Delivery.

Browser tests execute the transformed runtime and prove the pre-monetization invariant, all three serving engines, policy behavior, trusted-activity recovery, exact parent validation, singleton behavior, emergency bypass, no token persistence, no outcome telemetry, and the parallel boot contract.
