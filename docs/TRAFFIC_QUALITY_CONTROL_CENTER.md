# Horus Traffic Quality Control Center

## Product boundary

The Client Traffic Gate is a **client-only soft browser traffic filter**. Horus does not perform server-side Turnstile token validation for ad serving. The feature must not be described as human verification, bot verification, valid-traffic verification, fraud prevention, or IVT clearance.

No Cloudflare API integration or Cloudflare API token is required. Horus stores no per-visitor gate events, PASS counters, error beacons, browser fingerprints, or Turnstile tokens. The Cloudflare dashboard remains the external source for Turnstile widget challenge analytics.

## Admin location and RBAC

The canonical operator surface is **Admin → Operations → Traffic Quality**. `traffic_gate.manage` permits normal Client Traffic Gate administration. `traffic_gate.emergency_disable` permits the platform-wide emergency control. Publisher roles receive neither permission.

## Readiness

Global enable is blocked unless readiness is `READY`. The Admin readiness states are:

- `READY`
- `SITEKEY_MISSING`
- `GATE_ORIGIN_INVALID`
- `GATE_ASSET_NOT_CONFIGURED`
- `INVALID_TIMING`

Readiness is derived from Horus configuration and expected static assets only; it does not perform a per-visitor or external health request.

## Sitekey replacement

The public Sitekey uses a staged replacement flow:

1. Stage a candidate public Sitekey in the Admin session.
2. Run the Task 49 browser Admin-test protocol against `https://verify.horusmedia.net/traffic-gate/`.
3. Record only one local Admin result: `CLIENT PASS`, `CLIENT ERROR`, `CLIENT TIMEOUT`, or `GATE UNREACHABLE`.
4. Require `CLIENT PASS`, password confirmation, a reason, and explicit activation confirmation.
5. Activate the public Sitekey and publish affected active Sites through NORMAL Static Delivery.

The browser test sends `HORUS_TRAFFIC_GATE_HELLO` with protocol version 1, a fresh page nonce, `testMode: true`, and the candidate public Sitekey. The parent accepts a response only from the exact configured gate origin and exact iframe window. No Turnstile response token is returned to Horus.

## Policy and timing

Policies remain `STRICT`, `BALANCED`, and `PERMISSIVE`. Timing controls retain the bounded Task 48 ranges and `maxWaitMs >= initialWaitMs`. Trusted activity recovery is an explicit advanced control. Global timing changes use NORMAL Static Delivery.

## Static Delivery

Normal master, policy, timing, public Sitekey, and Site override changes use NORMAL delivery. Platform Emergency Disable Traffic Gate uses the existing `TRAFFIC_GATE` operational control and URGENT Static Delivery. Clearing an emergency returns to NORMAL delivery.

The Admin UI summarizes publication as `PENDING NORMAL BATCH`, `DEPLOYED`, or `FAILED`, with `PENDING URGENT` shown for Site-level outstanding urgent work.

## Site controls

Site state remains `INHERIT`, `ENABLED`, or `DISABLED`. Site policy remains `INHERIT`, `STRICT`, `BALANCED`, or `PERMISSIVE`. Bulk administration supports resetting selected Sites to `INHERIT`; there is intentionally no bulk “enable all Sites” action.

## Audit

Horus audits master enable/disable, emergency disable/clear, policy and timing changes, candidate Client Test result, public Sitekey activation, and Site override changes. Candidate test audit stores only a deterministic fingerprint of the public candidate plus the coarse Client Test result. No Turnstile token is stored.
