# Dashboard themes and reporting review — 24 September 2026

## Delivery

Dark remains the default. White Mode is an explicit, browser-local preference
applied before styles paint; denied storage falls back safely. The shared
palette covers navigation, forms, cards, tables, notifications and semantic
statuses. Official logo assets and ad-serving runtimes are unchanged.

Publisher reports now offer validated date ranges and quick periods, earnings,
impressions, clicks, earnings-based eCPM, daily detail and website breakdowns.
Estimated and finalized amounts remain identified. Missing days are not invented
as zeroes. Publisher projections expose only contractual earnings, not internal
margin/gross/net data. Finance figures are separate from selected report dates.
Paid-to-date is clearly all-time; scheduled payouts are identified as part of
pending payouts. Monthly statements link to private invoice review and payout.

Admin reports put performance first, identify gross breakdowns versus adjusted
headline totals, and keep technical source/import detail in a disclosure.
Permission-aware Finance links preserve the existing route authorization.

## Confirmed defects corrected

- Unvalidated report dates could throw during parsing; both report entry points
  validate dates/order and limit ranges to one year.
- Admin grouping used display names, merging distinct same-named entities.
  Publisher, website and campaign grouping now uses persistent identity.
- Prior carry-forward selection used statement creation time instead of financial
  month, allowing backfilled older statements to be selected. Select by period.
- A paid zero-balance statement with zero threshold could be described as ready
  for payout. Paid, empty and non-finalized states are excluded from readiness.
- Required/rejected invoices on older statements disappeared from the action
  list when a newer statement existed. All outstanding invoice actions link to
  their own statement. No ownership/permission boundary was widened.
- Month-labelled cards mixed all-time settlements and latest-statement amounts.
  Performance and Finance are now explicitly separate sections.

No production reports, statement balances, invoices or payments are rewritten
by this change. Period closing, approved adjustments, upload/review permissions,
private storage, payout approval and settlement remain authoritative.

## Validation

Local: 239 Node tests passed (0 failures); Vite production build succeeded;
JavaScript syntax and diff whitespace checks passed.
PHP is unavailable locally and local browser binaries are unavailable. CI is
required before merging. Added PHP tests cover date ranges, publisher isolation,
safe projections, eCPM, same-name grouping, old invoice visibility, zero-balance
readiness and chronological carry-forward. Added Chromium/WebKit desktop/mobile
tests cover theme round trips, storage denial, persisted choice, inputs,
navigation and page overflow. CI preserves light/dark screenshot previews.
These browser checks use the actual shared CSS/JS with representative shell
markup; PHP feature tests exercise server-rendered report routes separately.

## Changed files
- `.github/workflows/tests.yml`
- `app/Http/Controllers/Admin/ReportingController.php`
- `app/Http/Controllers/Publisher/ReportingController.php`
- `app/Http/Requests/ReportPeriodRequest.php`
- `app/Services/Reporting/PublisherFinanceService.php`
- `app/Services/Reporting/PublisherPerformanceService.php`
- `app/Services/Reporting/PublisherStatementService.php`
- `app/Services/Reporting/UnifiedReportService.php`
- `docs/BRAND_SYSTEM.md`
- `docs/DASHBOARD_THEME_REPORTING_REVIEW.md`
- `playwright.traffic-gate.config.js`
- `public/assets/dashboard-theme.js`
- `resources/css/dashboard-theme.css`
- `resources/css/reporting-experience.css`
- `resources/js/app.js`
- `resources/views/admin/reporting/index.blade.php`
- `resources/views/components/report-period.blade.php`
- `resources/views/layouts/admin.blade.php`
- `resources/views/publisher/finance/_performance.blade.php`
- `resources/views/publisher/finance/overview.blade.php`
- `resources/views/publisher/finance/statement.blade.php`
- `resources/views/publisher/finance/statements.blade.php`
- `tests/Browser/dashboard-theme.playwright.spec.js`
- `tests/Browser/dashboard-theme.test.js`
- `tests/Feature/PublisherFinanceExperienceTest.php`
- `tests/Feature/ReportingFinancialSystemTest.php`
