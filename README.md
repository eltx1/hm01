# Horus Media White Label Ad Network Platform

Control plane for Horus Media's publisher operations, advertising configuration,
direct advertiser campaigns, modular native demand, aggregated reporting,
revenue shares, and billing.

Repository: eltx1/hm01

Production domains:

- Marketing: https://horusmedia.net
- Dashboard: https://app.horusmedia.net
- Advertising CDN: https://cdn.horusmedia.net

## Multi-engine serving model

Horus Media uses one permanent publisher Loader and three independent serving
engines: **GAM**, **Prebid**, and **Direct JS**. Serving mode describes how a
website is broadly operated; engine state describes which delivery capabilities
are active for its placements.

`HORUS_GAM` remains the default, first-class Horus-managed GAM mode and the
existing GAM path remains backward compatible. `MCM_PARTNER_GAM` and
`PUBLISHER_GAM` remain optional GAM modes. `HORUS_DIRECT` is the first-class
Horus-managed mode for a site that does not require any GAM connection.
`DIRECT_NATIVE_ONLY` remains a legacy/specialized direct-native mode and
`PAUSED` retains its existing meaning.

A site may therefore use GAM + Prebid + Direct JS, standalone Prebid + Direct
JS without GAM, or Direct JS alone. Prebid and Direct JS do not need to compete
with each other and may operate simultaneously across independent placements.
A single physical placement still has one clear renderer at a time.

```text
Permanent Horus Loader
    +-- GAM Engine       -> GPT -> selected GAM connection
    +-- Prebid Engine    -> GAM_BRIDGE or STANDALONE
    +-- Direct JS Engine -> approved provider JavaScript
```

For GAM-enabled sites, the browser continues to run the existing GPT/GAM path,
including the existing Prebid-to-GAM bridge. For GAM-less sites, standalone
Prebid is an approved architecture in which the winning bid is rendered directly
by the Loader, and Direct JS remains independent of both GAM and Prebid. The
standalone renderer is implemented incrementally by dedicated runtime work; the
architecture contract is defined in
[Multi-engine serving](docs/MULTI_ENGINE_SERVING.md).

The Loader, manifests, and versioned runtime configuration remain static CDN
assets. Advertising requests and other publisher runtime requests never transit
the Laravel application. Hostinger remains the control plane only.

Direct advertisers use the Horus Media dashboard. Existing GAM-backed campaign
deployment remains unchanged for GAM-enabled websites and continues to use the
selected website GAM connection, with Horus GAM as the default GAM mode.

## Current release status

The application code, test suite, production packaging, and control-plane
features are implemented through the latest release validation. The remaining
work is external go-live evidence: production hosting, real credentials,
scheduler/CDN configuration, a controlled pilot, and operational sign-off.

Use these documents as the release gates:

- [Go-live checklist](docs/GO_LIVE_CHECKLIST.md)
- [Controlled pilot runbook](docs/PILOT_RUNBOOK.md)
- [Security and recovery operations](docs/SECURITY_OPERATIONS.md)
- [Portable deployment guide](docs/DEPLOYMENT.md)
- [Hostinger deployment profile](docs/HOSTINGER_DEPLOYMENT.md)
- [Cron jobs](CRON_JOBS.md)
- [Cloudflare and CDN setup](CLOUDFLARE_SETUP.md)

## Implemented scope

This release establishes the Laravel 12 control plane and browser delivery
system:

- PHP 8.2+ and MySQL production configuration
- database sessions, cache, queue, and cron-compatible queue draining
- health check at /up
- centralized exception responses and structured JSON logs
- audit-log model, migration, recorder, and retention command
- responsive admin shell
- PHPUnit with an in-memory SQLite test environment
- Vite assets compiled before deployment
- organization-scoped authentication and account isolation
- system roles, permissions, secure invitations, and audited administration
- TOTP administrator two-factor authentication with single-use recovery codes
- organization, publisher, advertiser, contact, and white-label branding management
- interactive first-super-administrator bootstrap command
- publisher, advertiser, partner, and Horus Media dashboard shells
- seven-step publisher onboarding with encrypted payment details
- contracts, private contract files, status transitions, and revenue-share terms
- organization-scoped websites, authorized domains, and four verification modes
- audited website review, status, serving-mode, revenue-share, and emergency controls
- Google Ad Manager multi-network connection and synchronization layer
- local inventory, transactional static-delivery outbox, Cloudflare Pages pipeline,
  GPT, and permanent Horus Loader
