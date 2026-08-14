# ADR 0001: Cloudflare Pages static delivery pipeline

- Status: Accepted and implemented
- Date: 2026-08-01

## Context

Publisher browsers must load the Horus Loader and public configuration without
calling Laravel or Hostinger. Static asset requests on Pages are free and
unlimited only when they do not invoke a Pages Function. Builds, files, projects,
Functions, and storage have separate limits. Current limits must be checked in
Cloudflare's [Pages limits](https://developers.cloudflare.com/pages/platform/limits/)
before changing configured safety budgets.

The former implementation wrote JSON to `HORUS_STATIC_CONFIG_ROOT` inside the
admin database transaction. That coupled Hostinger to the CDN filesystem and
reported publication before an edge deployment was proven.

## Options considered

1. Pages Direct Upload REST API from Laravel: avoids Node, but puts a Cloudflare
   token on Hostinger and requires maintaining Cloudflare's multipart upload
   protocol in PHP. Rejected for the initial production path.
2. Wrangler Direct Upload in GitHub Actions: officially supported, keeps the
   Cloudflare token in GitHub Secrets, and provides durable run evidence. Chosen
   as the final Pages deployment mechanism.
3. Sanitized delivery branch: Laravel atomically commits only public static files
   using the Git Data API, then dispatches the Wrangler workflow. Chosen as the
   transport between the control plane and CI. The branch is bootstrapped as an
   orphan and contains no application source or secrets.
4. Local filesystem: retained only for tests and local development. It is not a
   production CDN mechanism.

## Decision

`SiteConfigPublisher` creates a `ConfigVersion` and `StaticDeliveryItem` in one
transaction. It does no file or network I/O. The once-per-minute scheduler is a
lightweight eligibility check:

1. assigns every `NORMAL` outbox item to the next deterministic UTC boundary
   configured by `HORUS_STATIC_DELIVERY_BATCH_INTERVAL_MINUTES` (30 minutes by
   default, producing `HH:00` and `HH:30` boundaries);
2. selects all due outbox items in one locked operation and keeps only the newest
   site/environment item, so one boundary produces at most one normal snapshot;
3. leaves `URGENT` pauses and platform engine kill switches immediately eligible;
4. builds a complete deterministic snapshot from confirmed versions plus the
   versions selected for this batch, excluding later normal windows even if a
   scheduler invocation starts late;
5. deduplicates an already confirmed manifest before any remote submission or
   deployment-budget consumption;
6. enforces monthly deployment and file safety budgets;
7. commits the managed paths to `edge-delivery` through a least-privilege GitHub
   token referenced by `env:` or `file:`; and
8. sends `repository_dispatch` to `.github/workflows/cloudflare-pages-delivery.yml`.

The Admin-only **Deploy Pending Changes Now** action acquires the same distributed
database-cache lock, changes currently pending `NORMAL` items to immediately
eligible, and invokes `StaticDeliveryManager`. It still applies payload, secret,
checksum, manifest, file, and deployment-budget safeguards. It does not call
GitHub or Cloudflare from the controller, does not convert normal work to urgent,
and does not force an identical or empty deployment. Manual reason, actor, result,
and batch evidence are audited.

The workflow validates the sanitized tree and uses `cloudflare/wrangler-action`
for Pages Direct Upload. Cloudflare credentials exist only in GitHub Secrets.
Laravel records the protected `UPLOADING` attempt immediately before invoking
the driver; it records `DEPLOYED` only after the workflow concludes successfully.
Missing Cloudflare secrets produce successful
validation with an explicit skipped-live-deploy message, never a fake deployment.

No `functions/`, `_worker.js`, Worker, KV, D1, Durable Object, or R2 resource is
used in publisher runtime. Headers are supplied by `_headers`.

## Consequences

- Hostinger load scales with administrators, scheduled orchestration, reporting,
  finance, and auditing—not page views, slots, bids, refreshes, or impressions.
- A GitHub credential with repository Contents read/write and Actions read access
  is required privately on the control plane. Cloudflare Pages Edit credentials
  remain in GitHub Secrets.
- Delivery is eventually consistent. Admin UI distinguishes Pending, Batching,
  Uploading, Deployed, Failed, and Retry Scheduled.
- With no eligible outbox items, no batch, delivery commit, Cloudflare deployment,
  or budget use occurs. The scheduler may safely continue checking every minute.
- Scheduler processing, manual deployment, reconciliation, and retry share one
  distributed lock in the configured cache store (database in production), while
  item row locks and durable status transitions preserve idempotency.
- The database retains complete history; active Pages snapshots retain a bounded
  number of immutable files per site/environment.
