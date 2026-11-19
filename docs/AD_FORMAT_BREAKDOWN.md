# Breakdown by ad format

Publishers (`/publisher/reporting/ad-formats`) and Horus staff
(`/admin/reporting/publishers/{publisher}/ad-formats`) can view the same report
broken down by Google Ad Manager inventory format: Banner, Interstitial, Anchor,
Rewarded, Side rail, Native and any other format Google reports. The
**Breakdown by Ad Format** button sits on the publisher's Reports page, on each
admin website report, on the website-report cards and on the admin publisher
page. Both pages support
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

Ledger amounts without format evidence, such as other demand sources,
publisher-level amounts not tied to one website, or days not synchronized yet,
appear as **Not broken down**. They are never guessed. The "All websites" view
uses the same scope as the publisher's main report, so its total equals the main
report's main earnings. A single-website view covers that website only. Video
and mobile apps keep their own separate reports.

## Operator status

The admin page has an **Ad format data status** panel for each website. It shows
Up to date, Collecting history, Preparing, Retrying, Unsupported, or No GAM
reporting ad unit connected, plus the last day with format data and the last
synchronization time. It reads only stored checkpoints and never calls Google.
The panel opens automatically when a website needs attention.

## Compatibility evidence and live verification

Google's generated SOAP documentation (googleads-java-lib, v202608) marks both
format dimensions and every Ad Exchange column we use as compatible with the
same "Historical" report type:

- `INVENTORY_FORMAT`: "Breaks down impressions by inventory format id. Compatible
  with the Historical report type."
- `INVENTORY_FORMAT_NAME`: "Corresponds to 'Inventory format' in the Ad Manager UI.
  Compatible with the Historical report type."
- `AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS`, `_CLICKS`, `_REVENUE`,
  `AD_EXCHANGE_TOTAL_REQUESTS`, `AD_EXCHANGE_RESPONSES_SERVED` and `SITE_NAME`
  are also Historical.

Sharing a report type is not proof that Google accepts every combination for a
given network. Verify it live with one of these read-only checks, which store
nothing:

- **Dashboard:** open a publisher's *Breakdown by ad format*, choose one website
  and press **Verify with Google now**. This requires `reporting.sources.manage`.
- **Server:** `php artisan reporting:probe-ad-formats --site=<site id> --date=<Y-m-d>`

Both run the exact production query for one completed day and compare Google's
per-format Ad Exchange revenue with the stored report for that day. If Google
rejects the production query, narrower variants show which part it refuses
(Active View, the site dimension, or inventory format itself). The result is
one of SUPPORTED, PARTIALLY_SUPPORTED, UNSUPPORTED or INCONCLUSIVE, and it is
audited.

Publishers see the **Breakdown by Ad Format** button only after Google has
returned format data for their websites.
