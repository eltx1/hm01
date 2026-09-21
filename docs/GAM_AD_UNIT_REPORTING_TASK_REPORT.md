# GAM ad-unit reporting implementation report

The website now has an independent reporting-only Google Ad Manager connection.
The administrator submits an existing account and an ad-unit name/code/ID in one
form; searchable suggestions and single-account preselection reduce entry work.
The verified unit drives the existing report, revenue-rule, reconciliation,
financial-close, and publisher-statement pipeline.

Validation before opening the PR:

- Focused reporting/finance/GAM regressions: **50 passed, 390 assertions**.
- New feature coverage: **16 passed, 111 assertions** (included above).
- Existing loader/browser unit suite: **234 passed, 0 failed**.
- Production asset build: **passed**.
- `git diff --check`: **passed**.
- Local full PHP run: **805 passed, 31 failed**. This environment lacks GD and
  cannot resolve the external provider hosts used by several pre-existing
  Quick Monetize tests. The complete hosted CI suite is required before merge.
- Two new UI tests are included in all four Chromium/WebKit desktop/mobile
  projects. Local browser installation timed out; hosted CI runs this matrix.

The feature tests cover one-submit binding, permission and organization scope,
ambiguous names, duplicate network/unit ownership, actual generated SOAP payload
hydration, micros conversion, website revenue rules, publisher totals, month close
and statement creation, immutable closed periods, pending-job resumption, retry
recovery, corrected/empty snapshots, malformed reports, signed URL handling,
versioned cutovers, CSV/full-network deduplication, network timezone boundaries,
hourly estimate eligibility, and account coverage without bypassing other sites.

No production website is bound to an invented account or unit. Live binding still
requires the administrator's intended account and unit through the new form.
The operational behavior and effective-date rules are documented in
`docs/GAM_AD_UNIT_REPORTING.md`.

Changed files:

- `app/Console/Commands/RunReportingImports.php`
- `app/Console/Commands/SyncSiteGamReports.php`
- `app/Enums/ReportSourceCode.php`
- `app/Http/Controllers/Admin/ReportingController.php`
- `app/Http/Controllers/Admin/SiteController.php`
- `app/Http/Controllers/Admin/SiteGamReportingController.php`
- `app/Models/Site.php`
- `app/Models/SiteGamReportBinding.php`
- `app/Services/Gam/GamOfficialSoapTransport.php`
- `app/Services/Gam/GamOperationExecutor.php`
- `app/Services/Reporting/Connectors/GamAdUnitReportConnector.php`
- `app/Services/Reporting/GamAdUnitReportClient.php`
- `app/Services/Reporting/GamReportPending.php`
- `app/Services/Reporting/MonetizationFinancialReadinessService.php`
- `app/Services/Reporting/ReportImportService.php`
- `app/Services/Reporting/ReportSourceManager.php`
- `app/Services/Reporting/SiteGamFinancialCoverage.php`
- `app/Services/Reporting/SiteGamReportSynchronizer.php`
- `app/Services/Reporting/SiteGamReportingService.php`
- `app/Services/Reporting/SiteReportSourcePolicy.php`
- `config/reporting.php`
- `database/migrations/2026_09_21_030000_create_site_gam_report_bindings.php`
- `docs/GAM_AD_UNIT_REPORTING.md`
- `docs/GAM_AD_UNIT_REPORTING_TASK_REPORT.md`
- `playwright.traffic-gate.config.js`
- `resources/js/app.js`
- `resources/js/site-gam-reporting.js`
- `resources/views/admin/sites/gam-reporting.blade.php`
- `resources/views/publisher/sites/show.blade.php`
- `routes/console.php`
- `routes/web.php`
- `tests/Browser/site-gam-reporting.playwright.spec.js`
- `tests/Feature/SiteGamReportingTest.php`
