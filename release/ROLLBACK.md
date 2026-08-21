# Rollback Procedure

## Automatic application rollback

The Task 54 atomic deploy runner automatically restores the previous application
symlink when the newly switched release fails its HTTP health check. It then
reloads PHP-FPM, clears maintenance mode on the restored application, and checks
health again.

Automatic application rollback does **not** reverse database migrations.
Production migrations must therefore remain compatible with the immediately
previous application release whenever application-only rollback is expected.

## Manual application rollback

Use a retained release only after confirming it is compatible with the current
database schema.

1. identify the current and intended retained release paths;
2. enter maintenance mode through the stable application path;
3. atomically repoint the stable application symlink to the retained release;
4. reload PHP-FPM to clear realpath/opcache state;
5. exit maintenance mode;
6. verify direct-origin and public `/up`, administrator login, scheduler
   heartbeat, and failed jobs.

Do not rebuild a retained release in a temporary path and then move it after
`artisan optimize`. Do not rotate `APP_KEY` during rollback.

## Loader rollback

Use **Admin → Operations → Loader rollback**. The action requires the
administrator's current password, verifies the stored SHA-256 checksum, activates
the selected release, and writes an audit event. Republish affected site
configurations afterward.

## Configuration rollback

Use website configuration version history. A rollback creates a new immutable
version pointing to the source version; it never rewrites historical financial
or configuration records.

## Database recovery

Prefer a reviewed forward-fix migration. Restore a verified pre-deployment
backup only when database recovery is unavoidable and under a maintenance
window. Do not run `migrate:rollback` merely because an application release was
rolled back, and never target the live database with an untested restore drill.
