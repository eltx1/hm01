# Architecture

## System boundaries

Horus Media Platform is the control plane. It stores operational configuration,
connector metadata, and aggregated reporting. It never sits in the advertising
request path.

```mermaid
flowchart LR
    A["Publisher Website"] --> B["Horus Loader<br/>cdn.horusmedia.net"]
    B --> C["Prebid.js"]
    C --> D["Google Publisher Tag"]
    D --> E{"Selected GAM Network<br/>Default: HORUS_GAM"}
    E --> F["HORUS_GAM"]
    E --> G["MCM_PARTNER_GAM"]
    E --> H["PUBLISHER_GAM"]
    F --> I["Demand Sources"]
    G --> I
    H --> I
    C --> I
```

`DIRECT_NATIVE_ONLY` branches from loader configuration to configured native
connectors, and `PAUSED` prevents slot activation. They are omitted above to
keep the required GAM path legible.

## Control-plane workflow

```mermaid
flowchart TD
    A["Horus Media Admin Dashboard"] --> B["Publishers"]
    B --> C["Websites"]
    C --> D["Placements"]
    D --> E["Direct Advertisers"]
    E --> F["Campaigns"]
    F --> G["GAM API"]
    G --> H["Reports"]
    H --> I["Revenue Shares"]
    I --> J["Publisher Payments"]
```

This diagram is a capability sequence, not a claim that all modules exist in
the foundation release.

## Runtime components

- `app.horusmedia.net`: Laravel control plane and dashboard
- `cdn.horusmedia.net`: versioned loader, browser bundles, and published
  configuration artifacts
- MySQL: transactional control-plane data, database sessions, cache, queue,
  audit log, and aggregated reporting in later phases
- cron: Laravel scheduler once per minute
- browser: Prebid.js auctions, GPT setup, and direct advertising requests
- GAM and external networks: serving and source reporting systems

## Configuration publication

A later release will publish immutable, cacheable configuration snapshots to
the CDN. The loader resolves the current snapshot by stable site key. Publication
must be atomic, versioned, idempotent, auditable, and support dry-run for every
external write.

## Publisher onboarding control plane

The implemented module stores publisher commercial terms, encrypted payment
profiles, private contract documents, websites, authorized domains, verification
attempts, reviews, internal notes, status history, serving settings, and serving
mode history. The website owns one stable public key used in the permanent
loader tag. Its serving mode defaults independently in both `sites` and
`site_serving_settings` to `HORUS_GAM`; changing either operational selection is
performed transactionally without changing the public key.

Domain checks support HTML meta, well-known text file, DNS TXT, and audited
manual verification. HTTP verification accepts public DNS targets only and does
not follow redirects, protecting the control plane from internal-network fetches.

## Portable hosting constraints

There is no production dependency on Docker, Redis, Supervisor, WebSockets,
permanent workers, or a Node.js runtime. Composer dependencies and Vite assets
are prepared before upload. Cron invokes the scheduler; scheduled database queue
work drains with `--stop-when-empty`. These constraints preserve compatibility
with Hostinger while also supporting Apache, nginx, VPS, managed cloud, and
other standards-compliant PHP/MySQL hosts.
