# Prebid Setup

Prebid.js runs only in the publisher browser. Laravel publishes aggregated configuration and never proxies bid requests or stores raw bids or impression events.

1. Approve one bidder and obtain its publisher/account identifiers.
2. Enable only the required adapter in `resources/prebid/horus-build.json`.
3. Run the production build before deployment.
4. Upload the generated files and SHA-256 file under `cdn.horusmedia.net/assets/prebid/`.
5. Configure bidder mappings for the test site and placement.
6. Run mocked browser tests and GAM fallback tests.
7. Publish the website configuration.
8. Verify that Prebid targeting is applied before the GAM refresh and that GAM still serves when the bidder times out or the Prebid build fails.

The Prebid kill switch disables bidder loading while preserving the normal GAM path.
