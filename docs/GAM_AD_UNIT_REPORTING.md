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
