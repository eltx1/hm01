# Simplify platform Google reporting connection

Website admins should authorize Google and select an ad unit, without configuring
an OAuth application or uploading secrets. The website now starts consent directly;
an optional pre-entered unit is retained securely and bound after network choice.
Failed unit verification preserves the authorized account and input. Multiple
Google identities and networks remain supported, with an explicit single network
choice when completing a website binding.

Platform operators can provision the shared app using a private file through
`gam:reporting-google`, or use an explicitly configured private reference. Readiness
validates/decrypts configuration, handles corrupt files safely and is included in
the existing production audit. The operator command reports configuration only,
not successful live Google authorization. Existing upload endpoints remain
compatible for operations but are absent from the website workflow.

No advertising runtime, inventory deployment, financial calculation or report
import algorithm changed. Binding still uses SiteGamReportingService.

## Verification

Local PHP 8.3 targeted suite:

```
Tests: 59 passed (505 assertions)
Duration: 7.78s
```

This covers onboarding, central configuration, reporting/finance integration,
serving isolation, and production deployment contracts. It includes a complete
Google-return-to-unit-binding flow, ambiguous-network rejection, failed-unit
recovery without reauthorization, replay without duplicate connections/bindings,
credential redaction, corrupt configuration, and invalid operator imports.
Google HTTP/SOAP responses are faked; these tests are not a live account connection.

Blade view cache: passed. PHP audit-script syntax: passed. `git diff --check`: passed.
Hosted full CI must pass before the authorized normal merge/deploy.

## External activation status at implementation time

The latest production audit for edbb4a007e4f1dea991c4f75bdf333dffb4802b9 showed zero
GAM connections. The owner completed secure Google sign-in to the chosen company
account. Google Cloud remained inaccessible from the available cloud browser
(Site Unavailable / 502 Connection refused), including a fresh post-login check.
No Cloud project, OAuth client, Google terms acceptance or live network binding
is claimed as completed. OAuth activation remains a separate unresolved external
step; the admin UI truthfully reports pending activation if configuration is absent.

## Changed files

- `.env.production.example`
- `app/Console/Commands/ConfigureGamReportingGoogle.php`
- `app/Http/Controllers/Admin/GamReportingAccountController.php`
- `app/Http/Controllers/Admin/SiteController.php`
- `app/Services/Gam/GamReportingGoogleApp.php`
- `app/Services/Gam/GamReportingOnboarding.php`
- `config/gam.php`
- `docs/GAM_REPORTING_ACCOUNT_ONBOARDING.md`
- `docs/GAM_SIMPLE_CONNECT_TASK_REPORT.md`
- `ops/audit/production-readiness.php`
- `resources/views/admin/gam/reporting-connect.blade.php`
- `resources/views/admin/gam/reporting-google-button.blade.php`
- `resources/views/admin/sites/gam-reporting.blade.php`
- `tests/Feature/GamReportingGoogleAppTest.php`
- `tests/Feature/GamReportingOnboardingTest.php`
