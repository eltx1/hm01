# Portable Production Deployment

## Supported hosting model

Horus Media is a conventional Laravel application and is not tied to one hosting
provider. A compatible target provides PHP 8.2 or newer, required Laravel
extensions, MySQL 8 or compatible MariaDB, HTTPS, writable `storage/` and
`bootstrap/cache/`, and cron.

Production does not require Node.js, Docker, Redis, Supervisor, WebSockets, or a
permanent queue worker. Production dependencies and browser assets are built in
CI and shipped in the validated release artifact.

## Authoritative release pipeline

`Production release validation` is the canonical production build. It runs the
PHP/browser/database/deployment regression suite, smoke-tests the production
environment template, installs no-dev Composer dependencies, builds the ZIP with
`scripts/build-release.sh`, validates it with `scripts/validate-release.sh`, and
publishes `horus-media-platform-<sha>`.

A production host must deploy that validated ZIP rather than rebuilding
application code in place.

## First installation

For a brand-new installation:

1. extract the validated production artifact into the intended application path;
2. create a private `.env` from `.env.production.example` and populate real
   `APP_KEY`, database, mail, domain, and provider values;
3. make `storage/` and `bootstrap/cache/` writable by the PHP site user;
4. run `php artisan migrate --force`;
5. run required baseline seeders and `php artisan horus:create-super-admin` once;
6. run `php artisan storage:link` and `php artisan optimize` only after the code
   is in its final path;
7. smoke-test `/up`, authentication, tenant isolation, and private downloads;
8. configure exactly one once-per-minute Laravel scheduler entry.

Never copy a generated APP key or credentials from CI into production.

## Existing production upgrades

The supported Horus production upgrade model is documented in
`docs/PRODUCTION_DEPLOYMENT_FOUNDATION.md` and implemented by:

```text
ops/deploy/horus-bootstrap-atomic-layout.sh
ops/deploy/horus-atomic-deploy.sh
ops/deploy/write-mysql-client-config.php
.github/workflows/deploy-production.yml
```

The one-time bootstrap converts an existing real application directory into a
versioned release layout with one shared `.env` and one shared `storage/`.
Normal deployments then create a new immutable release, build Laravel caches in
that **final release path**, back up production state, run forward migrations,
atomically switch the stable application symlink, reload PHP-FPM, and verify
`/up`. A failed post-switch health check restores the previous application
symlink automatically.

Do not run `artisan optimize` in a temporary extraction directory and then move
the optimized application. Laravel bootstrap caches may contain absolute paths;
that deployment pattern can produce a production 500 after the move.

Normal upgrades never run `key:generate`, `db:seed`, `migrate:fresh`, or an
automatic migration rollback.

## Scheduler

Configure one scheduler only:

```cron
* * * * * /path/to/php /stable/application/path/artisan schedule:run >> /private/log/path/horus-scheduler.log 2>&1
```

On the CloudPanel production layout, the stable path remains
`/home/horusapp/htdocs/app.horusmedia.net`, so the cron does not change between
releases. The scheduler drains database-backed work in bounded runs; Publisher
ad traffic does not traverse this server.

## Server-specific notes

- Apache uses committed `public/.htaccess`; nginx must route non-file requests to
  `public/index.php` and deny dotfiles.
- Shared hosting should keep `.env`, private storage, and source outside the
  publicly addressable `public/` directory whenever possible.
- Containers and object storage are optional deployment choices, not application
  requirements.
- PHP-FPM realpath/opcache state must be reloaded after an atomic release symlink
  switch. Grant the site user only the narrow service-reload privilege required
  by the production runbook, never general sudo.

## Backups and rollback

Every normal production deployment creates a MySQL dump plus `.env` and
`storage/app` backup before maintenance and migration. Backups and old releases
are retained in bounded sets to avoid uncontrolled disk growth.

Application rollback switches the stable symlink back to a retained release and
reloads PHP-FPM. It never automatically reverses database migrations. Production
migrations therefore need to remain compatible with the immediately previous
application release whenever application-only rollback is expected.

## Static delivery and integrations

After the control plane deployment foundation is healthy, configure the private
static-delivery GitHub token and complete `CLOUDFLARE_SETUP.md`. Cloudflare Pages
credentials live in GitHub Secrets, not on the Laravel server. Keep GAM writes in
dry-run until production connection evidence is verified. THOTH remains optional
and its provider credentials must remain private.

## Final production package

The authoritative deployment package and operational runbooks are generated
under `release/`. Build with `scripts/build-release.sh` only after production
Composer dependencies and browser assets are compiled, then validate with
`scripts/validate-release.sh`.
