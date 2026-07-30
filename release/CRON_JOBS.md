# Required Cron Jobs

The minimum Hostinger cron entry runs every minute:

```cron
* * * * * cd /home/ACCOUNT/domains/app.horusmedia.net/application && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

If Hostinger exposes a PHP selector path, use the PHP 8.2+ binary shown in hPanel. Do not start a permanent worker. The scheduler starts a database queue worker with `--stop-when-empty`, records a heartbeat, imports reports, closes periods, and prunes audit logs.

Verification commands:

```bash
php artisan schedule:list
php artisan operations:heartbeat manual-check
php artisan queue:monitor database:default --max=100
```

The Operations dashboard marks the scheduler stale when no heartbeat has been recorded within `HEARTBEAT_STALE_AFTER_SECONDS`.
