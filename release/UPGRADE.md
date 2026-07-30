# Safe Production Upgrade

1. Verify the new ZIP against `CHECKSUMS.txt` before extraction.
2. Announce a maintenance window and stop administrative writes.
3. Create a database backup and copy the current application directory, `.env`, Loader assets, and CDN `configs` directory.
4. Record the current Git/release identifier and active Loader/config versions.
5. Extract the new release into a new sibling directory; never overwrite the live directory file by file.
6. Copy the existing `.env` into the new directory and compare it with the new `.env.example`. Add new variables without replacing secrets or `APP_KEY`.
7. Ensure `storage` and `bootstrap/cache` are writable and point persistent uploaded private data to the retained storage directory if using a shared symlink layout.
8. Run `php artisan down --retry=60 --secret=<random-maintenance-secret>` on the live release.
9. In the new release run `php artisan optimize:clear`, then `php artisan migrate --force`.
10. Run `php artisan about`, `php artisan migrate:status`, `php artisan route:list`, and `php artisan schedule:list`.
11. Switch the subdomain document root or atomic symlink to the new `public` directory.
12. Publish the new Loader/Prebid assets to the CDN. Keep the previous Loader files available for rollback.
13. Run `php artisan optimize` and `php artisan up`.
14. Test `/up`, administrator login/TOTP, Operations, GAM dry-run, one test configuration, and one house ad.
15. Confirm cron heartbeat, failed jobs/imports, and logs during the first operating period.

Production migrations are forward-only. Never run `migrate:rollback` against live financial data. Use the documented application rollback plus a verified database restore when schema rollback is necessary.
