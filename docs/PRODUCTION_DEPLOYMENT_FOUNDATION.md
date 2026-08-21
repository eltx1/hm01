# Horus Media Production Deployment Foundation

This runbook defines the supported production release layout for `app.horusmedia.net`.
It keeps the existing CloudPanel document root stable while making application
releases versioned, atomic, reproducible, and reversible.

## Production layout

```text
/home/horusapp/
├── backups/
├── incoming/
├── releases/
│   ├── bootstrap-20260821T190000Z/
│   ├── <commit-sha>/
│   └── ...
├── shared/
│   ├── .env
│   └── storage/
├── logs/
│   ├── horus-deploy.log
│   └── horus-scheduler.log
└── htdocs/
    └── app.horusmedia.net -> /home/horusapp/releases/<active-release>
```

CloudPanel continues to use the relative document root:

```text
app.horusmedia.net/public
```

The existing scheduler command also remains stable because it enters the same
`/home/horusapp/htdocs/app.horusmedia.net` symlink.

## Invariants

- `.env` exists once at `/home/horusapp/shared/.env` and is symlinked into every release.
- `storage/` exists once at `/home/horusapp/shared/storage` and is symlinked into every release.
- a release directory is immutable application code after activation;
- the release ZIP contains no `.env`, deployment tooling, tests, Git metadata, or Laravel bootstrap cache generated on CI;
- Laravel `optimize` runs only after code has reached its final immutable release path;
- the active application changes through one atomic symlink replacement;
- exactly one deployment may run at a time (`flock`);
- every normal deployment creates database, `.env`, and `storage/app` backups before maintenance/migration;
- failed post-switch health checks restore the previous application symlink automatically;
- database migrations are forward-only. Application rollback never performs an automatic destructive database rollback.

## One-time conversion of the existing CloudPanel installation

Before conversion, verify a current MySQL dump plus `.env` and `storage/app`
backup. The bootstrap refuses to run unless this has been explicitly confirmed.

The site user needs only one narrow root capability so an atomic switch can clear
PHP-FPM realpath/opcache state:

```text
horusapp ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm
```

Install that rule as root in `/etc/sudoers.d/horusapp-horus-deploy`, mode `0440`,
and validate it with `visudo -cf` before continuing. Do not grant general sudo.

Run the repository bootstrap script as `horusapp`:

```bash
HORUS_BOOTSTRAP_CONFIRMED_BACKUP=1 \
  bash ops/deploy/horus-bootstrap-atomic-layout.sh
```

It briefly enters Laravel maintenance mode, moves the existing application into
`releases/bootstrap-...`, moves `.env` and `storage/` into `shared/`, rebuilds
Laravel caches in the final release path, replaces the original application
path with a symlink, reloads PHP-FPM, exits maintenance, and verifies `/up`.
If conversion fails after maintenance begins, it restores the directory layout.

## Normal deployment

The canonical deployment entry point is:

```bash
bash ops/deploy/horus-atomic-deploy.sh \
  /path/to/horus-media-platform.zip \
  EXPECTED_SHA256
```

The runner performs:

1. checksum and package validation;
2. extraction into a temporary staging directory;
3. move into the final `releases/<release-id>` path **before** Laravel cache generation;
4. shared `.env` and `storage/` links;
5. `optimize:clear`, route boot, and `schedule:list` preflight;
6. database + `.env` + `storage/app` backup;
7. maintenance mode;
8. `migrate --force`;
9. `storage:link` and `optimize` in the final release path;
10. atomic `app.horusmedia.net` symlink switch;
11. PHP-FPM reload;
12. maintenance exit and direct-origin `/up` health retries;
13. automatic application rollback when the new release fails health checks;
14. old application release retention.

The runner deliberately does not run `db:seed`, `key:generate`, `migrate:fresh`,
or `migrate:rollback` during upgrades.

## GitHub production deployment

`.github/workflows/deploy-production.yml` listens for a successful **Production
release validation** run on `main`. Deployment is intentionally fail-safe OFF
until the repository variable below is explicitly enabled:

```text
HORUS_PRODUCTION_DEPLOY_ENABLED=true
```

Configure the GitHub `production` environment with these secrets:

```text
HORUS_PRODUCTION_HOST
HORUS_PRODUCTION_SSH_USER
HORUS_PRODUCTION_SSH_PRIVATE_KEY
HORUS_PRODUCTION_KNOWN_HOSTS
```

The production SSH user should be `horusapp` with a dedicated Ed25519 deployment
key, not the general AWS administrator key. Pin the server host key in
`HORUS_PRODUCTION_KNOWN_HOSTS`; do not disable host-key checking.

Optional repository variables:

```text
HORUS_PRODUCTION_SSH_PORT=22
HORUS_PRODUCTION_HOME=/home/horusapp
HORUS_PRODUCTION_HEALTH_URL=https://app.horusmedia.net/up
```

The workflow downloads the exact artifact produced by the successful validation
run, verifies `CHECKSUMS.txt`, transfers only the validated ZIP and deployment
runner, runs the atomic deployment remotely, then performs a public `/up` check.
Production deployments are serialized and are never cancelled mid-run.

## Cron

Keep exactly one CloudPanel cron entry:

```cron
* * * * * cd /home/horusapp/htdocs/app.horusmedia.net && /usr/bin/php8.4 artisan schedule:run >> /home/horusapp/logs/horus-scheduler.log 2>&1
```

Because `app.horusmedia.net` becomes the stable atomic symlink, this cron entry
does not change between releases.

## Rollback rules

A failed new-release HTTP health check automatically restores the previous
application symlink and reloads PHP-FPM. For a later manual application rollback,
point the stable symlink at a retained release, rebuild that release's cache only
if required, reload PHP-FPM, and verify `/up`.

Do not automatically reverse database migrations. Production migrations must be
backward-compatible with the immediately previous application release whenever
an application-only rollback is expected to remain possible.

## Operational evidence

For each deployment retain:

- GitHub validation run ID and commit SHA;
- release artifact SHA-256;
- `/home/horusapp/logs/horus-deploy.log` entries;
- backup directory manifest;
- active `.horus-release` metadata;
- successful direct-origin and public `/up` checks.
