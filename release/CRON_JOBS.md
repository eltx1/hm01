# Hostinger Cron Jobs

Only one permanent cron entry is required. It starts short-lived Laravel scheduler processes; the application never depends on Supervisor or a permanent worker.

Run every minute:

```cron
* * * * * cd /home/ACCOUNT/domains/app.horusmedia.net/application && /usr/bin/php artisan schedule:run >> /home/ACCOUNT/horus-cron.log 2>&1
```

Use the exact PHP binary shown by Hostinger's PHP information or cron UI. If the application resides directly under the domain directory, adjust the `cd` path only.

The scheduler performs:

- database cron heartbeat every minute;
- short-lived database queue work with `--stop-when-empty`, job/time/memory limits, and bounded retries;
- failed-job pruning;
- audit retention pruning;
- hourly and daily report imports with retry;
- direct-campaign monitoring and reconciliation;
- monthly financial close.

## Verification

```bash
php artisan schedule:list
php artisan operations:heartbeat manual
php artisan queue:monitor database:100
```

Then open Admin → Operations. The `scheduler` heartbeat must update every minute. A heartbeat older than five minutes, repeated failed jobs, or repeated failed imports is an operational incident.

Rotate or delete `horus-cron.log` periodically, or redirect to `/dev/null` after initial verification. Application logs use Laravel daily rotation.
