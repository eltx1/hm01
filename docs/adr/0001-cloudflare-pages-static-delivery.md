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
transaction. It does no file or network I/O. The once-per-minute scheduler:

1. selects due outbox items and keeps only the newest site/environment item;
2. groups normal changes while urgent pauses and platform engine kill switches bypass the delay;
3. enforces monthly deployment and file safety budgets;
4. builds a complete deterministic snapshot with bounded immutable retention;
5. commits the managed paths to `edge-delivery` through a least-privilege GitHub
   token referenced by `env:` or `file:`; and
6. sends `repository_dispatch` to `.github/workflows/cloudflare-pages-delivery.yml`.

The workflow validates the sanitized tree and uses `cloudflare/wrangler-action`
for Pages Direct Upload. Cloudflare credentials exist only in GitHub Secrets.
Laravel records `UPLOADING` after dispatch; it records `DEPLOYED` only after the
workflow concludes successfully. Missing Cloudflare secrets produce successful
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
- The database retains complete history; active Pages snapshots retain a bounded
  number of immutable files per site/environment.
