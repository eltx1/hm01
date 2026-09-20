# Static Delivery Batching and Operations

## Active production automation

Production uses `cloudflare-pages-direct`: the existing once-per-minute Laravel
scheduler processes the durable outbox and submits static snapshots directly to
Cloudflare Pages. GitHub Actions continues building and deploying application
releases over the existing pinned SSH connection. Routine configuration delivery
has no dependency on GitHub schedule timing or a persistent GitHub API token.

The normal production release reuses the existing `CLOUDFLARE_API_TOKEN`,
`CLOUDFLARE_ACCOUNT_ID`, Pages project variable, and SSH secrets. After a healthy
application release, it transfers the Cloudflare credential on stdin through SSH
to `/home/horusapp/shared/secrets/edge-cloudflare-token` (0600), writes a `file:`
reference in the shared environment, rebuilds the config cache, and reloads FPM
using the deployment's existing command. No new secret needs to be created.
The installer supports no-write dry run, preserves unrelated environment settings,
and records a redacted audit entry. It configures five-minute batch boundaries;
the portable application's default remains 30 minutes.

`php artisan static-delivery:automation-check --require-scheduler` is read-only.
It requires a real scheduler heartbeat in the preceding five minutes and a
readable credential. It does not manufacture a heartbeat or prove API permissions.
Acceptance still requires an actual scheduler-created batch, Cloudflare deployment
success, and exact public CDN plus Traffic Gate verification.

The driver follows Cloudflare Pages Direct Upload: scoped upload JWT, missing-asset
check, content-addressed uploads, and a deployment manifest with `_headers`.
BLAKE3 asset identifiers match Wrangler and are tested against official BLAKE3
vectors. No Node runtime or additional PHP extension is installed on production.
Headers, custom domains, Turnstile, Click Guard and publisher loader contracts are
unchanged. This driver rejects Worker/functions snapshots; it only publishes the
same static snapshot that the existing Wrangler workflow used.

Only one outbox batch can be in flight. Matching latest production deployments
are reused after a lost response; older deployments cannot suppress a required
publish. Attempts persist across replacement batches and stop at the configured
maximum. Unconfirmed batches expire after 30 minutes by default
(`HORUS_STATIC_DELIVERY_CONFIRMATION_TIMEOUT_SECONDS`). Upload execution has a
100-second window; provider errors are redacted and enter bounded backoff.
A Cloudflare success response with stale public files is not marked deployed.

In direct mode the legacy sync workflow refreshes queued runtime configuration
and checks prerequisites, but does not invoke the processor or upload independent
snapshots. The server scheduler performs the publication. A bounded read-only observer
checks `--require-scheduler --require-idle` for up to 12 minutes and reports the
confirmed batch/manifest or the actual pending/error state; it cannot publish.
Passive mode is refused
for automatic sync events; its explicit manual recovery path remains available.
The optional GitHub pipeline driver is retained for existing installations and
never silently falls back to passive sync when credentials are missing.

Acceptance: save a configuration, observe the next eligible scheduler batch and
matching deployment, then repeat with a second change. No manual dispatch or CDN
refresh is part of this test. Five minutes describes batching eligibility, not a
hard delivery SLA; scheduler execution, provider upload, propagation and public
verification add time. HTTP controllers only enqueue changes, never publish them.

Protocol references:
- https://developers.cloudflare.com/api/resources/pages/subresources/projects/subresources/deployments/methods/create/
- https://github.com/cloudflare/workers-sdk/blob/main/packages/wrangler/src/pages/upload.ts
- https://github.com/cloudflare/workers-sdk/blob/main/packages/deploy-helpers/src/deploy/helpers/hash.ts

## Eligibility modes

