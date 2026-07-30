# Database Backup and Restore

## Backup

Use Hostinger hPanel export or a hosting-provided `mysqldump` binary before every deployment and financial close:

```bash
mysqldump --single-transaction --quick --routines --triggers \
  -h DB_HOST -u DB_USER -p DB_NAME | gzip > horus-$(date +%Y%m%d-%H%M%S).sql.gz
```

Also back up `.env`, the protected credential directory, `storage/app/private`, and the CDN `configs` directory. Encrypt backups at rest and restrict access to authorized Horus Media administrators.

## Restore rehearsal

Restore to a separate database first:

```bash
gunzip -c horus-BACKUP.sql.gz | mysql -h DB_HOST -u RESTORE_USER -p RESTORE_DATABASE
```

Run `php artisan migrate:status`, application health checks, reporting totals, statement hashes, and login tests against the restored copy. A backup is not accepted until a restore rehearsal succeeds.

## Emergency production restore

Enable maintenance mode, stop scheduled imports temporarily, restore the verified database, restore matching application/private files, clear and rebuild Laravel caches, then re-enable cron and check the operations heartbeat. Record the incident and restore reference in the audit/incident log.
