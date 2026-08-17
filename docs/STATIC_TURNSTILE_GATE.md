# Horus Static Invisible Turnstile Gate

Status: Task 49 static browser gate origin and secure parent-handshake foundation.

The Horus Client Traffic Gate remains a **client-only soft traffic filter**. It is not an authentication boundary, Siteverify integration, human-verification claim, valid-traffic certification, bot verification system, or IVT clearance system.

Cloudflare's current Turnstile documentation requires server-side Siteverify when Turnstile is used as a security control. Horus intentionally does not make that claim for this client-only product. The success callback is used only as a bounded browser-side gate signal, and its token is discarded immediately.

## Hosting model

The gate is a normal static artifact in the existing Horus Cloudflare Pages project. No Pages Function, Worker, Laravel endpoint, backend API, database request, or cookie-backed Horus verification is introduced.

Production URL:

```text
https://verify.horusmedia.net/traffic-gate/
```

The same Pages artifact set also contains the canonical per-Site static configuration under:

```text
/configs/{SITE_PUBLIC_KEY}/production.json
```

This lets the gate validate the embedding parent against the Site's public `allowedHostnames` without a Laravel request.

## One-time Cloudflare setup

1. Add `verify.horusmedia.net` as an additional custom domain on the existing pure-static Horus Pages project used by `cdn.horusmedia.net`.
2. Do not add a `functions/` directory, Pages Function, or `_worker.js`.
3. Create a dedicated Turnstile widget named **Horus Ad Traffic Gate**.
4. Configure the widget hostname as **`verify.horusmedia.net` only**. Publisher domains are not added to Turnstile Hostname Management.
5. Configure widget mode as **Invisible**.
6. Copy the widget's **PUBLIC sitekey** into the Horus Traffic Gate global setting when activation is desired.
7. The generated Turnstile secret is **not used by this client-only feature** and must not be stored in Horus.

The Publisher continues to install only the permanent Horus Loader.

## Cloudflare client contract used by Task 49

Task 49 follows the current Cloudflare Turnstile browser documentation:

- official script: `https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit`;
- explicit programmatic rendering;
- `callback`, `error-callback`, `timeout-callback`, and `unsupported-callback` handling;
- `response-field: false`, so no hidden response field is created;
- Turnstile automatic retry disabled with `retry: never`;
- one bounded Horus-controlled reset using Task 48's validated `retryIntervalMs`;
- overall challenge lifetime bounded by Task 48's validated `maxWaitMs`;
- production widget behavior is Invisible because the Cloudflare widget itself is configured as Invisible.

Task 49 does not proxy, bundle, mirror, or locally cache Cloudflare's Turnstile script.

## Versioned parent protocol

Protocol version: `1`.

Parent-to-gate message:

```json
{
  "type": "HORUS_TRAFFIC_GATE_HELLO",
  "protocolVersion": 1,
  "pageNonce": "cryptographically-random-parent-nonce",
  "sitePublicKey": "HORUS_PUBLIC_SITE_KEY"
}
```

The gate accepts HELLO only from `window.parent`, requires an HTTPS `event.origin`, and binds the accepted frame session to:

- exact `event.source`;
- exact `event.origin`;
- exact `pageNonce`;
- constrained Horus `sitePublicKey`.

The gate never trusts a parent-supplied domain string.

After HELLO, normal mode fetches the Site's same-origin public static configuration and requires all of the following before rendering Turnstile:

- returned `siteKey` exactly matches the requested `sitePublicKey`;
- actual `event.origin` hostname exactly matches a current `allowedHostnames` entry;
- `trafficGate.enabled === true`;
- readiness is `READY`;
- provider is `CLOUDFLARE_TURNSTILE_CLIENT_ONLY`;
- configured gate origin is exactly `https://verify.horusmedia.net`;
- public Turnstile sitekey is syntactically valid;
- Task 48 timing bounds remain valid.

If the parent is unauthorized, the gate returns `HORUS_TRAFFIC_GATE_DENIED` and does not load or render Turnstile.

## Gate-to-parent messages

The gate emits only bounded status messages:

- `HORUS_TRAFFIC_GATE_READY`
- `HORUS_TRAFFIC_GATE_PASS`
- `HORUS_TRAFFIC_GATE_ERROR`
- `HORUS_TRAFFIC_GATE_TIMEOUT`
- `HORUS_TRAFFIC_GATE_DENIED`

Every response includes the exact bound `pageNonce` and `protocolVersion`. Final communication uses the exact learned parent origin as `postMessage` target; wildcard `*` is not used.

Task 50 will implement the parent-side Loader checks for `event.origin`, `event.source`, protocol version, and nonce before accepting a result.

## Token handling

The Turnstile success callback intentionally declares no token parameter. The response field is disabled. A success token is therefore never stored by Horus code and is never sent to:

- the parent page;
- Laravel;
- a Worker;
- Siteverify;
- an Horus API;
- analytics;
- localStorage;
- cookies;
- the database.

After a terminal state, the widget is removed on a best-effort basis.

## State machine

The static gate distinguishes:

- `BOOTING`
- `PARENT_VALIDATED`
- `TURNSTILE_LOADING`
- `CHALLENGE_RUNNING`
- `PASSED`
- `ERROR`
- `TIMEOUT`
- `DENIED`

`ERROR` is a technical client state and must never be described as a bot verdict.

## Admin test mode

A bounded client test mode exists only when the actual parent origin is exactly:

```text
https://app.horusmedia.net
```

The Admin parent may supply a candidate public Turnstile sitekey for a browser-only rendering test. Test mode does not authenticate the Admin, does not call Siteverify, and does not prove traffic validity. Task 49 does not add an Admin UI for this mode.

CI/browser tests use Cloudflare's official current Invisible test sitekeys:

- always pass: `1x00000000000000000000BB`;
- always fail: `2x00000000000000000000BB`.

Those test keys are present only in test code and are not embedded in the production gate asset.

## CSP and frame policy

Only the gate path receives the Turnstile-specific CSP. It allows the same-origin Horus gate JavaScript plus `https://challenges.cloudflare.com` for the Turnstile script/frame/network path required by current documentation.

`frame-ancestors https:` permits HTTPS Publisher embedding at the browser policy layer. Authorization is then narrowed dynamically by the gate's same-origin static Site configuration check against the actual `event.origin` hostname.

The gate path is not served with `X-Frame-Options: DENY` or `SAMEORIGIN`. Unrelated Horus paths do not receive a weakened frame policy.

## Privacy

Horus persists no Turnstile token, fingerprint, visitor ID, IP address, browser identifier, or Publisher visitor history from this gate. Task 49 adds zero Horus-side visitor telemetry and zero per-view paid Horus backend component.

## Task boundary

Task 49 does not modify GAM boot, Prebid boot, Direct JS boot, Loader monetization state, or Admin Control Center. The permanent Loader remains behaviorally unchanged until Task 50 implements the parent side.
