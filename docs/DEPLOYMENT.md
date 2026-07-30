# Portable Production Deployment

## Supported hosting model

Horus Media is a conventional Laravel application and is not tied to a single
hosting company. A compatible target provides PHP 8.2 or newer, the required
Laravel extensions, MySQL 8 or a compatible MariaDB release, HTTPS, writable
`storage/` and `bootstrap/cache/`, and a cron facility. Apache, nginx, managed
PHP hosting, a VPS, cloud application hosting, and shared hosting are supported
when they meet those requirements.

Production does not require Node.js, Docker, Redis, Supervisor, WebSockets, a
permanent queue worker, or root access. Node and Composer builds may happen in
CI or another trusted build environment.

## Build

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan test
```

The GitHub Actions `release-artifact` job performs the production dependency and
frontend builds after both the PHP/SQLite matrix and MySQL integration suite
pass. Its `horus-media-release-<sha>` ZIP is provider-neutral.

## Configure and release

1. Extract a release outside the public web root when the provider allows it.
2. Point the site document root to the release's `public/` directory.
3. Copy `.env.production.example` to `.env` and set `APP_URL`, `APP_KEY`,
   database, mail, session-domain, and provider-specific values privately.
4. Make `storage/` and `bootstrap/cache/` writable by the PHP process.
5. Run `php artisan migrate --force` and
   `php artisan db:seed --class=IdentityAccessSeeder --force`.
6. Run `php artisan storage:link` for public white-label logos. Contract files
   remain on the private local disk and must never be moved under `public/`.
7. On the first release, run `php artisan horus:create-super-admin`.
8. Run `php artisan config:cache`, `php artisan route:cache`, and
   `php artisan view:cache`.
9. Smoke-test `/up`, authentication, TOTP, one tenant-isolation check, and
   private contract download authorization.

Configure the equivalent of this cron command once per minute:

```cron
* * * * * /path/to/php /path/to/release/artisan schedule:run >> /dev/null 2>&1
```

The scheduler drains database-backed jobs in bounded, stop-when-empty runs.

## Server-specific notes

- Apache uses the committed `public/.htaccess`; allow overrides or mirror its
  front-controller rules in the virtual host.
- nginx must route non-file requests to `public/index.php` and deny dotfiles.
- Shared hosting should keep `.env`, `vendor`, storage, and source outside the
  publicly addressable directory whenever its control panel permits.
- Containers are optional packaging, never an application dependency.
- Object storage may replace Laravel disks later, but current contract files
  require a private, access-controlled disk.

Back up MySQL and private storage before every release. Prefer versioned release
directories and an atomic symlink/document-root switch. Preserve the previous
release for rollback and use forward database migrations rather than destructive
production rollbacks.

## Final production package

The authoritative deployment package and operational runbooks are generated under `release/`. Build with `scripts/build-release.sh` only after Composer production dependencies and browser assets have been compiled. Validate with `scripts/validate-release.sh`.
