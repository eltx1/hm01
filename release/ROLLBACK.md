# Production Rollback

## Application-only rollback

Use this when the new release has not changed data incompatibly:

1. Enable Laravel maintenance mode or the platform maintenance control.
2. Switch the application document root/symlink back to the previous release.
3. Restore the previous Loader files or activate a prior Loader Release from Admin → Operations.
4. Use Configuration Rollback to create a new immutable configuration version based on the prior known-good version.
5. Run `php artisan optimize:clear && php artisan optimize` in the restored release.
6. Disable maintenance mode and run the smoke tests.

## Database restore

Use only after confirming the backup timestamp and accepting loss of data written after that backup.

Command-line example without root access:

```bash
mysql -h DB_HOST -u DB_USERNAME -p DB_DATABASE < horus-before-upgrade.sql
```

Backup example:

```bash
mysqldump --single-transaction --routines --triggers --default-character-set=utf8mb4 \
  -h DB_HOST -u DB_USERNAME -p DB_DATABASE > horus-before-upgrade.sql
sha256sum horus-before-upgrade.sql > horus-before-upgrade.sql.sha256
```

When Hostinger does not expose these binaries, use hPanel/phpMyAdmin Export and Import with SQL format and verify the resulting row counts. Store database exports outside all domain document roots and delete temporary server copies after transfer to encrypted storage.

After restore: run `php artisan optimize:clear`, check `migrate:status`, verify financial-period and statement counts, test authentication, and confirm the CDN control/config files match the restored database state.
