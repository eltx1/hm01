# Static Delivery Batching and Operations

## Active production automation

The five-minute GitHub `schedule` is a recovery mechanism, not a delivery SLA.
Production should use `cloudflare-pages-pipeline`: the existing once-per-minute
Laravel scheduler processes the durable outbox, uploads the immutable snapshot
to `edge-delivery`, and sends `repository_dispatch`. No operator or chat session
is involved in subsequent configuration changes. The active driver must not
silently switch to passive external sync when its credential is unavailable.

Activation requires a persistent, repository-scoped GitHub credential. For a
fine-grained token restricted to this repository, grant Contents read/write
(Git objects and repository dispatch) and Actions read (run/job verification).
Never persist the ephemeral Actions `GITHUB_TOKEN` on the server. Put the
persistent credential in the production environment secret
`HORUS_EDGE_GITHUB_TOKEN`; the normal release workflow installs it through pinned
SSH, stdin, a private file, and a `file:` reference. The installer defaults to a
no-write dry run unless `--apply` is supplied. It preserves unrelated environment
settings and writes a redacted local audit record. Production activation chooses
a five-minute batching interval; the general configuration default remains 30.

The provisioning step deliberately warns when that secret is absent. A green
code deployment without provisioning is **not** proof that automation is ready.
Run the read-only `php artisan static-delivery:automation-check` to check local
prerequisites. This command does not prove remote permissions or cron execution.
The release uses `--require-scheduler` to require a heartbeat within five
minutes, without manufacturing one. An actual automatic configuration
publication must also be checked.

Only one batch can remain in flight. A pending batch cannot overtake it. Failed
submissions retain their attempt count across replacement batches; retries stop
at the configured maximum. Unconfirmed uploads expire after 30 minutes by default
(`HORUS_STATIC_DELIVERY_CONFIRMATION_TIMEOUT_SECONDS`) and enter bounded backoff.
An accepted dispatch whose response was lost is reused when a running/successful
workflow for that exact immutable commit is visible. Missing credentials and
rejected dispatches are recorded as delivery failures, not success.

The Pages delivery job uses the `production` environment and fails on missing
Cloudflare credentials. Confirmation requires both a successful deployment step
and matching public CDN and Traffic Gate artifacts. A successful GitHub run with
stale public files remains unconfirmed. In active mode the legacy sync workflow
only wakes the server outbox and refreshes runtime configuration; it does not
publish an independent snapshot that could race the queued snapshot.

Acceptance after activation: save a normal configuration change; observe a
scheduler-created batch and a `repository_dispatch` run without manual dispatch;
verify the exact published manifest and placement payload; then save a second
change and repeat. The publisher needs no loader/code change. Do not claim a
five-minute guarantee: batching eligibility is followed by scheduler, runner,
deployment, and public verification time.

Horus static delivery preserves one pipeline: database outbox →
`StaticDeliveryManager` → deterministic snapshot → sanitized GitHub delivery
branch → Cloudflare Pages workflow. HTTP controllers never write to GitHub or
Cloudflare directly.

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
