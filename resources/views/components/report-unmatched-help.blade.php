@props(['totals'])
@if($totals['has_site_ad_exchange'] ?? false)
<p class="report-footnote muted report-unmatched-help">Ad Exchange unmatched requests = Ad Exchange ad requests − responses served, for the same website and ad unit. These are requests to Ad Exchange, not empty ad slots; another demand source may still serve an ad. Unfilled impressions are a different source metric and are not provided by this website Ad Exchange report. Unavailable means a counter or its reporting basis is missing or inconsistent for part of the period.</p>
@endif
