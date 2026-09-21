# Google Ad Manager account onboarding task report

The previous website report form assumed a preconfigured GAM credential. This
change completes account creation from the website: Google consent, network
discovery, multi-network selection, return to the same website and unit selection.
The first account no longer requires entering server paths or refresh tokens.
Google application setup is performed once through a JSON upload in the UI; a
service-account JSON upload is available as an independent alternative.

Validation before hosted CI:

- New OAuth/onboarding HTTP feature cases: **11 passed, 133 assertions**, including
  real SDK credential construction and REST write isolation.
- Expanded integration regression set: **83 passed, 574 assertions; 1 local
  environment failure**. The existing campaign image-upload test requires GD,
  which is absent from the isolated local PHP runtime. Full hosted CI is required
  before merge and includes PHP 8.2/8.3/8.4, MySQL, release validation and browsers.
- Tests cover first setup, OAuth PKCE, Google consent, one/multiple networks,
  multiple Google identities, reconnect/idempotency, session ownership, expiry,
  cancelled/partial authorization, service-account upload, encrypted storage,
  verified return selection, unit binding, serving isolation and REST/SOAP write
  rejection. Existing finance and campaign guardrails are exercised too.
- No production Google credentials were created or selected on the user's behalf;
  actual Google account consent remains an administrator action in the new UI.

Changed files:

- `app/Http/Controllers/Admin/GamReportingAccountController.php`
- `app/Models/GamConnection.php`
- `app/Services/Campaigns/CampaignDeliveryCapabilityService.php`
- `app/Services/Gam/GamConnectionResolver.php`
- `app/Services/Gam/GamConnectionService.php`
- `app/Services/Gam/GamManagedCredentials.php`
- `app/Services/Gam/GamOfficialSoapTransport.php`
- `app/Services/Gam/GamOperationExecutor.php`
- `app/Services/Gam/GamReportingOnboarding.php`
- `app/Services/Gam/GamRestConnector.php`
- `app/Services/Gam/GamSecretResolver.php`
- `config/gam.php`
- `database/migrations/2026_09_21_080000_add_reporting_account_identity_to_gam_connections.php`
- `docs/GAM_AD_UNIT_REPORTING.md`
- `docs/GAM_REPORTING_ACCOUNT_ONBOARDING.md`
- `docs/GAM_REPORTING_ONBOARDING_TASK_REPORT.md`
- `resources/views/admin/gam/connections/index.blade.php`
- `resources/views/admin/gam/connections/show.blade.php`
- `resources/views/admin/gam/reporting-connect.blade.php`
- `resources/views/admin/sites/gam-reporting.blade.php`
- `routes/web.php`
- `tests/Feature/GamReportingOnboardingTest.php`
