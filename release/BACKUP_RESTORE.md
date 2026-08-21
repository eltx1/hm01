# Database Backup and Restore

## Deployment backups

After the one-time Task 54 atomic-layout bootstrap, every normal production
deployment creates a private backup directory before maintenance and migration:

```text
/home/horusapp/backups/<release-id>/
├── .env
├── database.sql.gz
├── storage-app.tar.gz
└── manifest.txt
```

The deployment helper reads database credentials from the shared `.env`, writes
a temporary mode-0600 MySQL client file, and removes that file after the dump.
Credentials are not placed in process arguments or deployment logs.

By default the newest 10 deployment backups are retained. Adjust
`HORUS_DEPLOY_KEEP_BACKUPS` only with an explicit storage/retention policy.

A deployment backup complements provider-level snapshots; it does not replace a
separate tested disaster-recovery backup policy.

## Independent backup / restore rehearsal

For an independent rehearsal, create a consistent MySQL/MariaDB dump using the
hosting provider's supported tooling and back up shared `.env` plus
`storage/app`. Store backups encrypted at rest with access limited to authorized
Horus Media operators.

Restore a verified dump to a **separate database**, never production first. Then
run migration status, application health, login, reporting totals, statement
hashes, and relevant integration checks against the restored copy. A backup is
not considered proven until an isolated restore rehearsal succeeds.

## Emergency production recovery

Use a maintenance window. Pause scheduled operations, restore the verified
matching database/private files, restore a compatible retained application
release, rebuild Laravel caches in that final release path, atomically select the
release, reload PHP-FPM, exit maintenance, and verify `/up`, login, scheduler
heartbeat, failed jobs, reporting, and finance state.

Record the incident, backup manifest, restored application release, and health
evidence. Do not use a production restore as a substitute for an isolated restore
drill.
