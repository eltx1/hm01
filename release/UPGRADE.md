# Upgrade Procedure

1. Download and verify the new ZIP using `CHECKSUMS.txt`.
2. Back up database, `.env`, private credentials, private uploads, and CDN configurations.
3. Inspect migrations with `php artisan migrate:status` and release notes.
4. Confirm the private production `.env` preserves the intended authentication policy:

```dotenv
AUTH_EMAIL_VERIFICATION_REQUIRED=false
AUTH_ADMIN_2FA_REQUIRED=false
SESSION_COOKIE=horus-media-session
```

Do not define `MYSQL_ATTR_SSL_CA` unless the database explicitly requires a CA certificate and the configured path exists.
5. Enable maintenance mode with a random secret.
6. Deploy to a new directory when the host permits atomic directory switching; otherwise replace application code while preserving `.env`, private storage, and credentials.
7. Preserve `.env`, `storage/app/private`, and the protected credential directory.
8. Run `composer install --no-dev --prefer-dist --optimize-autoloader` only when vendor was not supplied.
9. Run `php artisan migrate --force`.
10. Run `php artisan optimize`.
11. Publish versioned loader/Prebid assets through the configured static-delivery pipeline, retaining rollback artifacts.
12. Disable maintenance mode and execute health, login, Publisher application, GAM dry-run, loader, reporting, and cron checks.

For Task 53 and later, verify that staff login reaches the control plane directly and a test Publisher registration reaches the Publisher application directly when both authentication requirement switches are `false`.

Never use `migrate:fresh`, destructive schema commands, or an unreviewed SQL import in production.
