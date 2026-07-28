# Horus Media White Label Ad Network Platform

Control plane for Horus Media's publisher operations, advertising configuration,
reporting imports, revenue shares, and publisher payments.

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
network. Ad requests never transit the Laravel application.

## Foundation scope

This release establishes the Laravel 12 control-plane foundation:

- PHP 8.2+ and MySQL production configuration
- database sessions, cache, queue, and cron-compatible queue draining
- health check at `/up`
- centralized exception responses and structured JSON logs
- audit-log model, migration, recorder, and retention command
- responsive admin shell
- PHPUnit with an in-memory SQLite test environment
- Vite assets compiled before deployment

Publisher management, GAM API operations, bidders, campaigns, reporting imports,
revenue calculations, and payments are intentionally not implemented yet.

## Local setup

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate
npm ci
npm run build
php artisan test
```

Use PHP 8.2 or newer and enable PDO SQLite for the test suite. For local MySQL,
set the `DB_*` variables in `.env`.

## Production deployment

Build frontend assets before uploading. Upload the application plus `vendor/`
and built `public/build/` through hPanel, point the dashboard document root to
`public/`, populate a private `.env`, migrate with `--force`, and configure the
single scheduler cron described in
[`docs/HOSTINGER_DEPLOYMENT.md`](docs/HOSTINGER_DEPLOYMENT.md).

## Documentation

- [Product](docs/PRODUCT.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Database](docs/DATABASE.md)
- [GAM architecture](docs/GAM_ARCHITECTURE.md)
- [Prebid architecture](docs/PREBID_ARCHITECTURE.md)
- [Native networks](docs/NATIVE_NETWORKS.md)
- [Reporting](docs/REPORTING.md)
- [Security](docs/SECURITY.md)
- [Hostinger deployment](docs/HOSTINGER_DEPLOYMENT.md)
- [Roadmap](docs/ROADMAP.md)
