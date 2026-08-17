# Horus Client Traffic Gate

Status: Task 48 control-plane and static-configuration foundation.

The **Horus Client Traffic Gate** is an optional **client-only soft traffic filter** intended for a later Cloudflare Turnstile Invisible browser integration. Task 48 does not execute Turnstile in publisher browsers and does not alter ad-serving behavior.

## Product boundary

The Client Traffic Gate is not a traffic-verification or IVT certification system. Horus must not describe it as **HUMAN VERIFIED**, **VALID TRAFFIC**, **BOT VERIFIED**, or **IVT CLEARED**. Appropriate language includes **CLIENT TRAFFIC GATE**, **CLIENT GATE PASSED**, and **SOFT TRAFFIC FILTER**.

Task 48 adds no Turnstile script or iframe, Loader blocking, browser activity recovery implementation, Siteverify request, Worker validation, server-side token validation, per-visitor Laravel request, pageview database write, traffic telemetry, or IVT score.

The existing Publisher Registration/Application Turnstile integration is separate. Its Siteverify, secret, hostname/action checks, replay protection, and production fail-closed behavior are unchanged.

## Global typed settings

The editable non-secret settings use the existing Typed Global Settings architecture:

- `traffic_gate.enabled`
- `traffic_gate.site_key` — public Turnstile site key
- `traffic_gate.policy`
- `traffic_gate.initial_wait_ms`
- `traffic_gate.max_wait_ms`
- `traffic_gate.retry_interval_ms`
- `traffic_gate.activity_recovery_enabled`

There is no Turnstile secret in this namespace.

The gate origin is intentionally not a typed editable setting. It comes from constrained Horus configuration:

```dotenv
HORUS_TRAFFIC_GATE_ORIGIN=https://verify.horusmedia.net
```

The only accepted effective origin is HTTPS `verify.horusmedia.net` with no credentials, port, query, or fragment. Admin may see the effective origin but cannot redirect the gate to an arbitrary third-party URL.

## Policies

Exactly three global/effective policies exist: `STRICT`, `BALANCED`, and `PERMISSIVE`.

- `STRICT` — only a future PASS result authorizes monetization.
- `BALANCED` — PASS authorizes immediately; a future technical failure may recover through trusted browser activity and must not automatically be labelled a bot.
- `PERMISSIVE` — PASS authorizes immediately; a future technical failure/stall may soft-allow after a bounded timeout.

Task 48 publishes these policy identifiers only. Their browser behavior is deferred to Task 50. Default policy: `BALANCED`.

## Timing bounds and defaults

| Setting | Default | Minimum | Maximum |
| --- | ---: | ---: | ---: |
| `initial_wait_ms` | 1500 | 500 | 5000 |
| `max_wait_ms` | 6000 | 2000 | 15000 |
| `retry_interval_ms` | 1500 | 500 | 10000 |

`max_wait_ms` must be greater than or equal to `initial_wait_ms`. Zero, negative, arbitrary-string, unbounded, and minutes-long waits are rejected by the typed settings layer. Corrupt or invalid persisted timing state still resolves to `INVALID_CONFIGURATION` rather than activating the browser gate.

`activity_recovery_enabled` defaults to `true`; Task 48 does not implement activity recovery.

## Per-Site overrides

`site_serving_settings` owns the constrained Site override contract.

Gate state: `INHERIT`, `ENABLED`, `DISABLED`.

Policy: `INHERIT`, `STRICT`, `BALANCED`, `PERMISSIVE`.

Timings remain global. A Site cannot define arbitrary JavaScript behavior or an arbitrary gate origin.

`TrafficGateConfigurationResolver` is the canonical effective-configuration source. Controllers and Blade views consume its output rather than reconstructing enablement/readiness rules.

## Readiness and activation safety

Published readiness values are `READY`, `DISABLED`, `CONFIGURATION_REQUIRED`, and `INVALID_CONFIGURATION`.

A requested enabled state becomes effective only when the public site key exists, the fixed origin is valid, timings are valid, and the global emergency disable is not active. Incomplete or invalid Traffic Gate configuration never pauses existing GAM, Prebid, or Direct JS delivery by itself.

## Public static payload

The existing Site static configuration receives one additive `trafficGate` object:

```json
{
  "trafficGate": {
    "enabled": true,
    "provider": "CLOUDFLARE_TURNSTILE_CLIENT_ONLY",
    "gateOrigin": "https://verify.horusmedia.net",
    "siteKey": "PUBLIC_SITE_KEY",
    "policy": "BALANCED",
    "timings": {
      "initialWaitMs": 1500,
      "maxWaitMs": 6000,
      "retryIntervalMs": 1500
    },
    "activityRecoveryEnabled": true,
    "readiness": "READY"
  }
}
```

Only public/non-secret values are emitted and the payload continues through `PublicPayloadGuard`. No Cloudflare token, Turnstile secret, Worker secret, Laravel credential, session data, or Publisher-private information is emitted.

The Loader is intentionally unchanged by Task 48 and ignores this new additive configuration until a later runtime task implements it.

## Static Delivery and emergency bypass

Routine global changes and Site overrides republish active Production configuration through existing `NORMAL` Static Delivery batching. No controller deploys directly to Cloudflare/GitHub and the browser does not fetch configuration from Laravel.

Operations adds a platform-only `TRAFFIC_GATE` emergency control. The global edge artifact exposes `controls.trafficGateDisabled`.

A platform Traffic Gate disable wins over every Site `ENABLED` override and queues active Production configurations with `URGENT` priority so operators do not wait for the normal batching window during a gate incident. Re-enabling follows normal publication semantics.

## Audit

Task 48 records dedicated events for global Traffic Gate enabled/disabled changes, policy changes, timing changes, public site-key replacement, activity-recovery setting changes, Site override changes, and emergency disable/clear. The feature contains no secret to record.