- browser-side Prebid.js and centralized, idempotent Prebid GAM automation
- direct advertiser campaigns, validated creatives, billing profiles, invoices,
  multi-network GAM deployment, lifecycle synchronization, reports, and drift detection
- modular MGID, Taboola, Speakol, Outbrain, and custom demand connectors with
  encrypted credential references, per-site and per-placement controls, GAM or
  direct-JS delivery, ads.txt, aggregated API/CSV reporting, and safe Loader fallback
- unified reporting, reconciliation, revenue-share calculations, statements,
  adjustments, partial payments, advertiser reports, and invoice balances
- Publisher earnings/payment self-service and Admin Finance Operations with
  readiness-gated close, maker-checker payouts, immutable settlements, masked
  verification queues, versioned rules, adjustments, and reconciliation
- first-party organization-scoped Support with threaded public messages,
  confidential Horus notes, private attachments, controlled lifecycle, and SLA
- durable role-targeted Notification Center, idempotent SLA/email automation,
  state-transition alerts, and source-driven Admin/Publisher Action Center

## Local setup

~~~bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan horus:create-super-admin
npm ci
npm run build
php artisan test
~~~

Use PHP 8.2 or newer and enable PDO SQLite for the test suite. For local MySQL,
set the DB_* variables in .env.

## Production deployment

Build frontend assets before uploading. The application runs on any compatible
PHP 8.2+ web server with MySQL/MariaDB, writable Laravel storage, and cron.
Point the web document root to public/, create a private .env, migrate with
--force, and configure one scheduler cron. GitHub Actions produces a
provider-neutral release archive containing optimized Composer dependencies and
compiled frontend assets.

Use the normal scheduler or cron to run campaigns:monitor --reconcile for
direct campaign lifecycle, aggregated GAM report requests, and drift checks.

## Documentation

- [Product](docs/PRODUCT.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Multi-engine serving](docs/MULTI_ENGINE_SERVING.md)
- [Database](docs/DATABASE.md)
- [GAM architecture](docs/GAM_ARCHITECTURE.md)
- [Inventory and Horus Loader](docs/INVENTORY_AND_LOADER.md)
- [Prebid architecture](docs/PREBID_ARCHITECTURE.md)
- [Prebid operations](docs/PREBID_OPERATIONS.md)
- [Ads.txt compliance](docs/ADS_TXT_COMPLIANCE.md)
- [Supply-chain identity](docs/SUPPLY_CHAIN_IDENTITY.md)
- [Supply Chain Compliance Control Center](docs/SUPPLY_CHAIN_COMPLIANCE.md)
- [Direct campaigns](docs/DIRECT_CAMPAIGNS.md)
- [Native networks](docs/NATIVE_NETWORKS.md)
- [Reporting](docs/REPORTING.md)
- [Publisher earnings and payments](docs/PUBLISHER_FINANCE.md)
- [Admin Finance Operations](docs/FINANCE_OPERATIONS.md)
- [Support Ticket System](docs/SUPPORT_SYSTEM.md)
- [Notification Center and Action Center](docs/NOTIFICATION_CENTER.md)
- [Security](docs/SECURITY.md)
- [Publisher onboarding and websites](docs/PUBLISHER_ONBOARDING.md)
- [Portable deployment](docs/DEPLOYMENT.md)
- [Hostinger deployment](docs/HOSTINGER_DEPLOYMENT.md)
- [Go-live checklist](docs/GO_LIVE_CHECKLIST.md)
- [Pilot runbook](docs/PILOT_RUNBOOK.md)
- [Security operations](docs/SECURITY_OPERATIONS.md)
- [Roadmap](docs/ROADMAP.md)

## GAM-less controlled pilot baseline (Task 20)

The multi-engine program is now hardened for a controlled GAM-less pilot. The
release-gated architecture is still one permanent Horus Loader with three
independent engines: `GAM`, `Prebid (GAM_BRIDGE or STANDALONE)`, and `Direct JS`.
`HORUS_DIRECT` may be production-healthy with standalone Prebid and/or Direct JS
without a GAM connection. See `docs/PILOT_RUNBOOK.md` for PILOT A-E and provider
activation gates. OneTag is an optional pinned Prebid adapter; ExoClick has a
reviewed asynchronous Direct JS recipe; Adsterra remains operator-tag-driven
through the generic reviewed importer rather than a guessed hard-coded recipe.

