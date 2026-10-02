# Website reporting through a GAM ad unit

An administrator opens **Site 360 → Reports**, selects an available Google Ad
Manager account, searches for an ad unit by name/code/ID, and selects **Connect
reports**. The exact name, code or numeric ID also works without search or
JavaScript. When only one account is available it is selected automatically.
Ambiguous names require selection of the intended ID. Account access, unit ID,
network currency and timezone are verified before the binding is saved. The network currency is retained as source metadata; Horus always requests report revenue in **USD**. This is a fixed accounting/reporting invariant, not an environment-selectable display preference.

If no account has been connected, select **Connect your first Ad Manager
account** in the same section. Google sign-in discovers one or several networks
and returns to the website with the account selected. **Connect another Ad Manager
account** adds further Google identities or networks. One-time OAuth app setup
and a service-account file alternative are available in that screen; see
`docs/GAM_REPORTING_ACCOUNT_ONBOARDING.md`.

This creates a `GAM_AD_UNIT` source connection with type `SITE_GAM_AD_UNIT`.
It does not assign the site's serving GAM connection, change its serving mode,
create advertising inventory, or publish a CDN configuration. Existing Horus,
MCM, Publisher GAM, and CSV reporting paths continue to operate.

## Data ownership and dates

- A binding covers exactly one Google network and ad-unit ID intersected with the
  website's registered hostname through Google's `SITE_NAME` dimension. Hostnames
  are normalized from bare names or configured HTTP(S) URLs; scheme/path, case and
  a terminal DNS root dot are removed. Subdomains and `www` stay distinct. The
  hostname is automatic for current and future websites, with no extra input.
  It uses Google's
  `FLAT` view: child units are excluded. Renaming an ad unit does not change its ID.
- One active website owns a physical network/unit pair, even when the same network
  has several credential connections. Publisher-owned accounts are scoped to their
  organization; Horus and partner MCM connections can be selected by administrators.
- Initial coverage starts in the current open network-calendar month, after any
  already imported days for this website or its full-network GAM source and after
  locked financial periods. The effective date appears on the confirmation and
  website. No earlier financial records are deleted or reassigned.
- Changing the selection versions the binding and gives the previous binding an
  end date. Its open financial periods remain synchronizable through that date.
- From the effective date, the site source owns the website's reporting. Imports
  from other sources exclude that site's overlapping rows, retaining other sites
  and prior dates. Full-network GAM imports also exclude the selected Google unit.
  Exclusions appear in import warnings; source reconciliation uses retained totals.

## Automatic synchronization

`reporting:sync-site-gam` runs every five minutes through the existing scheduler.
It requires no permanent worker. Each binding uses the Google network timezone.
Current-day daily estimates refresh hourly. Daily reports refresh every six
hours for each open month, including the initial current-month backfill. Google
preparation is asynchronous: pending job IDs are persisted and resumed instead of
blocking the administrator's save request. Requests have bounded SOAP timeouts;
failures back off and expired preparation jobs are retried.

Website reports use the explicit `AD_EXCHANGE_V1` basis: Ad Exchange impressions,
clicks, revenue, ad requests and responses served. The exact Google columns are
defined in `SiteGamReportMetrics`; full-network reporting retains its separate
existing contract. Every website GAM query sends
`reportCurrency=USD`, regardless
of the network's base currency. Google performs the reporting FX conversion using
its report-currency rules; Horus stores the returned CSV_DUMP micros directly in
USD and converts micros to minor units once per aggregate. Dates and the returned unit ID
must match the binding. `SITE_NAME` is required; rows for another/unknown/not-applicable
Site cannot contribute revenue or delivery metrics. The selected unit filter remains
in Google; exact Site selection is also enforced locally without assuming PQL Site
filter support. `DOMAIN` and unscoped totals never substitute for the requested
exact hostname. Optional Ad Exchange Active View columns may be omitted after
an explicit unsupported-column response, while Site, unit, currency and all five
core Ad Exchange metrics remain unchanged. Missing Active View counters stay null.
Unfilled impressions are always unavailable for this contract; Horus does not
substitute Total unfilled impressions or infer them from requests. The existing
internal unmatched-request calculation remains distinct from unfilled impressions.
Unsupported core queries fail visibly.
Malformed, oversized and failed downloads never create
zero-revenue reports. A completed, valid report fills omitted dates/hours with zero
to correctly apply downward corrections. Download URLs are Google HTTPS URLs;
temporary signatures are excluded from persisted operation responses.

