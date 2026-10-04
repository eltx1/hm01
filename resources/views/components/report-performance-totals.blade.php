@props(['totals', 'currency', 'publisher' => false, 'showHelp' => true])
<section class="report-quality-metrics" aria-label="Ad performance metrics">
    @foreach(array_diff(\App\Services\Reporting\PerformanceMetrics::defaultColumns($totals), ['impressions']) as $metric)
    <article class="report-quality-metric"><p>{{ \App\Services\Reporting\PerformanceMetrics::label($metric, $totals) }}</p><strong>{{ \App\Services\Reporting\PerformanceMetrics::display($metric, $totals[$metric]) }}@if($metric === 'ecpm_minor' && $totals[$metric] !== null) <small>{{ $currency }}</small>@endif</strong></article>
    @endforeach
</section>
<x-report-unmatched-help :totals="$totals" />
@if($showHelp)<p class="report-footnote muted">CTR = clicks ÷ impressions. CPM (eCPM) uses {{ $publisher ? 'your earnings after your revenue share' : 'gross revenue before revenue shares' }} per 1,000 impressions. Active View = viewable ÷ measurable impressions. Unfilled impressions are reported by the source, not estimated from requests. — means data is unavailable for all or part of the period, or the denominator is zero.</p>@endif
