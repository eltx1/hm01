# Site Ad Manager: today so far

## User outcome

The site's Reports section now shows today's latest imported estimated ad requests,
impressions, clicks, gross revenue and publisher earnings above the connection form.
The network's reporting date, currency, timezone and actual snapshot import timestamp
are explicit. A completed-day reports link carries the correct currency.

## Boundaries

- Read-only display of existing daily estimates for the current site binding.
- Date boundaries use the Ad Manager connection timezone, including midnight rollover.
- Queries constrain the organization, source connection, site dimension, currency,
  report date, estimated finality and completed import status.
- A missing current-day snapshot is shown as waiting, not as zero or yesterday's data.
- A successfully imported zero snapshot is displayed as zero.
- Site and reporting permissions remain required. No Google calls or import writes
  happen when the page is viewed.
- Estimates remain ineligible for payouts. Existing finalized reports and finance,
  import cadence, OAuth and advertising delivery are unchanged.

## Changed files

- `app/Services/Reporting/SiteGamTodayReport.php`: scoped read model for today's snapshot.
- `app/Http/Controllers/Admin/SiteController.php`: permission-gated report data.
- `resources/views/admin/sites/gam-reporting.blade.php`: current-day performance cards,
  timestamp, pending/paused messaging and completed-day navigation.
- `tests/Feature/SiteGamReportingTest.php`: current-day display, financial separation,
  read-only rendering, network midnight, genuine zeros, site isolation and permissions.
- `docs/SITE_GAM_TODAY_REPORT_TASK_REPORT.md`: task evidence.

## Validation

`php artisan test --filter='SiteGamReportingTest|ReportingFinanceTest|Financial'`
passed **38 tests / 280 assertions**. Pint, Blade compilation and git diff checks passed.
Hosted CI, merge and production deployment evidence are recorded in the pull request.
