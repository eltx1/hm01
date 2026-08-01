# Production Cron Jobs

Horus Media uses one once-per-minute scheduler entry. The application drains
database-backed work in bounded runs; it does not require a permanent worker,
Redis, Supervisor, Docker, or WebSockets.

## Required scheduler entry

Replace the PHP and release paths with the values on the host:

~~~cron
* * * * * /usr/bin/php /home/ACCOUNT/apps/horus-media/artisan schedule:run >> /home/ACCOUNT/logs/horus-scheduler.log 2>&1
~~~

Use an absolute PHP path. Keep the cron output outside public web roots and
rotate the log through the hosting provider or log retention policy.

## Release verification

1. The entry runs once per minute.
2. storage/logs/ contains no scheduler exceptions.
3. The Operations dashboard reports a healthy scheduler heartbeat within three minutes.
4. The database jobs and failed_jobs tables are writable.
5. A scheduled dry-run or test job completes without duplicate execution.

Do not add a second scheduler entry for the same release. During an atomic
release switch, keep only one active scheduler for the production application.

## Campaign and reporting operations

The normal Laravel scheduler registers campaign lifecycle reconciliation,
aggregated report requests/imports, drift detection, audit retention, bounded
queue draining, and static Pages delivery/reconciliation. Keep these operations enabled in production and inspect failed
jobs before retrying them.

For a manual operational check:

~~~bash
php artisan schedule:list
php artisan campaigns:monitor --reconcile
php artisan queue:failed
php artisan static-delivery:process --reconcile-only
~~~

Run manual commands in maintenance windows when they can produce external writes.
GAM and provider writes remain dry-run until an administrator explicitly confirms
them.

## Failure response

If the scheduler is unhealthy:

- check the cron path and PHP version;
- check .env, database connectivity, and writable storage/ and bootstrap/cache/;
- inspect failed_jobs and application JSON logs;
- resolve the root cause before retrying external writes;
- record the incident and the affected reporting/campaign period.

Never silently delete failed jobs or reset operation ledgers to recover.
