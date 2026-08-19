# Client Traffic Gate Production Hardening and Controlled Rollout

Status: Task 52 final engineering hardening for the **CLIENT TRAFFIC GATE** / **client-only soft traffic filter**.

This document does not describe human verification, valid-traffic certification, bot verification, fraud prevention, or IVT clearance. Horus intentionally does not perform server-side Turnstile token validation for ad serving, so a browser-side PASS is only a client-gate signal inside the Horus serving state machine.

## Official references reviewed for Task 52

Reviewed on 2026-08-19 before implementing the release tests:

- [Cloudflare Turnstile testing](https://developers.cloudflare.com/turnstile/troubleshooting/testing/)
- [Cloudflare Turnstile CSP](https://developers.cloudflare.com/turnstile/reference/content-security-policy/)
- [Cloudflare Turnstile / Invisible mode](https://developers.cloudflare.com/cloudflare-challenges/challenge-types/turnstile/)
- [Cloudflare Turnstile Privacy Addendum](https://www.cloudflare.com/turnstile-privacy-policy/)
- [Cloudflare Pages Functions routing](https://developers.cloudflare.com/pages/functions/routing/)
- [Cloudflare supported browsers](https://developers.cloudflare.com/cloudflare-challenges/reference/supported-browsers/)

These are operational/technical references, not legal certification.

## Deterministic automated Turnstile keys

Release CI uses Cloudflare's current official **Invisible** deterministic test sitekeys:

- always-pass: `1x00000000000000000000BB`
- always-fail: `2x00000000000000000000BB`

The keys exist only in tests. Static-delivery validation fails if either key appears in the production Traffic Gate JavaScript or compiled Horus Loader.

CI never calls the real production Turnstile widget. The Playwright browser suite intercepts `challenges.cloudflare.com` and supplies a deterministic client stub while preserving the real cross-origin frame and CSP boundaries. This keeps the suite reliable and prevents automated browsers from generating production challenge traffic.

## Browser matrix

Release-critical automation covers:

| Environment | Automated coverage |
| --- | --- |
| Chromium desktop | Yes |
| WebKit desktop | Yes |
| Chromium mobile-sized/touch | Yes |
| WebKit mobile-sized/touch | Yes |

Playwright WebKit is an automated WebKit compatibility target; it is not a claim that CI is running branded Safari on macOS/iOS. Before global rollout, the controlled Site must additionally be checked manually in a current real Safari build on supported Apple hardware.

The deterministic browser suite exercises JavaScript-enabled operation, cross-origin framing, strict Publisher CSP, a fast PASS, a delayed/slow PASS, blocked Turnstile resources, blocked gate framing, the official Invisible always-pass/always-fail behaviors, Publisher A/B origin isolation, and the Admin-test origin.

The existing Loader browser suite additionally covers iframe unavailability, privacy timing, trusted/synthetic activity, duplicate Loader evaluation, SPA navigation, refresh, and the policy state machine.

## Cross-origin authorization contract

The browser test matrix uses four independent origins:

- Publisher Origin A
- Publisher Origin B
- `https://verify.horusmedia.net`
- `https://app.horusmedia.net` for Admin test mode

Normal gate authorization is based on the actual browser `event.origin`, the exact `event.source`, the protocol version, the page nonce, the requested Horus public Site key, and the same-origin static Site configuration returned by the gate origin.

Publisher A requesting Publisher B's Site key/configuration is denied before Turnstile loads. A parent-supplied hostname is never trusted.

## Publisher CSP contract

A Publisher using a restrictive CSP needs to permit the Horus assets it actually embeds. For the Client Traffic Gate, the additional framing requirement is:

```text
frame-src https://verify.horusmedia.net
```

The Publisher does **not** need to add `https://challenges.cloudflare.com` to its own `script-src` or `frame-src` merely because Turnstile executes inside the cross-origin Horus gate document. The Task 52 real-browser suite proves the Publisher page can keep a strict CSP containing only the Horus gate frame allowance while the nested gate document loads the deterministic Cloudflare resources under its own CSP.

If the Publisher blocks `verify.horusmedia.net`, the Loader treats this as a technical gate-unavailable condition and applies the configured STRICT/BALANCED/PERMISSIVE policy. It is not classified as bot or invalid traffic.

## Gate-document CSP and frame headers

The dedicated static `/traffic-gate/*` surface carries the Turnstile-specific policy. Current Cloudflare documentation requires `https://challenges.cloudflare.com` in Turnstile `script-src` and `frame-src`; the Horus gate policy also permits its bounded Cloudflare connection path.

The gate has:

```text
frame-ancestors https:
```

at the browser policy layer so HTTPS Publisher origins can embed it. Dynamic authorization is then narrowed by the signed-independent static Horus Site configuration and actual `event.origin`.

The gate static surface must not receive `X-Frame-Options: DENY` or `SAMEORIGIN`.

`app.horusmedia.net` remains a different surface and stays non-frameable (`X-Frame-Options: DENY` plus the Admin CSP `frame-ancestors 'none'`). Task 52 includes an application regression for this isolation.

## Pure-static Pages routing contract

The Traffic Gate remains a static Pages asset. The static snapshot now publishes `_routes.json` with explicit exclusions for:

```text
/traffic-gate/*
/assets/traffic-gate/*
```

If unrelated Pages Functions are introduced later, those gate paths remain excluded from Function invocation. Static-delivery validation also continues to reject a `functions/` directory or `_worker.js` in the current Horus static snapshot.

This preserves the architecture: no Cloudflare Worker, no Pages Function, no Laravel per-page request, no Siteverify request, and no per-visitor database write for this feature. Cloudflare product plans/pricing can change in the future; Horus makes no promise about future external pricing.

## Pass performance and parallel boot

The Loader keeps three preparation paths parallel as early as dependencies permit:

1. static Site configuration and global controls start together;
2. once static Site configuration is available, the Client Traffic Gate and privacy/CMP resolution start independently; and
3. monetization begins only after the normal configuration/privacy prerequisites and the gate state both allow it.

`initialWaitMs` and `maxWaitMs` are failure/recovery bounds, not minimum delays. A fast valid PASS is processed immediately. Release tests assert that a PASS does not wait for either fallback timer.

## Failure and policy matrix

Canonical result/failure coverage includes PASS, ERROR, TIMEOUT, UNAVAILABLE, DENIED, slow PASS, a late valid PASS while BALANCED still owns the bound frame after initial-wait recovery, duplicate/late messages, wrong nonce, wrong origin, wrong source window, removed iframe, blocked iframe, and unavailable Turnstile script.

Policy behavior remains:

### STRICT

- PASS -> monetization may proceed when privacy/configuration also permit.
- ERROR / TIMEOUT / UNAVAILABLE / unresolved technical failure -> no monetization.
- DENIED -> blocked.

### BALANCED

- PASS -> monetization may proceed immediately when privacy/configuration also permit.
- Technical failure/stall -> `WAITING_FOR_ACTIVITY` when trusted activity recovery is enabled.
- Meaningful trusted activity -> `SOFT_ALLOWED`.
- Synthetic activity -> ignored.
- DENIED -> blocked.

A slow valid PASS that arrives while the initial-stall recovery still preserves the bound gate channel can become `PASSED` before a trusted-activity soft allow. Once a terminal decision removes the frame/listener, duplicate late messages are inert.

### PERMISSIVE

- PASS -> monetization may proceed immediately when privacy/configuration also permit.
- Technical failure/stall -> remains blocked until bounded `maxWaitMs`, then `SOFT_ALLOWED`.
- DENIED -> blocked under every policy.

## Multi-engine invariant

The Client Traffic Gate is a document-level prerequisite, not an engine-specific feature. The central Loader `canRequestAds()` predicate gates the shared scan/start path before:

- GAM/GPT;
- Prebid GAM bridge;
- standalone Prebid; and
- Direct JS/native direct demand.

Existing renderer ownership remains unchanged: one physical placement has one configured renderer owner. Task 52 does not add a second renderer, slot, bidder path, or demand-routing feature.

## Privacy disclosure boundary

Cloudflare's current Turnstile documentation states that enabling **Invisible** mode requires the website operator to reference Cloudflare's Turnstile Privacy Addendum in its own privacy policy. The addendum describes Cloudflare-side processing of client-side signals associated with Turnstile.

Horus therefore documents the integration to Publishers and maintains the Cloudflare Turnstile Privacy Addendum as an operational privacy reference. Each operator remains responsible for its own notices and legal assessment; Horus does not label this integration legally certified.

Horus itself stores no Turnstile response token, gate PASS/FAIL event, activity event, browser fingerprint, visitor identifier, or per-view gate record. Normal gate outcomes generate zero Horus analytics/reporting/control-plane beacons.

## Cloudflare analytics boundary

Cloudflare may expose challenge/widget analytics. Those metrics are external Cloudflare challenge-level observations. Because this Horus ad gate intentionally does not call Siteverify, Horus must not reinterpret challenge-solved counts as validated human traffic, valid traffic, IVT clearance, or a finance/reporting source.

No Horus revenue, payout, reconciliation, demand-routing, or reporting decision may use those challenge counts in Task 52.

## Controlled production rollout runbook

The global Client Traffic Gate remains **DISABLED by default** after Task 52 deployment.

### Step 1

Create a Cloudflare **Invisible** Turnstile widget.

Hostname:

```text
verify.horusmedia.net
```

### Step 2

Attach `verify.horusmedia.net` to the correct pure-static Horus Cloudflare Pages project.

### Step 3

Enter the public Turnstile sitekey in **Admin -> Operations -> Traffic Quality**. Do not enter or store the Turnstile secret in Horus.

### Step 4

Run **Admin Client Test** and require `CLIENT PASS` before normal Sitekey activation.

### Step 5

Keep the global switch disabled and enable the Client Traffic Gate on **one controlled Site** using the Site override.

### Step 6

Verify the controlled Site in real supported browsers, including current Chromium and current Safari/WebKit behavior.

### Step 7

Using browser developer tools, verify GAM/GPT, standalone Prebid, Prebid GAM bridge, and Direct JS do not start before the gate reaches an allowed state.

### Step 8

Observe real ad delivery and revenue behavior manually. Do not infer effectiveness percentages from challenge counts.

### Step 9

Expand only to a small controlled group of Publishers after the first Site behaves correctly.

### Step 10

Only after controlled evidence is satisfactory should Horus operators consider global enable.

## Incident runbook

If Turnstile causes widespread delivery problems:

```text
Admin
-> Operations
-> Traffic Quality
-> Emergency Disable Traffic Gate
```

Expected behavior:

1. Horus records the authorized emergency action and reason.
2. Static Delivery publishes the Traffic Gate emergency bypass with **URGENT** priority.
3. The Loader receives the static `trafficGateDisabled` control and bypasses the gate.
4. Normal Horus serving resumes subject to the existing master/engine/privacy controls.
5. No Loader release is required to disable the gate.

## Production-live decision

Repository CI can establish code/static/Admin readiness. It cannot establish that the account-level Cloudflare widget and `verify.horusmedia.net` custom domain are correctly configured in production.

Do **not** call the feature production-live until the external Cloudflare setup has completed Steps 1-4 above and one controlled real Publisher Site has completed Steps 5-8.
