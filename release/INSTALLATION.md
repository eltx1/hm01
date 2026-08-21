# Horus Media Production Installation

Deployment targets:

- `horusmedia.net`: public company website.
- `app.horusmedia.net`: Laravel application; its document root must be the package `public/` directory.
- `cdn.horusmedia.net`: static Horus Loader, Prebid build, and published site configurations.

The package supports PHP 8.2+, the PHP SOAP extension, MySQL 8/MariaDB-compatible hosting, Composer, and cron. It does not require root access, Docker, Redis, Supervisor, WebSockets, Node.js in production, or a permanent worker.

## 1. Create the subdomains

Create `app.horusmedia.net` on the application host. Enable a valid TLS certificate before enabling HSTS and point the application subdomain document root to `public/`.

`cdn.horusmedia.net` is delivered separately through the configured static-delivery pipeline; it must not share the Laravel application document root.

## 2. Upload the application

Extract `horus-media-platform.zip` outside the public web root when the panel permits it. Only the Laravel `public/` directory may be web-accessible.

The ZIP already contains production Composer dependencies and compiled browser assets. Do not run Node.js on the production application server.

## 3. Create MySQL

Create a database and least-privilege user. Grant that user access only to the Horus Media database. Record host, port, database, username, and password in the private `.env` file.

## 4. Configure the environment

Copy the packaged `.env.example` file to `.env`. Set at minimum:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.horusmedia.net
DB_HOST=...
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
SESSION_COOKIE=horus-media-session
AUTH_EMAIL_VERIFICATION_REQUIRED=false
AUTH_ADMIN_2FA_REQUIRED=false
MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=operations@horusmedia.net
HORUS_CDN_URL=https://cdn.horusmedia.net
HORUS_STATIC_DELIVERY_DRIVER=cloudflare-pages-pipeline
HORUS_EDGE_GITHUB_BRANCH=edge-delivery
HORUS_EDGE_GITHUB_TOKEN_REFERENCE=env:HORUS_EDGE_GITHUB_TOKEN
GAM_HORUS_NETWORK_CODE=...
GAM_HORUS_SERVICE_ACCOUNT_PATH=/home/ACCOUNT/private/gam-horus-service-account.json
GAM_SOAP_VERSION_OVERRIDE=
```

Horus production currently uses email + password authentication without mandatory email verification or mandatory administrator 2FA. Both capabilities remain implemented and can be re-enabled explicitly with the two `AUTH_*` switches above.

Do not define `MYSQL_ATTR_SSL_CA` unless the database server explicitly requires a CA certificate and the value is an absolute path to the deployed CA file.

Never place GAM JSON inside a public document root. Confirm the `soap` PHP extension is enabled before enabling live Direct or automatic Prebid writes. The production ZIP already contains the Composer-locked official Google Ads PHP library.

## 5. Generate the Laravel key

From SSH or terminal:

```bash
php artisan key:generate --force
```

Never rotate `APP_KEY` after encrypted credentials or optional two-factor secrets are stored without first performing a planned re-encryption migration.

## 6. Permissions

Use the application account user:

```bash
chmod -R u=rwX,go=rX .
chmod -R u=rwX,go= storage bootstrap/cache
chmod 600 .env
chmod 700 /home/ACCOUNT/private
chmod 600 /home/ACCOUNT/private/gam-horus-service-account.json
```

The application writes only `storage/` and `bootstrap/cache/`. Cloudflare Pages files use the scheduled GitHub pipeline; there is no application-server CDN document root.

## 7. Safe initial migration

```bash
php artisan down --secret="GENERATE-A-RANDOM-SECRET"
php artisan config:clear
php artisan migrate --force
php artisan db:seed --force
php artisan optimize
php artisan up
```

Create the first super administrator with the protected interactive command:

```bash
php artisan horus:create-super-admin admin@horusmedia.net --name="Horus Media Administrator"
```

The command securely prompts for a password containing at least 14 characters, uppercase and lowercase letters, a number, and a symbol. With the production authentication switches above set to `false`, the administrator signs in with email and password and proceeds directly to the control plane.

## 8. Publish browser files to the CDN

Use the static-delivery pipeline documented in `CLOUDFLARE_SETUP.md`. The production application server is the control plane, not the publisher ad-request path.

## 9. Configure cron

Install the exact commands in `CRON_JOBS.md`. The scheduler heartbeat must become healthy within three minutes.

## 10. Production verification

1. Open `https://app.horusmedia.net/up` and confirm HTTP 200.
2. Confirm security headers with browser developer tools.
3. Sign in as the super administrator with email and password and confirm direct dashboard/control-plane access.
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
