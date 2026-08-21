# Upgrade Procedure

For a production installation that has completed the one-time Task 54 atomic
layout bootstrap, use the validated atomic deployment runner. Do not manually
replace the active application directory.

## Preconditions

- the active application path is the stable atomic symlink;
- shared `.env` and `storage/` are healthy and private;
- exactly one Laravel scheduler entry uses the stable application path;
- the site user has only the narrow PHP-FPM reload sudo privilege documented in
  `PRODUCTION_DEPLOYMENT_FOUNDATION.md`;
- the new ZIP comes from a successful **Production release validation** run on
  `main` and its SHA-256 is known.

Preserve the intended production authentication policy unless intentionally
changed:

```dotenv
AUTH_EMAIL_VERIFICATION_REQUIRED=false
AUTH_ADMIN_2FA_REQUIRED=false
SESSION_COOKIE=horus-media-session
DB_CACHE_TABLE=cache
DB_CACHE_LOCK_TABLE=cache_locks
```

Do not define `MYSQL_ATTR_SSL_CA` unless the database explicitly requires a CA
certificate and the configured path exists.

## Supported upgrade command

```bash
bash horus-atomic-deploy.sh /path/to/horus-media-platform.zip EXPECTED_SHA256
```

TLS verification remains enabled by default. If an explicit internal
direct-origin resolve target uses a self-signed HTTPS certificate, add
`HORUS_DEPLOY_HEALTH_INSECURE_TLS=1` to the runner environment. The value must be
exactly `0` or `1`, is invalid without the direct-origin resolve target, and does
not apply to the separate public production health check.

The runner performs the checksum check, immutable release preparation, shared
links, Laravel preflight, MySQL/`.env`/`storage/app` backup, maintenance mode,
forward migrations, `storage:link`, final-path `optimize`, atomic application
switch, PHP-FPM reload, maintenance exit, direct-origin `/up` retries, old
release retention, and bounded backup retention.

If the new application fails its post-switch HTTP health check, the runner
restores the previous application symlink automatically and reloads PHP-FPM.

## Critical path rule

Never run `php artisan optimize` in a temporary extraction/staging directory and
then move that optimized application to another path. Laravel bootstrap cache
may contain absolute paths. The Task 54 deployment runner moves code into its
final immutable release path **before** generating Laravel caches.

## Prohibited upgrade actions

Normal upgrades never run:

```text
php artisan key:generate
php artisan db:seed
php artisan migrate:fresh
php artisan migrate:rollback
```

Do not rotate `APP_KEY` as part of deployment. Do not automatically reverse
production database migrations during an application rollback.

After a successful deployment verify the public `/up` endpoint, login, scheduler
heartbeat, `queue:failed`, and any integration changed by that release. GAM
writes remain dry-run until explicitly enabled by production evidence.
