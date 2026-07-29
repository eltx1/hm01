# Horus Media White Label Ad Network Platform

Control plane for Horus Media's publisher operations, advertising configuration,
direct advertiser campaigns, modular native demand, aggregated reporting,
revenue shares, and billing.

Repository: `eltx1/hm01`

Production domains:

- Marketing: `https://horusmedia.net`
- Dashboard: `https://app.horusmedia.net`
- Advertising CDN: `https://cdn.horusmedia.net`

## Fixed serving model

`HORUS_GAM` is the main, default ad server for every newly created website.
Administrators may later select `MCM_PARTNER_GAM`, `PUBLISHER_GAM`,
`DIRECT_NATIVE_ONLY`, or `PAUSED` per website. The permanent publisher loader
does not change when the selection changes.

The browser requests configuration from the CDN, runs Prebid.js and Google
Publisher Tag, and sends advertising traffic directly to the selected serving
network. Optional approved native demand may run through GAM third-party
creatives or public direct JavaScript fallback. Ad requests never transit the
Laravel application.

Direct advertisers use the Horus Media dashboard. Campaigns are split into
isolated GAM network instances based on each selected website's configured
network, with Horus GAM used by default.

## Implemented scope

This release establishes the Laravel 12 control-plane foundation:

- PHP 8.2+ and MySQL production configuration
- database sessions, cache, queue, and cron-compatible queue draining
- health check at `/up`
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
- local inventory, static CDN configuration, GPT, and permanent Horus Loader
- browser-side Prebid.js and centralized, idempotent Prebid GAM automation
- direct advertiser campaigns, validated creatives, billing profiles, invoices,
  multi-network GAM deployment, lifecycle synchronization, reports, and drift detection
- modular MGID, Taboola, Speakol, Outbrain, and custom demand connectors with
  encrypted credential references, per-site and per-placement controls, GAM or
  direct-JS delivery, ads.txt, aggregated API/CSV reporting, and safe Loader fallback

Revenue ledger calculations and publisher payment execution remain future work.

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan horus:create-super-admin
npm ci
npm run build
php artisan test
```

Use PHP 8.2 or newer and enable PDO SQLite for the test suite. For local MySQL,
set the `DB_*` variables in `.env`.

## Production deployment

Build frontend assets before uploading. The application runs on any compatible
PHP 8.2+ web server with MySQL/MariaDB, writable Laravel storage, and cron. Point
the web document root to `public/`, create a private `.env`, migrate with
`--force`, and configure one scheduler cron. See the portable
[`deployment guide`](docs/DEPLOYMENT.md) and the optional
[`Hostinger profile`](docs/HOSTINGER_DEPLOYMENT.md). GitHub Actions produces a
provider-neutral release archive containing optimized Composer dependencies and
compiled frontend assets.

Use the normal scheduler or cron to run `php artisan campaigns:monitor --reconcile`
for direct campaign lifecycle, aggregated GAM report requests, and drift checks.

## Documentation

- [Product](docs/PRODUCT.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Database](docs/DATABASE.md)
- [GAM architecture](docs/GAM_ARCHITECTURE.md)
- [Inventory and Horus Loader](docs/INVENTORY_AND_LOADER.md)
- [Prebid architecture](docs/PREBID_ARCHITECTURE.md)
- [Prebid operations](docs/PREBID_OPERATIONS.md)
- [Direct campaigns](docs/DIRECT_CAMPAIGNS.md)
- [Native networks](docs/NATIVE_NETWORKS.md)
- [Reporting](docs/REPORTING.md)
- [Security](docs/SECURITY.md)
- [Publisher onboarding and websites](docs/PUBLISHER_ONBOARDING.md)
- [Portable deployment](docs/DEPLOYMENT.md)
- [Hostinger deployment](docs/HOSTINGER_DEPLOYMENT.md)
- [Roadmap](docs/ROADMAP.md)
