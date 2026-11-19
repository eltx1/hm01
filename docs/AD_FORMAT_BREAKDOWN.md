# Breakdown by ad format

Publishers (`/publisher/reporting/ad-formats`) and Horus staff
(`/admin/reporting/publishers/{publisher}/ad-formats`) can view the same report
broken down by Google Ad Manager inventory format: Banner, Interstitial, Anchor,
Rewarded, Side rail, Native and any other format Google reports. The
**Breakdown by Ad Format** button sits on the publisher's Reports page, on each
admin website report and on the website-report cards. Both pages support
Today / Yesterday / Last 7 days / This month / Last month, custom dates, an
optional single-website filter, column selection and CSV export.

## Where the data comes from

`reporting:sync-site-gam` (every five minutes, under the main reporting lock)
runs `SiteGamAdFormatSynchronizer` for each website GAM reporting binding, after
the ledger import and the Unfilled sidecar. It uses exactly the same Google
query as the ledger import (same bound ad unit, `FLAT` view, USD report currency,
publisher time zone, Ad Exchange metric basis and exact registered hostname),
with two extra dimensions: `INVENTORY_FORMAT` and `INVENTORY_FORMAT_NAME`. These
are available in every SOAP version the installed `googleads-php-lib` ships.

- At most one bounded report of up to 31 days per pass. History goes back up to
  400 days from the binding start, then the last 7 days are refreshed hourly.
- Each completed Google report replaces the stored rows for its dates in
  `site_gam_ad_format_reports`, so corrections never add up twice.
- Checkpoints live under `configuration.ad_format_breakdown` on the reporting
  connection. If Google does not support the format dimension for a network,
  the state becomes `FORMAT_DIMENSION_UNSUPPORTED` and is retried after 24 hours.
  Other failures retry after `reporting.retry_delay_minutes`.
- It never writes `daily_reports`, report scopes, import jobs or source health.

## How amounts are calculated

Money always comes from the existing ledger. For each website, source and day,
the stored gross revenue, publisher earnings and Horus margin are split across
formats in proportion to the Ad Exchange revenue Google reported for each format
that day. A largest-remainder split keeps every format in whole cents, so the
formats always add up exactly to the main report. Publisher pages show only
publisher earnings, never gross revenue or margin.

Impressions, clicks, requests and Active View per format are Google's own
counters. Unfilled impressions are not available per format.

Ledger amounts without format evidence, such as other demand sources or days
not synchronized yet, appear as **Not broken down**. They are never guessed.
Video and mobile apps keep their own separate reports.
