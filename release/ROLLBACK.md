# Rollback Procedure

## Application rollback

1. Enable maintenance mode.
2. Restore the previous application directory or previous release ZIP.
3. Restore the previous `.env` only if environment settings changed.
4. Run `php artisan optimize`.
5. Disable maintenance mode and test `/up` and administrator login.

## Loader rollback

Use **Admin → Operations → Loader rollback**. The action requires the administrator's current password, verifies the stored SHA-256 checksum, activates the selected release, and writes an audit event. Republish affected site configurations afterward.

## Configuration rollback

Use the existing website configuration version history. A rollback creates a new immutable version pointing to the source version; it never rewrites historical financial or configuration records.

## Database rollback

Prefer forward-fix migrations. Restore a verified pre-deployment database backup when a schema rollback is unavoidable. Do not run `migrate:rollback` unless every migration in the batch has been reviewed for data loss.
