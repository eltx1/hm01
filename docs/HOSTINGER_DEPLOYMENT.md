# Hostinger Deployment

This is a provider-specific profile of the portable process in
[`DEPLOYMENT.md`](DEPLOYMENT.md). Hostinger is supported but is not required;
the application remains deployable on any compatible PHP/MySQL environment.

## Assumptions

- Hostinger shared or cloud plan provides PHP 8.2 or newer, required PHP
  extensions, MySQL, cron, SSL, hPanel file management or SFTP, and SSH/Composer
  where the chosen plan permits it.
- `app.horusmedia.net` can use the application's `public/` directory as its
  document root. If hPanel cannot point there, use Hostinger's documented
  Laravel directory layout while keeping `.env`, `vendor`, storage, and
  application source outside the public directory.
- `cdn.horusmedia.net` is a separate static/CDN origin in later releases.

Confirm current plan limits, PHP modules, cron frequency, execution time, memory,
storage, and MySQL version before launch.

## Build off-server

On a trusted machine or CI with PHP 8.2+, Composer 2, Node, and npm:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
php artisan test
```

Upload application files, production `vendor/`, and `public/build/`. Node.js and
npm are not needed in production.

On pushes to `main`, GitHub Actions builds a provider-neutral release ZIP after the SQLite/PHP
matrix and MySQL integration suite pass. The artifact includes `vendor/` and
compiled `public/build/` and can be uploaded through hPanel or another provider's deployment interface.

## Configure

1. Create the MySQL database and least-privilege application user in hPanel.
2. Copy `.env.production.example` to `.env` on the server and fill secrets
   privately. Generate `APP_KEY` with `php artisan key:generate`.
3. Set PHP 8.2+ and point `app.horusmedia.net` at `public/`.
4. Ensure `storage/` and `bootstrap/cache/` are writable by the PHP process.
5. Run `php artisan migrate --force`.
6. Run `php artisan db:seed --class=IdentityAccessSeeder --force`.
7. Run `php artisan storage:link` so uploaded branding logos are publicly served.
8. On the first deployment only, run `php artisan horus:create-super-admin` and
   enter the initial administrator password at the hidden prompt. Never place
   that password in shell history or deployment files.
9. Run `php artisan config:cache`, `route:cache`, and `view:cache`.
10. Verify `https://app.horusmedia.net/up` returns a successful response, then
    sign in and enroll the administrator's authenticator.

Do not upload a development `.env`, database dump, credentials, logs, or
`node_modules/`.

## Cron

Configure one once-per-minute job, replacing the absolute paths with the values
shown by Hostinger:

```cron
* * * * * /usr/bin/php /home/ACCOUNT/apps/horus-media/artisan schedule:run >> /dev/null 2>&1
```

The scheduler drains database queue work in a bounded process using
`--stop-when-empty --max-time=50`; no permanent worker or Supervisor is needed.
If the plan only allows a slower cron interval, report and import scheduling
must be designed around that actual limit.

## Release and rollback

Take a MySQL backup, upload to a versioned release directory when the plan
allows it, migrate, warm caches, switch the document root or release link, then
smoke-test `/up` and the dashboard. Preserve the previous release and define a
forward database recovery migration; do not rely on destructive migration
rollback in production.
