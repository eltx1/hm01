# Private Google application upload

## Problem and result

The owner created the platform Web OAuth client in Google Cloud and downloaded
its JSON file. The ordinary website connection flow intentionally contains no
technical credential form, so there was no supported UI for the owner to hand
the downloaded file directly to the platform. Cloud browser access remains
unavailable; uploading credentials into chat is not needed.

A separate, authenticated platform setup page at
`/admin/gam/reporting/google-app` now accepts the original file and saves it using
the existing encrypted private storage and audit service. It requires all three
platform/reporting/GAM management permissions, Horus membership and existing
2FA. The page directs the operator to choose a website after provisioning;
it does not claim account consent, network discovery or live reporting succeeded.

Uploads are limited to 32 KB and validated against the exact configured callback.
Private material, filenames and parser errors are not returned to the browser.
A shared cache lock serializes submissions; an active app cannot be replaced by
this initial-setup page, and explicit server configuration is not shadowed.

Testing identified that the shared security-header middleware overwrote explicit
`no-referrer` responses, including OAuth responses. It now preserves that stricter
value while retaining the configured default on all other responses. Ad-serving
code and financial report processing are unchanged.

## Changed files

- `app/Http/Controllers/Admin/GamReportingGoogleAppController.php`: setup GET/POST.
- `resources/views/admin/gam/reporting-google-app.blade.php`: private upload UI.
- `routes/web.php`: protected platform setup routes.
- `app/Http/Middleware/SecureResponseHeaders.php`: preserve explicit no-referrer.
- `tests/Feature/GamReportingGoogleAppUploadTest.php`: upload and access regressions.
- `docs/GAM_REPORTING_ACCOUNT_ONBOARDING.md`: operator upload instructions.
- `docs/GAM_PRIVATE_APP_UPLOAD_TASK_REPORT.md`: this report.

## Validation

`php artisan test --compact tests/Feature/GamReportingGoogleAppUploadTest.php tests/Feature/GamReportingGoogleAppTest.php tests/Feature/GamReportingOnboardingTest.php tests/Feature/ProductionSecurityTest.php`

Result: **27 passed, 262 assertions**, 3.09 seconds. Covers successful encrypted
upload and actor audit, idempotent retry, access denial for guests/publishers/staff
without settings permission, 2FA, malformed/oversized/wrong-callback files,
explicit configuration precedence, default and strict response headers, existing
OAuth flows and reporting/serving isolation. Pint passed for both new PHP files.

No real credentials were received or committed. Production account authorization
and the first live site report still require the owner's private upload and
Google authorization after deployment.