| Mode | Eligibility | Budget | Intended use |
| --- | --- | --- | --- |
| `NORMAL` | Next deterministic UTC boundary (`HH:00` or `HH:30` by default) | Normal capacity only | Routine configuration and monetization changes, including Client Traffic Gate settings and Site overrides |
| `URGENT` | Immediate | Total capacity, including emergency reserve when required | Existing safety events such as emergency pause, platform engine kill switches, and a platform Client Traffic Gate emergency disable |
| Deploy Now | Immediately accelerates currently pending `NORMAL` items | Still normal capacity only | Intentional Admin testing or an operational need to publish before the boundary |
| No changes | No batch and no remote action | No consumption | Scheduler wake-up or Deploy Now against an empty queue |

`HORUS_STATIC_DELIVERY_BATCH_INTERVAL_MINUTES` defaults to `30`. Boundaries are
computed from UTC epoch buckets rather than `created_at + interval`, preventing
independent changes from creating deployments only minutes apart. The scheduler
continues to check every minute, so a boundary does not inherit another
thirty-minute scheduler delay.

All due items are locked and coalesced. Multiple versions for the same
site/environment reduce to the highest version and older due items become
`SUPERSEDED`. A newer urgent version also supersedes an older normal item that
was still waiting for its boundary. Snapshot selection includes confirmed state
plus the versions owned by the current batch, so a later normal window cannot be
published early merely because the scheduler started after a boundary. The
remote artifact remains a complete deterministic snapshot.

The platform `TRAFFIC_GATE` operational control is additive to the existing
static edge-control artifact. Disabling it is an incident-recovery action and is
eligible immediately as `URGENT`; changing normal gate enablement, policy,
timings, public site key, activity-recovery setting, or Site override remains
`NORMAL`. The Traffic Gate never creates a separate deployment backend.

## Deploy Now safeguards

Operations → Static Delivery exposes **Deploy Pending Changes Now** only to an
internal Horus user with `operations.manage`, after authentication, verified
account, Admin 2FA, sensitive-action rate limiting, current-password validation,
explicit confirmation, and an operational reason.

The action:

1. acquires the same distributed cache lock used by scheduled processing,
   reconciliation, and retry;
2. row-locks pending normal items and makes them immediately eligible;
3. invokes `StaticDeliveryManager` with the initiating Admin as batch actor;
4. retains public-payload, secret, checksum, file-limit, manifest deduplication,
   and monthly-budget checks; and
5. audits `PROCESSED`, `NO_PENDING`, `BUSY`, or a categorized error with the
   supplied reason.

It never promotes normal work to urgent, never consumes emergency reserve for a
manual click, and never provides arbitrary force redeployment. Two clicks,
another Admin, the scheduler, or a retry cannot create a duplicate remote
deployment because they share the process lock and durable item transitions.

## Deduplication and budget evidence

A snapshot whose manifest hash equals an already confirmed deployment is marked
deployed with `is_deduplicated=true` and a `deduplicated_from_batch` reference.
No GitHub delivery commit or Cloudflare workflow is created, `submitted_at`
remains null, and monthly remote-deployment usage does not increase.

Monthly usage counts batches with a recorded remote submission attempt. The
normal ceiling is total configured budget minus emergency reserve. Only a
genuinely `URGENT` batch can proceed beyond that normal ceiling, up to the total
budget. A budget-blocked batch is deferred to the next month without being
misclassified as a remote upload failure.

## Operations evidence and warnings

The Admin view derives its state only from persisted outbox and batch evidence:
pending count and age, next normal boundary, current manifest, last successful
and last remote deployment, remote ID, file count/size, recent actors/timestamps,
and budget/reserve use. It does not invent Cloudflare infrastructure metrics.

The Operations view surfaces deduplicated warnings for approaching or exhausted
normal budget, emergency-reserve consumption, near-hard-limit file count, and
normal items overdue beyond their expected boundary plus the configured grace
period. Existing failed/retry-scheduled delivery warnings remain in the bounded,
aggregate-only Action Center provider instead of adding a second duplicate alert.

## Rollback

Rollback remains a new explicit immutable configuration version and outbox
record. This batching work does not alter its meaning or priority. Any existing
safety-critical rollback path that queues `URGENT` continues to bypass normal
boundaries.
