# True Unfilled impressions: separate exact-site diagnostic

The requested metric is Google's `TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS`
(SOAP), or `UNFILLED_IMPRESSIONS` (REST Interactive reports). It is additive to the
existing selected-unit, exact-host Ad Exchange report. Neither request-minus-
response counts nor request-minus-impression counts can supply this field.

This release restores the true field to default reporting cards, columns and CSV
without removing existing metrics. Unknown source values remain unavailable.
There is no importer, revenue calculation, stored financial fact, settlement,
historical correction activation or serving change.

## Bounded live evidence

Only the immediate reviewed successor of `558cc8e069fc188f429f4746ff6bbdcddfe98c4e`
on current `main` runs the diagnostic through the existing trusted deployment
workflow and pinned SSH connection. It executes against the current production
application and its existing Google credentials. An unsupported or inconclusive
diagnostic does not block the ordinary AdX finance preflight, import or deployment.
Subsequent releases skip the diagnostic automatically.

For each active binding (maximum 25), SOAP requests only the real Unfilled metric
with `DATE`, `AD_UNIT_ID`, `SITE_NAME`, the selected-unit predicate and publisher
timezone over the last seven complete days. It never drops Site, substitutes a
metric, mixes in revenue, or uses a network/unit total as a website value.

An accepted query and a valid CSV header prove compatibility only. Exact-site
attribution requires at least one valid returned row for the registered hostname.
Header-only results, `(Not applicable)` rows, missing dates and other hosts never
become zero. A real exact-host row with an explicit zero is distinguishable from
no row. Duplicate keys, wrong dates/units, malformed counters and incomplete rows
invalidate the evidence. API rejection, pending timeout and valid-empty results
are reported separately.

REST uses existing access, checks reusable report definitions first and may create
only a minimal unscheduled HIDDEN definition for this exact diagnostic. HIDDEN
means absent from the GAM UI, not a new sharing permission. It never changes
credentials, access, schedules or another saved report. REST results must prove
identical unit, exact host, publisher timezone, dates and genuine source metric.
The isolated diagnostic does not automatically wire a new data source into finance.

Raw query/job/row evidence remains in private application storage on the existing
production host. Public Actions output is reconstructed from a closed enum-and-
boolean schema; it contains no domains, unit/network IDs, impression counts, amounts,
credentials, signed download URLs or raw upstream errors. No raw artifacts are
uploaded.

A separate ingestion change is appropriate only after the exact live report is
compatible and returns attributable data. It must retain immutable source-specific
provenance, nullable incomplete periods and financial independence.

## Official references

- [SOAP metric definitions](https://developers.google.com/ad-manager/api/reference/v202608/ReportService.Column)
- [Interactive report definitions](https://developers.google.com/ad-manager/api/beta/reference/rest/v1/networks.reports)
- [Create and run Interactive reports](https://developers.google.com/ad-manager/api/beta/reports)

## PR242 observed outcome and saved-evidence classification

Release `7be80e9dff9d53cb82fe9dea9c007ac749adf1f4` deployed successfully.
Its isolated diagnostic completed all three SOAP queries with valid nonempty
reports, but none contained an exact registered-host row. REST stopped at access
rejection before selecting a report. Neither finding supplies a website count.
The REST result does not establish metric incompatibility.

The immediate successor release performs one read-only classification of the
already saved evidence. The deployed release marker must still be PR242, the
private evidence directory must be unique in the original execution window
(2026-10-03 20:53:20–20:53:45 UTC), and all six regular JSONL files must fall in
that same window. Missing, ambiguous, stale, symlinked, excessive or malformed
evidence fails closed. It never bootstraps Laravel, requests Google, runs another
report, changes credentials, or touches reporting facts. It prints only closed
SOAP label categories and Google structured ErrorInfo enums through a second
runner-side validator. Unknown error causes remain unclassified; raw messages,
identifiers, counters, metadata and evidence paths stay private on the server.

The existing SOAP/REST probe remains restricted to PR242 and will not be rerun
by this successor. The classification itself does not enable ingestion or
establish support for exact-host reporting.
