# GAM ad-unit report metric compatibility

The live site successfully connected network 23055873217 and unit 23375345468.
Its reporting card then showed a successful finalized import followed by
COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS for request metrics and total
revenue. The implementation sent the same complete metric set with both DATE
and DATE/HOUR. Google rejected the hourly combination; a prior daily success
and a later error can legitimately coexist.

Reporting-only ad-unit connections now request daily totals for intraday
estimates, refreshed hourly by the existing five-minute scheduler. All request,
matched/unfilled, impression, click and total-revenue metrics remain intact.
There is no substituted revenue definition and no fabricated hourly allocation.
Completed days still refresh every six hours in open financial periods.

Legacy hourly estimate calls normalize only for SITE_GAM_AD_UNIT. Automatic
retries convert old hourly work to daily estimates for the current day, or daily
finalized imports for completed days. Successful replacement imports retire
covered failed/pending jobs and remove their stale Google checkpoints. Repeated
snapshots replace the same date's totals; they are not added together. Financial
eligibility, closed-period protection, existing source isolation and currency
conversion logic remain authoritative.

The card now distinguishes the latest failed refresh from earlier successful
imports. Read-only production audit evidence separately counts reporting-only
connections with errors and recently completed daily estimated/finalized imports;
it emits no account identities, credentials, provider error payloads or revenue.

Changed files:
- app/Services/Reporting/Connectors/GamAdUnitReportConnector.php
- app/Services/Reporting/ReportImportService.php
- app/Services/Reporting/SiteGamReportSynchronizer.php
- app/Services/Reporting/SiteGamReportingService.php
- config/reporting.php
- resources/views/admin/sites/gam-reporting.blade.php
- ops/audit/production-readiness.php
- tests/Feature/SiteGamReportingTest.php
- docs/GAM_AD_UNIT_DAILY_REFRESH_TASK_REPORT.md

Validation:
- Reporting/onboarding/finance suite before the final rollover case: 61 passed,
  522 assertions.
- Final focused suite: 20 passed, 148 assertions.
- Pint, PHP syntax, Blade compilation and diff checks passed.
- The fake Google transport now rejects HOUR with the observed compatibility
  error. Tests cover hourly refresh without HOUR, pending jobs, complete metrics,
  no double counting, old retry recovery, day rollover, and financial finality.
- Production deployment and read-only live evidence are recorded in the PR.

No serving, loader, ad placements, OAuth credentials, or existing GAM/CSV source
behavior is changed. The reporting-only route intentionally uses daily snapshots
for intraday updates instead of hourly breakdowns.

References:
- https://developers.google.com/ad-manager/api/reporting
- https://developers.google.com/ad-manager/api/reference/v202608/ReportService.Column
