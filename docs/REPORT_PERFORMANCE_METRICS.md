# Report performance metrics

Admin Reports and Publisher Reports & earnings expose selectable daily columns
and a CSV export for impressions, clicks, CTR, CPM (eCPM), Active View, and unfilled
impressions. The same columns are available in website breakdowns; staff also
have publisher, source and campaign breakdowns. Themes and permissions remain
those of the existing dashboard.

## Definitions and revenue shares

| Metric | Calculation / source |
| --- | --- |
| Impressions / clicks | Existing source delivery counts; never multiplied by revenue share |
| CTR | Sum of clicks / sum of impressions; no averaging of daily CTR |
| Admin CPM (eCPM) | Gross reported USD revenue * 1,000 / impressions, before shares and statement adjustments |
| Publisher CPM (eCPM) | Sum of stored publisher earnings * 1,000 / impressions |
| Active View | Sum of viewable impressions / sum of measurable impressions |
| Unfilled impressions | Google's `TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS`, separate from unmatched requests |

Publisher earnings retain their existing date-effective revenue rule and
deductions. No current percentage is reapplied to historical earnings. Publisher
projections/CSV never contain gross revenue, Horus margin, or another publisher's
data. Admin reports retain finalized-only scope; publisher reports retain the
existing estimated/finalized scope, with separate earnings amounts in the CSV.

Percentages use two decimal places. CPM is denominated in USD, rounded to cents
after aggregation. Zero denominators and unavailable metrics display an em dash
for staff, and an explicit `Unavailable` label for publishers; CSV uses an empty
cell. A real reported count of zero remains zero. If any row
lacks the new counters, the corresponding aggregate is unavailable rather than
silently presenting a partial total or approximating Active View from impressions.

## Import and accounting compatibility

Both site-ad-unit and full-network GAM reports request viewable/measurable Active
View counters and unfilled impressions. API versions still come from the existing
GAM configuration. Queries keep DATE + AD_UNIT_ID, USD conversion and currency
proof. Pending-job identities include the metric set so old jobs are not mistaken
for the expanded report.

A specific Google incompatible-columns error gets one retry with the existing
financial columns. Missing optional counters remain NULL; finance/currency/core
metric validation is unchanged. Permission/quota/arbitrary failures are not
retried by that compatibility path. Malformed counters cannot partially update
financial records.

The additive migration adds nullable counters to hourly, daily and monthly rows.
It does not rewrite historical revenue or invoices. Metrics are excluded from
dimension identity so a refreshed report updates the original row instead of
duplicating revenue. Open-period synchronization picks up new metrics normally;
closed periods and issued statements are not automatically reopened/backfilled.
Monthly aggregation uses the same measurable-denominator calculation.

## Validation

### Publisher clarity follow-up

The publisher navigation opens the dedicated report route. Finance, statements,
payment details and payouts remain separate, permission-aware tabs. A finance-only
role retains its existing destination. The Finance page keeps its optional date
report collapsed; its accounting and payment operations are unchanged.

The report leads with one **Your earnings** amount, using the stored publisher
allocation. A collapsed breakdown explains finalized plus estimated earnings;
estimated zero-revenue rows still identify the total as including estimates.
Ad metrics have short explanations, and unavailable metrics distinguish incomplete
source data from zero denominators. No gross revenue or Horus margin is passed to
the report view or CSV. No additional revenue-share multiplication is performed.

Quick periods precede the total. Custom dates, column choices and CSV use the same
native GET form, including externally associated inputs. Daily rows are newest
first. Mobile rows disclose selected metrics below the date and publisher earnings;
desktop retains the table. A separate mobile SVG layout uses exactly the same
publisher earnings points, with readable labels and missing-day gaps.

Files changed by the follow-up: publisher ReportingController, ControlPlaneNavigation,
PublisherPerformanceService (estimate-presence metadata only), reporting-experience.css,
report-chart and report-performance-table components, three publisher-report components,
publisher reporting/index, finance/_performance, finance/_tabs, finance/overview,
ReportPerformanceMetricsTest, form-experience.playwright.spec.js, this document,
and compiled public/build assets. Run outcomes are recorded in the follow-up PR.

Regression coverage exercises changing daily shares, weighted ratios, missing
data, zero denominators, idempotent metric updates, invalid-counter rollback,
month close/statement preservation, tenant scoping, custom columns, CSV, Google
column compatibility, and both themes at desktop/mobile sizes. Browser fixtures
are real authenticated Blade responses. Run outcomes are recorded in the PR.

## Primary references

- https://developers.google.com/ad-manager/api/reference/v202605/ReportService.Column
- https://developers.google.com/ad-manager/api/reference/v202605/ReportService.ReportQuery

The enum reference defines `TOTAL_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS`,
`TOTAL_ACTIVE_VIEW_MEASURABLE_IMPRESSIONS`, and
`TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS`. It distinguishes the last metric
from `TOTAL_UNMATCHED_AD_REQUESTS`; the existing request metric is retained.
