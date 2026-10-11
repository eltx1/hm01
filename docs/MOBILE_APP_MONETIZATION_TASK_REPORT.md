# Mobile app monetization — task report

## Delivery status

Implementation is complete locally on `feat/mobile-app-monetization`, based on `35905ad86c7137a526477f1a214660e7d6759b4d` (main after PR #273). The prior temporary implementation files were unavailable when this session resumed. The implementation was reconstructed against the available repository and all results below were freshly obtained; previous unverified test results are not reused.

The user explicitly authorized publication to the public `eltx1/hm01` repository, CI, merge and production deployment after reviewing the implementation and local results. Publication and CI are now proceeding through the existing release process; deployment must follow successful gates. Live Google and MySQL/PHP 8.3/8.4 CI verification remain release checks until their results are recorded.

## Implemented behavior

- Separate mobile inventory, supported-store URL parsing, immutable app/store/publisher identities, publisher ownership isolation and admin review/activation.
- Current platform master records in app-ads.txt, HMP/HMA records, safe crawling and comparison, domain-change race protection and master-checksum invalidation.
- Permanent HMA identities in the existing sellers.json pipeline with standards-compliant seller_type PUBLISHER; no fake website or invalid APP seller type.
- SDK App ID and six supported native ad formats, copyable units and integration links. Serving network and reporting network are independently selected by the administrator.
- Exact network/unit/app Google report attribution, pending job resume, bounded retry/cooldown, daily zero corrections and source ownership across sites, Video and apps.
- Existing net publisher revenue allocation and statements, effective app overrides, separate app breakdowns, CSV export, closed-period protection and finalized coverage checks.
- Existing navy/gold UI, responsive reports and the current shared-hosting scheduler. No production Node/Redis/worker dependency was introduced.

## Validation output

Focused feature and regression suites:

```text
MobileAppMonetizationTest
AdminFinanceOperationsTest
RevenueRuleConflictSafetyTest
ReportEstimateVisibilityTest
ControlPlaneFoundationTest
OK (63 tests, 544 assertions)
Time: 00:06.970, Memory: 72.50 MB
```

The mobile suite contains 29 cases. Coverage includes all seven stores, unsafe URLs, seller publication, tenant isolation, anti-self-activation, master-file changes, a domain change during the fetch, exact-app gross/net attribution, missing dimensions, multiple units and cross-site ownership, pending jobs, zero correction, app revenue shares, six formats, scheduler cooldown, disabled-source coverage, estimates, closed periods, generic-network double-count protection, unchanged website hashes and immutable store identity.

Full suite (PHP 8.2.32 / SQLite):

```text
Tests: 1542, Assertions: 21523, Errors: 19, Failures: 12, Skipped: 19.
1492 passed; 31 unsuccessful; 19 skipped.
```

All 31 unsuccessful cases also fail on the unchanged base commit. A separate detached baseline worktree ran the nine affected suites:

```text
Tests: 72, Assertions: 1037, Errors: 19, Failures: 12.
Baseline failing test IDs: 31
Feature failing test IDs: 31
New failing test IDs: []
```

These pre-existing failures concern provider-host validation in the restricted runtime (including unresolved `cdn.taboola.com` and `securepubads.g.doubleclick.net`), plus follow-on missing fixture records. The validators and unrelated Direct Demand code were not weakened. The full suite is **not green** in this environment; CI must confirm it before merge.

Affected classes:

- `CustomThirdPartyGamRecipeCompatibilityTest`: 1
- `DirectDemandIsolationRegressionTest`: 5
- `DirectDemandQuickFinalHardeningTest`: 2
- `DirectDemandQuickMonetizeTest`: 12
- `DirectDemandQuickResourceOriginTest`: 1
- `QuickMonetizeDisabledSurfaceRecoveryTest`: 1
- `QuickMonetizeDisplaySuiteCommandTest`: 5
- `QuickMonetizeProviderAgnosticTest`: 2
- `QuickMonetizeSurfaceOwnershipTest`: 2

Other checks:

- PHP syntax: 43 changed non-Blade PHP files, zero errors.
- Vite production build: 67 modules, successful.
- Composer: all 119 installed package versions match composer.lock.
- Migration: fresh apply, one-step rollback and reapply all exited 0 on SQLite.
- Blade visual fixture rendering: 1 test, 5 assertions passed.
- Browser QA: app list, add form, publisher details, publisher reports and administrator details at 390px and 1440px; 10 captures, no page overflow, no JavaScript errors; App ID clipboard copy verified at both widths. Representative screenshots were visually inspected.
- `git diff --check`: clean.

## Release handoff

See `docs/MOBILE_APP_MONETIZATION.md` for publisher/admin workflows, Google references and operations. After authorized publication, run normal CI and merge only when gates pass. Deploy the migration and built assets through the existing release process. The existing cron scheduler runs `reporting:sync-mobile-apps` every five minutes. A live GAM report and SDK integration require a real approved app, reviewed developer website and authorized network credentials; no live Google inventory was modified by this work.

## Changed files (53)

- `app/Console/Commands/RunReportingImports.php`
- `app/Console/Commands/SyncMobileAppReports.php`
- `app/Enums/ReportSourceCode.php`
- `app/Enums/RevenueRuleScope.php`
- `app/Enums/SellerIdentityScope.php`
- `app/Http/Controllers/Admin/FinanceOperationsController.php`
- `app/Http/Controllers/MobileAppController.php`
- `app/Models/MobileApp.php`
- `app/Models/MobileAppAdUnit.php`
- `app/Models/MobileAppReportBinding.php`
- `app/Models/ReportDimension.php`
- `app/Models/SellerDeclaration.php`
- `app/Services/Compliance/AdsTxtFetcher.php`
- `app/Services/ControlPlane/ControlPlaneNavigation.php`
- `app/Services/MobileApps/AppAdsTxtService.php`
- `app/Services/MobileApps/AppStoreUrl.php`
- `app/Services/MobileApps/MobileAppFinancialCoverage.php`
- `app/Services/MobileApps/MobileAppReportConnector.php`
- `app/Services/MobileApps/MobileAppReportPolicy.php`
- `app/Services/MobileApps/MobileAppReportScope.php`
- `app/Services/MobileApps/MobileAppReportSynchronizer.php`
- `app/Services/MobileApps/MobileAppReporting.php`
- `app/Services/MobileApps/MobileAppService.php`
- `app/Services/Reporting/MonetizationFinancialReadinessService.php`
- `app/Services/Reporting/PublisherPerformanceService.php`
- `app/Services/Reporting/PublisherStatementService.php`
- `app/Services/Reporting/ReportDimensionResolver.php`
- `app/Services/Reporting/ReportImportService.php`
- `app/Services/Reporting/ReportSourceManager.php`
- `app/Services/Reporting/RevenueRuleService.php`
- `app/Services/Reporting/SiteGamUnitClaims.php`
- `app/Services/Reporting/SiteReportSourcePolicy.php`
- `app/Services/Reporting/UnifiedReportService.php`
- `app/Services/SupplyChain/HorusSellerIdentityService.php`
- `app/Services/SupplyChain/SupplyChainStandardsContract.php`
- `config/mobile-apps.php`
- `config/reporting.php`
- `database/migrations/2026_10_11_000001_create_mobile_app_monetization.php`
- `docs/MOBILE_APP_MONETIZATION.md`
- `docs/MOBILE_APP_MONETIZATION_TASK_REPORT.md`
- `resources/views/admin/finance/revenue-rules.blade.php`
- `resources/views/admin/reporting/index.blade.php`
- `resources/views/mobile-apps/form.blade.php`
- `resources/views/mobile-apps/index.blade.php`
- `resources/views/mobile-apps/reports.blade.php`
- `resources/views/mobile-apps/show.blade.php`
- `resources/views/mobile-apps/unit-form.blade.php`
- `resources/views/publisher/finance/_performance.blade.php`
- `routes/console.php`
- `routes/mobile-apps.php`
- `routes/web.php`
- `tests/Feature/ControlPlaneFoundationTest.php`
- `tests/Feature/MobileAppMonetizationTest.php`
