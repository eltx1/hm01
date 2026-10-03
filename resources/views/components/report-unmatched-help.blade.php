@props(['totals'])
@if($totals['has_site_ad_exchange'] ?? false)
<p class="report-footnote muted report-unmatched-help">Unfilled impressions are a source-reported metric and are not provided by this website Ad Exchange report. Missing values remain unavailable, never estimated from requests. Ad Exchange unmatched requests is a separate request metric: Ad Exchange ad requests − responses served for the same website and ad unit. These are requests to Ad Exchange, not empty ad slots; another demand source may still serve an ad.</p>
@endif
