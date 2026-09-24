# Automatic GAM reporting currency recovery

## Defect

The screenshot's exact error originates in both GAM CSV connectors, not in a
site/domain/ad-unit allowlist. For a non-canonical network currency, both
parsers required a currency prefix on every revenue cell. That rejected exact
zero, and also rejected unmarked nonzero micros even when Google's returned
ReportJob identified the requested canonical reporting currency. The connectors
discarded that returned currency evidence. Prior successful imports can coexist
with a failed later refresh because one newly encountered cell rejects the whole
snapshot atomically.

The screenshot establishes the failed validation branch, but does not expose
the rejected CSV cell. It does not by itself prove whether that cell was zero
or nonzero. Both cases now have explicit coverage.

## Correction and financial boundaries

- Both connectors use one strict money parser. Exact integer zero is safe
  without a currency marker. Nonzero unmarked micros require matching network
  currency or Google's confirmed currency for that exact report job.
- Confirmation comes from `runReportJob`'s returned
  `reportQuery.reportCurrency`, not Horus's requested-currency setting. It is
  stored alongside the job ID so pending reports retain it across scheduler
  runs. Existing jobs without confirmation still require the original marker
  for nonzero cross-currency money; failed downloads clear their checkpoint and
  the existing scheduler requests a new report automatically.
- Explicit conflicting currency markers, conflicting returned report currency,
  malformed values, and oversized integers remain errors. Unknown network
  currency is no longer sufficient evidence for unmarked nonzero money.
- Ad-unit jobs refresh network-currency metadata from the current network read.
- Existing validation of site/unit/date, atomic imports, deduplication, revenue
  shares, and closed accounting periods remains in the financial pipeline.
- No site-specific production IDs, migration, historical balance rewrite,
  exchange-rate guess, serving/runtime change, or publisher reinstallation.
- Existing sanitized production audit counters are exposed in its aggregate
  metrics so all reporting connections can be checked after deployment.

## Verification

Regression tests cover three new sites bound through the same service and GAM
account; a zero-revenue unit with real delivery; mixed zero/paid monthly rows;
scheduled refresh and error recovery without reconnecting; preservation of the
other sites; pending-job currency evidence; rejection of evidence reuse by a
different job; unchanged prior financial rows on failure; retry without double
counting; and conflicting Google responses before download. Full-network tests
cover marked, zero, confirmed positive and confirmed negative micros.

The parser's data-driven tests additionally cover USD markers, Unicode spacing,
non-USD expected currency, malformed/oversized numbers and conflicting markers,
including a zero explicitly marked with the wrong currency.

Local `git diff --check` passes. PHP is unavailable in the local workspace;
PHP 8.2/8.3/8.4, MySQL, and the existing browser/Node regression suites run in the
repository's GitHub checks. Exact results and deployment proof are recorded in
the PR once those runs finish. Production recovery must be checked separately
from fixture tests; the screenshot alone is not live import confirmation.

## Changed files

- `app/Services/Reporting/GamReportMoneyParser.php`
- `app/Services/Reporting/Connectors/GamAdUnitReportConnector.php`
- `app/Services/Reporting/Connectors/GamReportConnector.php`
- `tests/Unit/GamReportMoneyParserTest.php`
- `tests/Feature/SiteGamReportingTest.php`
- `tests/Feature/GamRestConnectorTest.php`
- `ops/audit/production-readiness.php`
- `docs/GAM_REPORT_CURRENCY_RECOVERY.md`

## Google references

- [ReportQuery reportCurrency](https://developers.google.com/ad-manager/api/reference/v202508/ReportService.ReportQuery#reportCurrency): specifies the currency of revenue metrics.
- [CSV_DUMP format](https://developers.google.com/ad-manager/api/reference/v202608/ReportService.ReportDownloadOptions): micros and currency-prefixed monetary representations.
