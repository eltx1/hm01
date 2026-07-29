# Prebid Operations

## Initial deployment

1. Run database migrations and seeders.
2. Build release assets with `npm ci && npm run build`.
3. Publish `public/assets/hm-loader.min.js` and `public/assets/prebid/` to `cdn.horusmedia.net`.
4. Configure the primary `HORUS_GAM` connection with `configuration.root_ad_unit_id`.
5. Open a website's Prebid administration page.
6. Add bidder accounts using public IDs only.
7. Assign accounts to the website and enter placement IDs.
8. Enable Prebid settings and publish the generated production configuration.
9. Review the GAM dry-run object estimate.
10. Confirm external writes and run centralized setup.

## Safe changes

Changing a bidder enabled state, public parameter, placement ID, timeout, currency, sequence, consent behavior, lazy loading, refresh, fallback, or selected GAM connection publishes a new static configuration. The publisher installation code remains unchanged.

## Recovery

A failed GAM setup run stores its cursor and completed remote mappings. Correct the connection, permission, template, or price-bucket issue and use Resume. Never delete successful mappings to retry a failed run. A full rerun is safe and skips all mapped objects.

## Build policy

Custom Prebid builds are created in CI or a release workstation, never by a production web request. Update `resources/prebid/horus-build.json`, run browser tests, build assets, verify the SHA-256 file, and deploy the complete release.
