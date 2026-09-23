# Website reporting through a GAM ad unit

An administrator opens **Site 360 → Reports**, selects an available Google Ad
Manager account, searches for an ad unit by name/code/ID, and selects **Connect
reports**. The exact name, code or numeric ID also works without search or
JavaScript. When only one account is available it is selected automatically.
Ambiguous names require selection of the intended ID. Account access, unit ID,
network currency and timezone are verified before the binding is saved. The GAM
network currency is retained only as source metadata: Horus requests all GAM
revenue metrics directly from Google in **USD**, the canonical platform reporting
currency, using the report currency field supported by Ad Manager.

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

- A binding covers exactly one Google network and ad-unit ID. It uses Google's
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
Current-day hourly estimates refresh hourly. Daily reports refresh every six
hours for each open month, including the initial current-month backfill. Google
preparation is asynchronous: pending job IDs are persisted and resumed instead of
blocking the administrator's save request. Requests have bounded SOAP timeouts;
failures back off and expired preparation jobs are retried.

Reports request total impressions, clicks, requests, responses, unmatched requests
and total CPM/CPC/CPD revenue, including dynamic allocation. Every GAM query sets
`reportCurrency=USD`; Google performs the source exchange-rate conversion. CSV_DUMP
revenue is therefore USD micros and is converted to Horus USD minor units once per
aggregate using integer rounding. Horus never performs a second AED/EUR/GBP→USD
conversion for GAM revenue. Dates and the returned unit ID
must match the binding. Malformed, oversized and failed downloads never create
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

References: [Google reporting workflow](https://developers.google.com/ad-manager/api/reporting),
[report query](https://developers.google.com/ad-manager/api/reference/v202608/ReportService.ReportQuery),
[report columns](https://developers.google.com/ad-manager/api/reference/v202608/ReportService.Column),
[CSV download options](https://developers.google.com/ad-manager/api/reference/v202608/ReportService.ReportDownloadOptions).
Runtime API versions are resolved from the installed SDK, never these documentation URLs.


## Legacy non-USD Site GAM connections

The five-minute Site GAM synchronizer self-heals active legacy connections whose
reporting currency was previously copied from the GAM network currency. When all
related finance periods are still open, it clears cached Google report jobs,
changes the canonical connection currency to USD, records the network currency as
metadata, and reimports the open rows from Google in USD. If any related non-USD
financial period is already closed, automatic conversion fails closed rather than
rewriting immutable statements/history; Finance must perform an explicit reviewed
historical migration instead.
