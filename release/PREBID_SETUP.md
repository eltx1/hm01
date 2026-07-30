# Browser-side Prebid Setup

Prebid remains browser-side. Laravel creates public configuration; it does not run auctions or collect bidstream events.

1. Use the pinned build produced at `public/assets/prebid/horus-prebid.min.js`.
2. Publish the file and its `.sha256` companion under `cdn.horusmedia.net/assets/prebid/`.
3. Register one approved bidder account in the dashboard using public browser parameters only. Store any private API/report credential through the private connector reference system.
4. Configure bidder media types, sizes, floor policy, timeout, consent behavior, and GAM key/value mapping.
5. Create matching GAM Prebid line items/creatives through dry-run planning first.
6. Enable Prebid on one test site and one placement.
7. Verify that bidder targeting is applied before GAM refresh and that timeout/script failure falls back to GAM.
8. Confirm the global Prebid kill switch disables auctions without disabling direct GAM serving.
9. Review browser console diagnostics only in test mode; disable debug for production publishers.
10. Add bidders individually after latency, policy approval, discrepancy, and reporting checks.

No raw bid requests, user identifiers, or auction event streams are sent to the Laravel database.
