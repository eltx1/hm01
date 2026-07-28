# Prebid Test Plan

## Backend

- Seed the pinned build and bidder registry.
- Generate public site configuration for the primary `HORUS_GAM` network.
- Verify bidder account, site, and placement parameter merging.
- Verify disabling a bidder removes it from generated configuration.
- Verify switching a website to another GAM connection changes the setup key and
  network while the permanent loader snippet remains unchanged.
- Verify public JSON contains no credential-like values or backend event URLs.
- Verify setup preview performs no GAM write.
- Verify an invalid confirmation code blocks all external writes.
- Verify confirmed setup creates mapped GAM objects in dependency order.
- Verify repeated execution and a second unchanged preview create no duplicates.
- Run the same migration and test suite on SQLite and MySQL 8.

## Browser

- Load GPT once.
- Load the selected Prebid bundle once.
- Disable GPT initial loading before display registration.
- Run the auction before applying targeting and refreshing GAM.
- Pass the exact generated ad-unit codes to `setTargetingForGPTAsync`.
- Fall back to GAM after a thrown bidder error.
- Fall back to GAM after the auction safety timeout.
- Keep normal GAM delivery when Prebid is disabled.
- Keep the existing paused-site, disabled-placement, unauthorized-hostname,
  cache-busting, size-mapping, and GPT-singleton tests.

## Build and release

- Install root frontend dependencies with `npm ci`.
- Audit root dependencies at moderate severity.
- Clone the pinned official Prebid release.
- Install its locked dependencies.
- Build only approved modules.
- Produce readable and minified static assets.
- Verify manifest version, checksum, module list, and non-empty outputs.
- Package compiled assets in the production release without the temporary source
  tree or Node.js runtime.
