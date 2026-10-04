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

## Manual REST-only retest after API enablement

`Diagnose exact-site Unfilled REST` is an explicit `workflow_dispatch` action with
no inputs. It never runs on pushes, pull requests, schedules or deployment events.
After the owner confirms that the Ad Manager API is enabled in the existing
credential's Cloud project, run it on `main` from GitHub Actions. It requires the
current `main` SHA to match the active atomic production release and shares the
production deployment concurrency lock. If main has not finished ordinary
release deployment, wait for that deployment before manually running the test.

The workflow streams the existing bounded REST report helper plus a REST-only
application wrapper through the existing pinned SSH connection. It runs no SOAP
report and deploys no application files. The same selected unit, exact hostname,
source timezone and last seven complete dates remain mandatory. It may reuse or
create the original minimal unscheduled HIDDEN diagnostic definition with existing
access; it never enables an API, grants access, changes credentials or imports facts.
A run is bounded by the existing 180-second helper budget and a 240-second remote
process limit. Results are still diagnostic, not proof of support unless exact-host
rows actually appear. Structured access-denial enums are included immediately to
avoid another release merely to identify a repeated access failure.

Execution options: GitHub Actions → Diagnose exact-site Unfilled REST → Run
workflow → main, or an already authorized GitHub CLI session:
`gh workflow run diagnose-unfilled-rest.yml --repo eltx1/hm01 --ref main`.
No workflow dispatch is performed as part of introducing this operational tool.

## Restored original SOAP unit counter

The original pre-exact-site importer requested `DATE + AD_UNIT_ID` and
`TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS`. The exact-site AdX importer removed
that column and stored null. Restore the original scope using
`SiteGamUnfilledSynchronizer`, independently from AdX financial imports:

- The same existing SOAP connection, publisher timezone, FLAT unit view and exact
  selected unit are used. No `SITE_NAME` dimension or request-count substitute.
- `site_gam_unfilled_reports` stores only unit/day observations and their Google
  job provenance. No daily/hourly financial fact, dimension identity, settlement,
  ledger or historical monetary correction is written.
- The existing five-minute Site GAM synchronization invokes this independent path,
  even when AdX synchronization fails. Pending Google jobs resume; malformed or
  failed responses preserve verified observations. Missing CSV days stay unknown.
- Historical catch-up starts at the binding's original start, respects its end,
  and processes at most 31 days per invocation. Recent seven-day observations
  refresh hourly after catch-up. A reported zero is distinct from a missing day.
- UI and CSV label this original counter as **ad unit, all sites**. Projections
  match historical binding/source/organization/unit/day and deduplicate identical
  network/unit/timezone/day totals across financial dimensions or credentials.
  Other AdX metrics remain exact-domain and exact-unit.

Operational recovery and bounded initial verification:
`php artisan reporting:sync-unit-unfilled --wait=180`.
The command has no financial-import path and prints only closed statuses and
aggregate evidence coverage, never identifiers or raw Google responses. The
reviewed immediate successor of PR244 invokes it once after healthy deployment;
subsequent ordinary deployments skip this activation. Scheduled synchronization
continues to refresh the original counter.
