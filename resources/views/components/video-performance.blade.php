@props(['video', 'publisher' => false, 'from' => null, 'to' => null])
@if($video['available'] || ($video['configured'] ?? false) || ($video['has_configuration'] ?? false))
@php
    $start = $from ? \Carbon\CarbonImmutable::parse($from)->toDateString() : ($video['days']->first()['date'] ?? now()->toDateString());
    $end = $to ? \Carbon\CarbonImmutable::parse($to)->toDateString() : ($video['days']->last()['date'] ?? $start);
    $earningsLabel = $publisher ? 'Video earnings' : 'Video gross revenue';
@endphp
<section id="video-performance" class="video-report" aria-labelledby="video-performance-heading">
    <header class="video-report-heading">
        <div><p class="eyebrow">VIDEO REPORTING</p><h2 id="video-performance-heading">Video performance</h2><p class="muted">{{ \Carbon\CarbonImmutable::parse($start)->format('j M Y') }} – {{ \Carbon\CarbonImmutable::parse($end)->format('j M Y') }}</p></div>
        <div class="video-report-actions">
            <span class="report-state">{{ $video['has_estimates'] ? 'Includes estimates' : ($video['available'] ? 'Finalized reports' : 'No imported data') }}</span>
            @if($video['available'])<a class="hm-button-secondary" href="{{ request()->fullUrlWithQuery(['export' => 'video_csv']) }}">Export Video CSV</a>@endif
        </div>
    </header>
    <p class="video-report-intro">Independent Video results for this period. Video earnings are included once in financial balances and statements.</p>
    @if(($video['configuration_state'] ?? '') === 'disabled')
    <aside class="video-source-notice"><p>Video reporting is disabled. Historical results remain available for their original dates.</p></aside>
    @elseif(($video['source_health'] ?? '') === 'failed')
    <aside class="video-source-notice"><p>The latest Video refresh did not complete. Previously imported results are preserved; recent dates may be missing.</p></aside>
    @elseif($video['available'] && ($video['source_health'] ?? '') === 'pending')
    <aside class="video-source-notice"><p>Some Video sources are still awaiting their first import. These totals reflect the data received so far.</p></aside>
    @endif
    @if($video['available'])
    <div class="report-metrics video-report-metrics" aria-label="Video performance totals">
        @foreach([
            ['revenue_minor', $earningsLabel, $publisher ? 'After your revenue share' : 'Before revenue share'],
            ['impressions', 'Video impressions', 'For the matched website'],
            ['ecpm_minor', 'Video eCPM', $publisher ? 'Your earnings per 1,000 impressions' : 'Gross revenue per 1,000 impressions'],
            ['unfilled_impressions', 'Video Unfilled', 'Selected ad unit · all sites'],
        ] as [$key, $label, $hint])
        <article class="report-kpi {{ $loop->first ? 'report-kpi-primary' : '' }}"><h3 class="eyebrow">{{ $label }}</h3><strong class="report-kpi-value">{{ $key === 'revenue_minor' ? \App\Support\Money::formatMinor($video[$key]) : ($video[$key] === null ? 'Unavailable' : \App\Services\Reporting\PerformanceMetrics::display($key, $video[$key])) }} @if(in_array($key, ['revenue_minor', 'ecpm_minor']) && $video[$key] !== null)<span>{{ $video['currency'] }}</span>@endif</strong><p class="report-kpi-hint">{{ $hint }}</p></article>
        @endforeach
    </div>
    @unless($publisher)
    <dl class="video-allocation" aria-label="Video revenue allocation"><div><dt>Video publisher earnings</dt><dd>{{ \App\Support\Money::formatMinor($video['publisher_earnings_minor']) }} {{ $video['currency'] }}</dd></div><div><dt>Video Horus margin</dt><dd>{{ \App\Support\Money::formatMinor($video['horus_earnings_minor']) }} {{ $video['currency'] }}</dd></div></dl>
    @endunless
    <article class="report-chart-card video-report-chart">
        <div class="report-card-heading"><h3>{{ $publisher ? 'Video earnings over time' : 'Video revenue over time' }}</h3><span class="report-state">{{ $publisher ? 'Your earnings' : 'Gross revenue' }} · {{ $video['currency'] }}</span></div>
        <x-report-chart :rows="$video['days']" value-key="revenue_minor" :currency="$video['currency']" :from="$start" :to="$end" :label="$publisher ? 'Daily Video publisher earnings' : 'Daily Video gross revenue'" :responsive="true" />
        <div class="report-chart-foot"><span><i aria-hidden="true"></i> {{ $earningsLabel }}</span><span>{{ $video['days']->count() }} imported {{ $video['days']->count() === 1 ? 'day' : 'days' }} · Missing days remain gaps, not zero.</span></div>
    </article>
    <section class="publisher-report-details" aria-labelledby="video-daily-heading">
        <div class="report-card-heading"><h3 id="video-daily-heading">Video daily breakdown</h3><span class="report-state">{{ $video['currency'] }} · Newest first</span></div>
        <x-video-performance-table :rows="$video['days']->reverse()->values()" :totals="$video" :publisher="$publisher" :currency="$video['currency']" />
    </section>
    <section class="publisher-report-details" aria-labelledby="video-websites-heading">
        <div class="report-card-heading"><h3 id="video-websites-heading">Video website breakdown</h3><span class="report-state">{{ $video['websites']->count() }} {{ $video['websites']->count() === 1 ? 'website' : 'websites' }}</span></div>
        <x-video-performance-table :rows="$video['websites']" :publisher="$publisher" :currency="$video['currency']" label="Website" />
    </section>
    @else
        <div class="video-report-empty"><h3>No Video results for this period</h3>
        @if(($video['starts_on'] ?? null) && $end < $video['starts_on'])
            <p>Video reporting starts on {{ \Carbon\CarbonImmutable::parse($video['starts_on'])->format('j M Y') }}. The selected period is before activation.</p>
        @elseif(($video['configuration_state'] ?? '') === 'disabled')
            <p>No historical Video results were imported for these dates.</p>
        @elseif(($video['source_health'] ?? '') === 'failed')
            <p>Results will appear after a successful Video refresh.</p>
        @elseif(($video['source_health'] ?? '') === 'pending' || ($video['configured'] ?? false) && !($video['last_successful_import_at'] ?? null))
            <p>Video reporting is enabled. Awaiting imported statistics for these dates.</p>
        @else
            <p>No imported Video results match the selected period.</p>
        @endif
        <p class="muted">Missing data does not mean zero earnings.@unless($publisher) This admin report includes finalized data only; today's estimates may not appear yet.@endunless</p></div>
    @endif
    @if($video['starts_on'] ?? null)<p class="report-footnote muted">Reporting began {{ \Carbon\CarbonImmutable::parse($video['starts_on'])->format('j M Y') }}.@if($video['ends_on'] ?? null) Last owned reporting day: {{ \Carbon\CarbonImmutable::parse($video['ends_on'])->format('j M Y') }}.@endif</p>@endif
    <details class="report-data-details video-report-basis">
        <summary>How to read Video metrics</summary>
        <p>Impressions and revenue use the matched website. Unfilled is Google's original selected-unit total across all sites, not exact-hostname requests. These scopes differ; Unfilled is not a website fill rate.</p>
        <p>Video eCPM uses {{ $publisher ? 'your earnings after revenue share' : 'gross revenue before revenue share' }} per 1,000 Video impressions. Unavailable means the source did not supply a complete metric or there is no eligible denominator.</p>
        <p>Reporting dates follow each selected network's reporting day.@if(count($video['timezones'] ?? []) > 1) Multiple source clocks: {{ implode(', ', $video['timezones']) }}. Days are grouped by source date, not converted to a common timezone.@elseif(count($video['timezones'] ?? []) === 1) Network timezone: {{ $video['timezones'][0] }}.@endif</p>
    </details>
    @if($video['updated_at'])<p class="publisher-report-updated">Video last updated: {{ $video['updated_at']->format('j M Y, H:i') }} ({{ config('app.timezone') }}).</p>@endif
</section>
@endif
