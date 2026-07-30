# Horus Media Production Installation

Deployment targets:

- `horusmedia.net`: public company website.
- `app.horusmedia.net`: Laravel application; its document root must be the package `public/` directory.
- `cdn.horusmedia.net`: static Horus Loader, Prebid build, and published site configurations.

The package supports PHP 8.2+, MySQL 8/MariaDB-compatible hosting, Composer, and cron. It does not require root access, Docker, Redis, Supervisor, WebSockets, Node.js in production, or a permanent worker.

## 1. Create the subdomains

Create `app.horusmedia.net` and `cdn.horusmedia.net` in Hostinger. Enable valid TLS certificates before enabling HSTS. Point the application subdomain document root to `public/`. Point the CDN subdomain to a separate public directory such as:

```text
/home/ACCOUNT/domains/cdn.horusmedia.net/public_html
```

## 2. Upload the application

Extract `horus-media-platform.zip` outside the public web root when the panel permits it. Only the Laravel `public/` directory may be web-accessible. If Hostinger forces the project under `public_html`, keep framework directories above the subdomain document root and adjust `public/index.php` paths only when necessary.

The ZIP already contains production Composer dependencies and compiled browser assets. Do not run Node.js on the server.

## 3. Create MySQL

Create a database and least-privilege user in hPanel. Grant that user access only to the Horus Media database. Record host, port, database, username, and password in the private `.env` file.

## 4. Configure the environment

Copy `.env.production.example` to `.env`. Set at minimum:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.horusmedia.net
DB_HOST=...
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=operations@horusmedia.net
HORUS_CDN_URL=https://cdn.horusmedia.net
HORUS_STATIC_CONFIG_ROOT=/home/ACCOUNT/domains/cdn.horusmedia.net/public_html/configs
GAM_HORUS_NETWORK_CODE=...
GAM_HORUS_SERVICE_ACCOUNT_PATH=/home/ACCOUNT/private/gam-horus-service-account.json
```

Never place GAM JSON inside either public document root.

## 5. Generate the Laravel key

From Hostinger SSH or terminal:

```bash
php artisan key:generate --force
```

Never rotate `APP_KEY` after encrypted credentials or two-factor secrets are stored without first performing a planned re-encryption migration.

## 6. Permissions without root

Use the hosting account user only:

```bash
chmod -R u=rwX,go=rX .
chmod -R u=rwX,go= storage bootstrap/cache
chmod 600 .env
chmod 700 /home/ACCOUNT/private
chmod 600 /home/ACCOUNT/private/gam-horus-service-account.json
```

The application must be able to write to `storage/`, `bootstrap/cache/`, and the configured CDN `configs/` directory.

## 7. Safe initial migration

```bash
php artisan down --secret="GENERATE-A-RANDOM-SECRET"
php artisan config:clear
php artisan migrate --force
php artisan db:seed --class=IdentityAccessSeeder --force
php artisan db:seed --class=ReportingSeeder --force
php artisan optimize
php artisan up
```

Create the first super administrator using the project command and a strong unique password. Administrator two-factor authentication is mandatory before dashboard access.

## 8. Publish browser files to the CDN

Copy these built files from the package to the CDN root:

```text
public/assets/hm-loader.js        -> cdn public_html/hm-loader.js
public/assets/hm-loader.min.js    -> cdn public_html/hm-loader.min.js
public/assets/prebid/*            -> cdn public_html/assets/prebid/
```

Create the writable directory:

```text
cdn public_html/configs/
```

Use Cloudflare cache rules from `CLOUDFLARE_SETUP.md`.

## 9. Configure cron

Install the exact commands in `CRON_JOBS.md`. The scheduler heartbeat must become healthy within three minutes.

## 10. Production verification

1. Open `https://app.horusmedia.net/up` and confirm HTTP 200.
2. Confirm security headers with browser developer tools.
3. Sign in as the super administrator and complete 2FA.
4. Open **Operations** and confirm the scheduler heartbeat is healthy.
5. Add the Horus GAM connection, keeping `HORUS_GAM` primary and enabled.
6. Run the GAM connection test in dry-run mode.
7. Create a Horus test website and one placement.
8. Publish its production configuration and confirm the JSON exists on `cdn.horusmedia.net`.
9. Install the permanent loader tag on the test website.
10. Run a house campaign and verify GAM impression reporting before onboarding an external publisher.

## Log rotation and error notifications

`LOG_CHANNEL=daily` and `LOG_DAILY_DAYS=30` rotate JSON logs without root access. To receive critical alerts through Slack, set `LOG_STACK=daily,slack`, `LOG_SLACK_WEBHOOK_URL`, and a critical channel level after sending a test alert. Keep webhook values only in `.env`. Review disk usage and archived logs during the pilot.

## Maintenance mode

Use `php artisan down --secret="RANDOM_SECRET"` for planned work. The secret URL is for authorized deployment verification only. Use `php artisan up` after migrations, caches, cron, mail, and health checks pass.
