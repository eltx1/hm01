# Mobile app monetization

## Publisher workflow

Open **Mobile apps → Add mobile app**. Provide the public supported-store URL, app name and developer website root domain. Google Play package and Apple numeric store IDs are extracted from their URLs. Other supported Android stores may require the Android package explicitly. Android packages cannot be registered again through another store or publisher.

Supported stores: Google Play, Apple App Store, Amazon Appstore, OPPO App Market, Samsung Galaxy Store, VIVO App Store and Xiaomi GetApps. A store listing is subject to administrator review; URL validation alone does not prove ownership or Google approval.

Download the generated `app-ads.txt`, host it at `https://<developer-root-domain>/app-ads.txt`, and ensure that developer website is listed on the store page. The file uses the same active, verified, currently effective platform master records as websites, plus the publisher HMP and app HMA records. Existing master administration remains authoritative. Verification uses the existing bounded, DNS-safe crawler and ads.txt parser/comparator, including certificate and relationship checks. Changing the domain resets review and file evidence; an in-flight fetch cannot verify a subsequently changed domain. Changes to required master records invalidate the previous verification checksum.

Each app has an immutable local ULID, public `hma_` ID, store/package identity, and a permanent HMA seller ID. App ownership and store identity cannot be reassigned. The public sellers.json uses the valid IAB seller_type `PUBLISHER`; `MOBILE_APP` is an internal identity scope. Publisher and app onboarding identities remain discoverable through the existing static supply-chain publication pipeline. App identities never become website seller fallbacks.

## Administrator workflow

1. Review app ownership, store/package identity, the developer root domain listed on the store, and Google app readiness.
2. Save the serving GAM connection and SDK App ID (`ca-app-pub-…~…`).
3. Add Google Mobile Ads SDK unit paths, such as `/123456/app_banner`, with an explicit format. Supported formats: Banner, App open, Interstitial, Rewarded, Rewarded interstitial and Native.
4. Activate after current app-ads.txt verification, store review, an SDK App ID and at least one enabled unit in the serving network.
5. Separately connect the reporting network and an exact ad unit ID, code or name. Horus verifies them with Google. Multiple distinct reporting units are supported.
6. Optionally set a versioned app revenue-share rule with an effective date, or use the existing publisher terms. Finance rule creation and preview support the app scope.

Publishers see the SDK App ID, copyable paths and official integration guides after activation. These are native SDK identifiers; app requests go directly to Google. Horus status changes cannot remotely stop an already installed SDK. Pause actual delivery in Google Ad Manager when required. This feature does not create apps or units in Google, install the SDK, or bypass Google's review.

## Reporting and financial boundaries

`GAM_MOBILE_APP` / `MOBILE_APP_GAM` sources use DATE, AD_UNIT_ID and MOBILE_APP_RESOLVED_ID, FLAT ad-unit view and an exact ad-unit query. The app ID dimension is the numeric Apple store ID or Android package, not the SDK App ID. CSV parsing requires the app dimension and exact unit/date range, filters other/unknown app IDs, rejects malformed/duplicate rows, verifies currency and emits zero daily snapshots for corrections. It uses the same Ad Exchange monetary columns as website reporting, requests canonical USD, and rounds Google micros once into existing ledger minor units.

Daily/hourly financial facts reuse the existing import, finality, period-lock, reconciliation, revenue-rule and publisher statement services. Publisher views and CSV exports use `publisher_earnings_minor`; administrator app reports use gross revenue. Publisher finance and shared reporting include app earnings and a separate app breakdown. Website dimension hashes are unchanged when no app is present.

Shared physical network/unit claims prevent duplicate financial ownership across primary website, website Video and mobile app reports. Generic Google network rows overlapping app-owned units (including ambiguous unitless totals) are excluded. App facts are API-only and validate publisher, organization, app, unit, network, store ID, scope and effective dates before admission. Existing generic network days, previous unit ownership and closed periods move new bindings forward rather than rewrite history.

The command `php artisan reporting:sync-mobile-apps` runs every five minutes via the existing scheduler. Each binding has its own lock; pending Google jobs resume, failures back off, today's daily estimate refreshes hourly, and open monthly finalized windows refresh every six hours. Disabled sources keep their history and unit claims. Missing finalized days, including zero-traffic days, block period closing until covered. No additional permanent worker, Redis, Node server or raw event ingestion is required.

## Release procedure

Use the normal repository CI/release workflow. Apply `php artisan migrate --force`, build assets in CI, and keep the existing Laravel scheduler running. Static sellers.json publication uses the existing scheduled publisher. A one-step migration rollback and reapply have been exercised on SQLite. Review MySQL and PHP 8.3/8.4 CI before merging. Perform a live Google smoke check with an authorized reporting connection and real app inventory after release; local tests use simulated Google responses.

## Primary standards references

- Google supported mobile stores and app readiness: https://support.google.com/admanager/answer/6316896?hl=en
- Google root-domain hosting requirements: https://support.google.com/admanager/answer/9422161?hl=en
- Google app-ads.txt setup: https://support.google.com/admanager/answer/7544382?hl=en
- Google reporting dimensions: https://developers.google.com/ad-manager/api/reference/v202602/ReportService.Dimension (runtime API version remains controlled by the existing resolver)
- Android SDK: https://developers.google.com/ad-manager/mobile-ads-sdk/android/quick-start
- iOS SDK: https://developers.google.com/ad-manager/mobile-ads-sdk/ios/quick-start