The normal import, revenue-rule, reconciliation, monthly close and statement
pipeline is used. Hourly rows remain estimated. Missing daily coverage blocks
financial close. A complete site source can satisfy demand-account financial
coverage for the mapped site; other uncovered sites still block closure. Existing
payment approval and payout controls continue to apply.

For operations, inspect the source's last import/error and recent import jobs in
Reporting. `php artisan reporting:sync-site-gam --site=<site-ulid>` runs the same
bounded reporting synchronization for one website. Google credentials remain in
the existing GAM connection secret store. No credentials are entered in the site
binding form.

## Forward-only scope upgrade and historical review

`site_report_scope` records a canonical fingerprint of binding, network, unit,
exact hostname, metric basis, currency, timezone, version and effective date. New/empty bindings
use their usual start date. Existing bindings with stored daily/hourly facts
automatically start exact-site attribution at the later of the current network
day or the day after the last stored fact. An already imported current day therefore
switches the following day. No binding must be recreated. Hostname changes create
a new recorded forward scope; earlier scopes remain in operational history.
Changing the basis from legacy Total to Ad Exchange also versions this scope.

The scheduler clamps its month windows and pending retries to that date. Google
job keys include the fingerprint, and asynchronous checkpoint writes revalidate it.
Financial rows carry the exact Site, `gam_report_basis` and fingerprint in their dimension provenance.
Imports reject conflicting same-day provenance inside the financial transaction.
Old amounts are neither overwritten nor duplicated by a new scope. Earlier balances
remain unverified until a separate, private comparison preview and explicit
historical-correction approval; this change does not alter statements, payouts or
their existing approval rules. The site reporting view discloses that boundary.

## Production compatibility gate

Before release transfer/switch/migrations, the trusted-main deployment workflow
streams `ops/audit/gam-site-scope-preflight.php` into the currently deployed app.
It uses existing credentials to generate temporary report jobs for enabled active
bindings: the five mandatory Ad Exchange columns, `DATE + AD_UNIT_ID + SITE_NAME`, selected
unit filter, `FLAT`, network-calendar yesterday and USD. It polls to completion
and validates the CSV schema, unit/date and monetary currency proof. No fallback
query is permitted to satisfy this preflight. Optional Active View availability
does not replace any mandatory metric. Unsupported, failed, invalid or timed-out
reports abort deployment before production code changes.

For a column/dimension incompatibility only, the first rejected binding receives
at most six diagnostic profiles: the three finance metrics together and each
mandatory column individually, preserving the original scope. Each receives one
submission and at most one status check within a further 180-second budget.
Pending reports remain inconclusive. Even compatible subsets never turn the
original rejection into a successful gate. Output contains fixed profile names
and compatibility flags only.

The probe is bounded to 25 active bindings and a 180-second scheduling/poll budget
(individual existing network/download timeouts still apply). A larger installation
requires a reviewed batching adjustment, not a silent skip. With no active bindings,
the gate reports zero tested bindings rather than proving live network compatibility.
The incoming column contract has a test against the release connector.

Only normal operational audit/authentication bookkeeping is written. No import, financial,
source, binding or saved-report-definition writes occur. Public workflow output is
limited to compatibility flags/counts and allowlisted failure codes; CSV, monetary
amounts, identities, signed download URLs and raw errors are never published.

