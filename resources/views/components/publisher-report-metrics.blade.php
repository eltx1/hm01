@props(['totals', 'currency', 'previous' => null, 'period' => null])
<section class="publisher-ad-metrics" aria-label="Ad performance metrics">
    @foreach(\App\Services\Reporting\PerformanceMetrics::defaultColumns($totals) as $metric)
        @php($unavailable = $totals[$metric] === null)
        <article class="publisher-ad-metric {{ $unavailable ? 'publisher-ad-metric--unavailable' : '' }}">
            <h3>{{ \App\Services\Reporting\PerformanceMetrics::label($metric, $totals) }}</h3>
            <strong>@if($unavailable)Unavailable @else{{ \App\Services\Reporting\PerformanceMetrics::display($metric, $totals[$metric]) }}@if($metric === 'ecpm_minor') <small>{{ $currency }}</small>@endif @endif</strong>
            @if($previous)<x-report-delta :metric="$metric" :current="$totals[$metric]" :previous="$previous[$metric] ?? null" :period="$period" />@endif
        </article>
    @endforeach
</section>
