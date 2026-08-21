# Required Cron Jobs

Horus Media requires exactly one Laravel scheduler entry every minute. It does
not require a permanent queue worker, Redis, Supervisor, Docker, or WebSockets.

For the production CloudPanel installation the stable command is:

```cron
* * * * * cd /home/horusapp/htdocs/app.horusmedia.net && /usr/bin/php8.4 artisan schedule:run >> /home/horusapp/logs/horus-scheduler.log 2>&1
```

The Task 54 deployment foundation keeps
`/home/horusapp/htdocs/app.horusmedia.net` as the stable active-application
symlink, so this cron command does not change between releases.

For another provider, use the equivalent absolute PHP binary and stable atomic
application path. Keep scheduler logs outside public web roots.

The scheduler starts bounded database queue work with `--stop-when-empty`,
records the scheduler heartbeat, processes static delivery, reconciles campaigns,
imports reports, closes periods, and runs the registered retention jobs.

Verification:

```bash
php artisan schedule:list
php artisan queue:failed
tail -n 50 /home/horusapp/logs/horus-scheduler.log
```

Verify the Operations dashboard reports a fresh scheduler heartbeat within three
minutes. Do not create a second scheduler entry during a deployment or rollback.