References: [Google reporting workflow](https://developers.google.com/ad-manager/api/reporting),
[report query](https://developers.google.com/ad-manager/api/reference/v202608/ReportService.ReportQuery),
[report columns](https://developers.google.com/ad-manager/api/reference/v202608/ReportService.Column),
[CSV download options](https://developers.google.com/ad-manager/api/reference/v202608/ReportService.ReportDownloadOptions).
Runtime API versions are resolved from the installed SDK, never these documentation URLs.

## Private historical preview

Horus administrators with `reporting.admin.view` can open **Private historical GAM
preview** from Reporting. The preview accepts at most 31 completed network-local
calendar days within the existing active binding. It independently requests
`DATE`, `AD_UNIT_ID`, and `SITE_NAME` with the three core Ad Exchange revenue,
impression, and click columns, USD, the publisher/network timezone, and the exact
selected ad-unit filter. The parser retains only the exact registered hostname
using the same case/trailing-dot normalization as the forward importer.

The shared `SiteGamReportMetrics::BASIS` and finance columns bind the preview to
the canonical Ad Exchange contract. This report contract is not assumed to be
supported by Google. An unsupported
column/dimension combination, missing currency proof, malformed report, or absent
exact-site row remains unavailable or unverified. There is no metric/scope
fallback and a missing row is never presented as zero earnings. The production
compatibility gate remains independent of this preview.

The actor-bound server-side session holds up to three previews for 30 minutes.
Session locks serialize requests, and a checkpoint is saved before submitting a
Google job so an interrupted or uncertain attempt cannot be silently resubmitted.
Each read, poll, and private download revalidates binding, source scope, query,
stored facts, periods, and original-rule snapshots. Responses are private/no-store.
Downloads omit raw source CSV, nonmatching sites, and complete financial models.
No result is written to public files, fixtures, or repository artifacts.

Legacy rows without `gam_report_basis` remain explicitly labeled Total-era /
unversioned; they are never relabeled as Ad Exchange. The preview separately
identifies the new basis, and explains that an old-to-new difference may combine a
metric-basis change and a scope change. It is not an approved correction.

Daily projections preserve integer revenue micros, the importer's signed daily
cent rounding, original persisted rule versions, existing per-fact deductions,
and the calculator's allocation remainder. Unknown/default-only rules, mixed
identities/currencies, unsupported dimensions, multiple stored facts, allocation
mismatches, and non-open periods withhold projections. Separate approved
adjustments are explicitly excluded and unchanged. This feature has no apply
endpoint and never calls financial importers, scope initialization, source or
scheduler checkpoint writers, period closing, statement, balance, or payout
writers. A preview does not establish replacement approval or settlement safety.

## Reviewed historical correction

The separate **Historical corrections** page requires a Horus administrator with
`reporting.admin.view`, `reporting.import`, and `finance.adjustments.approve` on
every action. Each candidate belongs to its requesting administrator. Preparing
or refreshing a candidate writes only private correction evidence and normal
Google operation/authentication bookkeeping. It does not import financial facts.

A candidate covers at most 31 completed network-calendar days within one month,
entirely before the existing verified forward scope's effective date. It uses the
same five mandatory Ad Exchange metrics and optional Active View pair as forward
reporting. The full requested range must have exactly one verified existing daily
fact, its original applicable rule version, and an observed exact-Site Google row
for every day. Missing rows remain unobserved, never inferred zero; an incomplete
range cannot silently become an eligible subset. Request a deliberately narrower
range if necessary. Existing estimates can be proposed as finalized only for
completed days, with that transition shown explicitly alongside all allocations.

Candidates are durable, bounded to six unexpired unapplied candidates per actor,
and expire for application after one hour. Ready, blocked or failed candidates
can be explicitly superseded by a request for fresh evidence; their evidence is
retained and the replacement requires another review. In-flight and applied
candidates cannot be superseded. An uncertain initial Google request is
recorded before submission and is never automatically resubmitted. Polling is
bounded, and completed evidence fixes the query, Google job, original snapshot,
daily proposal and recursively canonicalized digest. Downloaded comparison JSON
is not an apply payload. Submitted amounts, rules and scope are never trusted.

Application requires the administrator to review the daily changes, confirm the
review, submit the exact candidate digest and provide a reason. Inside the normal
source import lock and one database transaction, the service locks the network,
site, binding, source, period, facts, dimensions and original rules. It rechecks
the captured identities, complete fact/rule snapshot and downstream finance state.
Only an unambiguous OPEN period is supported. Existing monthly snapshots,
statements (including drafts), payouts, settlements, affiliate commissions,
overlapping pending imports, hourly facts and pending adjustments block this path.
Approved separate adjustments remain unchanged and part of the freshness check.

The service replaces the identified daily rows in place, increments revisions,
records exact historical Ad Exchange provenance and retains each original rule
and deduction. It never rewinds the forward scope or its Google/scheduler jobs.
Unsupported old Total performance counters are cleared; only fresh Ad Exchange
counters contribute. Existing calculator rounding and signed-revenue policy remain
unchanged. An API correction import and exact reconciliation are committed with an
immutable receipt containing complete bounded before/after facts, dimensions,
rules, finance evidence, hashes and the approving actor/reason. Receipts are not
operational audit logs and are excluded from pruning; migration rollback refuses
to discard applied receipts. Repeated application returns the same receipt.

This capability does not automatically approve candidates, reopen periods,
regenerate statements, alter payments, or expose a reversal action. A closed or
materialized period needs a separately reviewed accounting procedure. Possessing
a candidate or deploying this feature does not authorize applying it.
