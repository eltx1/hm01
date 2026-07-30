# Google Ad Manager Setup

`HORUS_GAM` remains the default and primary ad server. MCM partner GAM and Publisher GAM are optional connections and must not block Horus GAM activation.

1. Create or select the Horus Media GAM network.
2. Create a Google Cloud service account with only the required GAM API permissions.
3. Store its JSON outside all public directories with mode `600`.
4. Set `GAM_HORUS_NETWORK_CODE` and `GAM_HORUS_SERVICE_ACCOUNT_PATH`.
5. In the dashboard, create a connection of type `HORUS_GAM`, mark it primary and enabled, and leave dry-run enabled initially.
6. Test authentication and network-code matching.
7. Create one test ad unit and one house order/line item in GAM.
8. Publish a test website configuration and verify the ad-unit path.
9. Disable dry-run only after the exact proposed API writes have been reviewed.

API writes use idempotency records and sanitized payload logging. Credentials are referenced by protected file path or environment reference; raw service-account JSON must never be stored in public configuration or logs.
