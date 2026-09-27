@props(['totals', 'currency'])
<section class="publisher-ad-metrics" aria-label="Ad performance metrics">
    @foreach([
        ['impressions', 'Impressions', 'Ads shown'],
        ['clicks', 'Clicks', 'Clicks on your ads'],
        ['ctr_bp', 'CTR', 'Clicks ÷ ads shown'],
        ['ecpm_minor', 'CPM (eCPM)', 'Your earnings per 1,000 ads'],
        ['viewability_bp', 'Active View', 'Viewable ÷ measurable ads'],
        ['unfilled_impressions', 'Unfilled impressions', 'Ad opportunities left unfilled'],
    ] as [$metric, $label, $hint])
        @php
            $unavailable = $totals[$metric] === null;
            $reason = match (true) {
                in_array($metric, ['ctr_bp', 'ecpm_minor'], true) => 'No impressions in this period',
                $metric === 'viewability_bp' && $totals['active_view_measurable_impressions'] === 0 => 'No measurable impressions',
                default => 'Incomplete source data',
            };
        @endphp
        <article class="publisher-ad-metric {{ $unavailable ? 'publisher-ad-metric--unavailable' : '' }}">
            <h3>{{ $label }}</h3>
            <strong>@if($unavailable)Unavailable @else{{ \App\Services\Reporting\PerformanceMetrics::display($metric, $totals[$metric]) }}@if($metric === 'ecpm_minor') <small>{{ $currency }}</small>@endif @endif</strong>
            <p>{{ $unavailable ? $reason : $hint }}</p>
        </article>
    @endforeach
</section>
