# Upgrade Procedure

1. Download and verify the new ZIP using `CHECKSUMS.txt`.
2. Back up database, `.env`, private credentials, private uploads, and CDN configurations.
3. Inspect migrations with `php artisan migrate:status` and release notes.
4. Enable maintenance mode with a random secret.
5. Deploy to a new directory when the host permits atomic directory switching.
6. Preserve `.env`, `storage/app/private`, and the protected credential directory.
7. Run `composer install --no-dev --prefer-dist --optimize-autoloader` only when vendor was not supplied.
8. Run `php artisan migrate --force`.
9. Run `php artisan optimize`.
10. Copy versioned loader/Prebid assets to the CDN, retaining the previous loader files.
11. Disable maintenance mode and execute health, login, GAM dry-run, house campaign, loader, reporting, and cron checks.

Never use `migrate:fresh`, destructive schema commands, or an unreviewed SQL import in production.
