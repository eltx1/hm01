# Horus Media Production Installation

## Fixed production topology

- Main website: `https://horusmedia.net`
- Laravel application: `https://app.horusmedia.net`
- Advertising CDN: `https://cdn.horusmedia.net`
- Runtime: PHP 8.2–8.4, MySQL 8, database cache/session/queue, one cron entry.
- Not required: Docker, Redis, Supervisor, WebSockets, root access, a permanent worker, or Node.js in production.

The release ZIP already contains optimized Composer dependencies and compiled frontend, Loader, and Prebid assets. `HORUS_GAM` remains the default ad server.

## Hostinger installation

1. In hPanel create `app.horusmedia.net` and enable SSL.
2. Create `cdn.horusmedia.net` and enable SSL. Keep its document root separate from the application.
3. Upload `release/horus-media-platform.zip` outside the public document root where possible, then extract it.
4. Set the document root of `app.horusmedia.net` to the extracted `horus-media-platform/public` directory. Never point the domain to the project root.
5. Create a MySQL database and a dedicated database user with privileges only on that database.
6. Copy `.env.example` to `.env`, enter the MySQL values, and keep `.env` outside direct web access.
7. From the application directory run `php artisan key:generate --force`. Preserve this key permanently; encrypted credentials depend on it.
8. Set `APP_ENV=production`, `APP_DEBUG=false`, and `APP_URL=https://app.horusmedia.net`.
9. Set `APP_TRUSTED_HOSTS=app.horusmedia.net`, `TRUSTED_PROXIES=*` when Cloudflare is the only public proxy, and keep secure/encrypted database sessions enabled.
10. Grant the PHP account write access to `storage`, `bootstrap/cache`, and the configured CDN `configs` directory. Typical shared-hosting permissions are directories `755` or `775` and files `644`; never use `777` unless the host specifically requires a temporary diagnostic step.
11. Run `php artisan migrate --force` followed by `php artisan db:seed --force` for a new installation. Do not import an old schema over these migrations.
12. Run `php artisan horus:super-admin` and use a unique 14+ character password. The first administrator must enroll TOTP two-factor authentication.
13. Configure SMTP in `.env`; test password reset and invitation delivery before inviting users.
14. Store the GAM service-account JSON outside every public directory, for example `/home/ACCOUNT/private/gam-horus-service-account.json`, permissions `600` when supported.
15. Set `GAM_HORUS_NETWORK_CODE` and `GAM_HORUS_SERVICE_ACCOUNT_PATH`. Leave `GAM_DRY_RUN_DEFAULT=true` during the pilot.
16. Set `HORUS_STATIC_CONFIG_ROOT` to the physical `cdn.horusmedia.net/public_html/configs` directory and `HORUS_CDN_URL=https://cdn.horusmedia.net`.
17. Upload the contents of the release package's `cdn/` directory to the `cdn.horusmedia.net` document root. It contains the compiled Loader, pinned Prebid distribution, checksum, and safe default `configs/control.json`.
18. Configure the cron command from `CRON_JOBS.md`; then verify the `scheduler` heartbeat in Admin → Operations.
19. Run `php artisan optimize`, then test `https://app.horusmedia.net/up`, administrator login, TOTP, the Operations page, and a password reset.
20. Connect Horus GAM in dry-run mode, test the connection, create one test site/ad unit/house campaign, publish its configuration, install the Loader, and follow `PILOT_PLAN.md` before external onboarding.

## CDN directory example

```text
/home/ACCOUNT/domains/cdn.horusmedia.net/public_html/
├── hm-loader.js
├── hm-loader.min.js
├── assets/prebid/horus-prebid.min.js
└── configs/
    ├── control.json
    └── <site-public-key>.json
```

`configs` is public by design and must contain only browser-safe configuration. Credentials, revenue data, reports, invoices, contracts, logs, and database exports must never be written there.

## Final acceptance checks

```bash
php artisan about
php artisan migrate:status
php artisan route:list
php artisan schedule:list
php artisan operations:heartbeat manual-install-test
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Confirm that `/up` returns HTTP 200, application pages have CSP/security headers, `control.json` is reachable from the CDN, and the Loader refuses an unauthorized hostname.
