# Architecture

## Overview

Horus Media Platform is divided into two planes.

### Control plane

Hosted on `app.horusmedia.net` using PHP, Laravel, MySQL, and cron jobs.

Responsibilities:

- Users and permissions
- Publishers and advertisers
- Websites and placements
- GAM connection management
- Campaign management
- Static configuration generation
- Report imports
- Revenue calculations
- Statements and payments
- Audit logs

### Delivery plane

Runs outside the Laravel request cycle.

Flow:

```text
Publisher website
  -> hm-loader.js
  -> static site configuration
  -> Prebid.js
  -> Google Publisher Tag
  -> selected GAM network
  -> demand sources
```

## Serving modes

- `HORUS_GAM` is the default.
- `MCM_PARTNER_GAM` is optional.
- `PUBLISHER_GAM` is optional.
- `DIRECT_NATIVE_ONLY` is optional.
- `PAUSED` disables ad delivery.

The serving mode is resolved per website. The publisher installation code remains unchanged.

## Domains

- `horusmedia.net`: company website
- `app.horusmedia.net`: application dashboard
- `cdn.horusmedia.net`: loader, Prebid builds, and static configurations

## Configuration delivery

The backend generates versioned static JSON configuration files. The loader fetches these files from the CDN. No Laravel request is required for each impression.

## Google Ad Manager

The system must support multiple GAM connections. `HORUS_GAM` is the primary connection and default target. Remote object IDs are stored locally for synchronization and idempotency.

## Prebid

Prebid.js runs in the browser. Bidder configuration is generated per website and placement. GAM setup is automated through the platform and stored with local-to-remote mappings.

## Reporting

Reports are imported from GAM and external demand sources by cron. Data is stored in hourly or daily aggregates. Raw bid and impression events are not stored in the MVP.

## Production constraints

The production release must run on Hostinger shared or cloud hosting without root access, Docker, Redis, Supervisor, WebSockets, or a permanent Node.js runtime.
