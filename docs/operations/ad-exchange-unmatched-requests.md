# Website Ad Exchange unmatched requests

Website-bound GAM reporting uses exact `SITE_NAME` plus selected `AD_UNIT_ID`,
Google Ad Exchange counters, the source timezone, and canonical USD. The report
already persists `AD_EXCHANGE_TOTAL_REQUESTS` as `ad_requests` and
`AD_EXCHANGE_RESPONSES_SERVED` as `matched_requests` on each daily fact. The
source-reported `unfilled_impressions` field retains its own meaning; this connector
deliberately leaves it null when the source report does not provide it.

## Display contract

- `ad_exchange_unmatched_requests` is a **read-only projection** of requests minus
  responses for each proven website AdX fact, summed after per-fact validation.
- The visible label is **Ad Exchange unmatched requests** in cards, detail columns
  and CSV. It is never labeled unfilled impressions or substituted for the
  unfilled-impressions KPI.
- Provenance must include the exact `AD_EXCHANGE_V1` basis, website, selected unit
  and scope fingerprint stored with the fact. Current binding configuration cannot
  supply missing historical provenance.
- Zero requires known, equal request/response counters. Missing, inconsistent,
  legacy or mixed-source inputs remain unavailable. A negative difference never
  becomes zero, and a valid day cannot mask an invalid day during SQL aggregation.
- AdX requests can count an opportunity more than once across programmatic
  channels. Unmatched AdX requests can still be filled by other demand. This is
  not a unique empty-slot count and not requests minus impressions.
- Default cards, detail columns, website-directory metrics and CSV include
  **Unfilled impressions** for every source, including site AdX and mixed-source
  reports, alongside existing metrics. Site AdX keeps its existing unmatched-request
  metric; non-site sources retain their source-reported unfilled counts, including zero.
  Missing unfilled data remains unavailable rather than changing the metric.
- Explicit column selection does not replace either default KPI, relabel an
  existing URL's metric, or reinterpret stored unfilled values. Selecting both
  keeps both meanings visible. Non-site reports can still opt into the separate
  unmatched-request column without treating other-source counters as AdX data.
- A mixed-source aggregate with an unavailable unfilled counter remains unavailable;
  a known value from another source must not make the partial aggregate appear complete.

Existing correctly scoped facts can display immediately without reimport, source
API changes, migration, backfill, settlement adjustment, or any monetary write.
Ingestion continues to preserve the two original Google counters, with tests
covering the complete connector-to-persisted-fact-to-display route.

## Source semantics and limits

- [Google SOAP Column reference](https://developers.google.com/ad-manager/api/reference/v202608/ReportService.Column)
  defines Ad Exchange requests and responses served separately from inventory-wide
  `TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS`.
- [Google report metrics](https://support.google.com/admanager/table/7568664?hl=en)
  defines AdX match rate as responses served divided by AdX ad requests.
- [Google Data Transfer cookbook](https://support.google.com/admanager/answer/10248303?hl=en)
  describes separate page/ad-unit unfilled-request analysis; this is not a drop-in
  replacement for the existing site's report.

The current site-scoped AdX report does not return unfilled impressions. This is
not a claim that every Google API/report combination is universally incompatible.
An alternate report would need live compatibility and exact-site attribution
validation before use. Do not broaden to an ad-unit-only or network-total counter
or add unreviewed historical financial corrections to make the field non-null.
