# Prebid Operations

Prebid has two supported delivery contexts. Operational steps that write GAM
objects apply only to `GAM_BRIDGE`; standalone Prebid must not require fake GAM
IDs or GAM setup.

## GAM_BRIDGE initial deployment

1. Run database migrations and seeders.
2. Build release assets with `npm ci && npm run build`.
3. Publish `public/assets/hm-loader.min.js` and `public/assets/prebid/` to `cdn.horusmedia.net`.
4. Configure the selected GAM connection; `HORUS_GAM` remains the default GAM mode.
5. Open the website's Prebid administration page.
6. Add bidder accounts using public IDs only.
7. Assign accounts to the website and enter placement IDs.
8. Enable Prebid settings and publish the generated production configuration.
9. Review the GAM dry-run object estimate.
10. Confirm external writes and run centralized GAM setup.

The current GAM automation, targeting, line items, idempotent mappings, dry-run,
and recovery behavior remain unchanged.

## STANDALONE deployment contract

A `HORUS_DIRECT` site may use standalone Prebid without a GAM connection.
Standalone operation still uses the permanent Horus Loader, a pinned browser-side
Prebid build, approved public bidder configuration, static CDN configuration,
privacy/supply-chain policy, and normal publication/rollback controls.

Standalone operation must not:

- require `gam_connection_id` or `network_code`;
- create GAM advertisers, line items, creatives, or targeting keys;
- load GPT merely to satisfy an old architecture assumption;
- convert Direct JS providers into fake Prebid bidders;
- send raw browser bids, impressions, or clicks to Laravel.

The direct winning-bid renderer is implemented by dedicated runtime work and is
not implicitly enabled by this architecture-contract task.

## Safe changes

Changing a bidder enabled state, public parameter, placement ID, timeout,
sequence, consent behavior, lazy loading, refresh, or delivery context publishes
a new static configuration. The publisher installation code remains unchanged.

For `GAM_BRIDGE`, currency/price-bucket/GAM-template changes and switching the
selected GAM connection continue to publish the appropriate bridge
configuration. They do not create a GAM dependency for unrelated standalone or
Direct JS placements.

## Recovery

A failed GAM setup run stores its cursor and completed remote mappings. Correct
the connection, permission, template, or price-bucket issue and use Resume.
Never delete successful mappings to retry a failed run. A full rerun is safe and
skips all mapped objects.

Standalone Prebid recovery uses static configuration promotion/rollback and
browser fail-safe behavior; there is no GAM setup run to resume.

## Build policy

Custom Prebid builds are created in CI or a release workstation, never by a
production web request. Update `resources/prebid/horus-build.json`, run browser
tests, build assets, verify the SHA-256 file, and deploy the complete release.

See `MULTI_ENGINE_SERVING.md` and `PREBID_ARCHITECTURE.md` for the authoritative
engine and renderer contracts.
