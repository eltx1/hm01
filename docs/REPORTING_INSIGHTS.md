# Reporting insights

These features build on [Breakdown by ad format](AD_FORMAT_BREAKDOWN.md). None
of them writes to the financial ledger.

1. **Comparison with the previous period.** Each report shows the change
   against the period of the same length immediately before it: a relative %
   for amounts and counts, percentage points for CTR and Active View. No
   change is shown when there is no previous data or the previous value is
   zero.
2. **Daily trend by format.** Stacked daily bars on the full breakdown pages
   for ad formats and devices. Each value keeps a fixed colour from a
   CVD-validated palette, in both the dark and the light theme. The daily
   table remains the value view.
3. **Drop alerts.** `reporting:ad-format-alerts` runs daily at 07:35 UTC. It
   compares each website and format with its own 14-day average and reports
   when revenue falls below 50% of it, or when eCPM falls below 60% while
   volume is stable. Thresholds live under `reporting.ad_format_alerts`. Days
   that have not been synchronized are skipped. Horus reporting staff are
   notified once per drop, and the admin ad format pages list yesterday's
   drops.
4. **Revenue opportunities.** A website is shown the web formats it does not
   use that at least 3 peer websites earn from. The estimate is the network
   median share over 30 days and reveals no individual peer's figures.
   Publishers see the estimate converted to their own earnings.
5. **Website ranking by eCPM per format.** Admin only. A minimum number of
   impressions is required (`reporting.ad_format_ranking`), and the network
   median is shown for reference.
6. **Country and device breakdowns.** `SiteGamDimensionSynchronizer` uses the
   same exact-site Ad Exchange basis, with `COUNTRY_CODE`/`COUNTRY_NAME` or
   `DEVICE_CATEGORY` added. It fetches the newest days first, keeps 90 days of
   history and refreshes every 3 hours. Choose the view with `?by=country` or
   `?by=device`. Countries are shown as the top 15 plus "Other countries".
7. **Earnings reports.** `reporting:send-earnings-digests` sends a daily
   report at 08:15 UTC and a weekly one on Mondays at 08:25 UTC, through the
   existing notification pipeline. The new **Reports** notification category
   is emailed by default, and users can switch it off in their notification
   preferences.
8. **Faster deploys.** See `docs/DEPLOYMENT.md`.
