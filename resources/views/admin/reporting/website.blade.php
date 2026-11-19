@extends('layouts.admin')
@section('title', $site->display_name.' · Reports')
@section('heading', 'Website report')
@section('content')
<div class="reports-page admin-website-reports">
    <nav class="report-finance-links" aria-label="Website report navigation"><a class="text-link" href="{{ route('admin.reporting.websites.index', ['from' => $summary['from'], 'to' => $summary['to'], 'metrics' => $reportMetrics]) }}">← Website reports</a>@if(auth()->user()->hasPermission('sites.view'))<a class="text-link" href="{{ route('admin.sites.show', $site) }}">Website settings</a>@endif
    @if($site->publisher)<a class="hm-button-secondary ad-format-breakdown-button" href="{{ route('admin.reporting.publishers.ad-formats', ['publisher' => $site->publisher, 'site' => $site->id, 'from' => $summary['from'], 'to' => $summary['to']]) }}">Breakdown by Ad Format</a>@endif</nav>
    <header class="report-page-heading"><div><p class="eyebrow">WEBSITE PERFORMANCE</p><h2>{{ $site->display_name }}</h2><p class="muted">{{ $site->primary_domain }} · {{ $site->publisher?->display_name }}</p></div><span class="report-state report-finality-state"><x-report-data-state :available="$summary['available']" :has-estimates="$summary['has_estimates']" :coverage="$summary['coverage']['main'] ?? []" /> · {{ $summary['currency'] }}</span></header>
    <x-report-period :from="$summary['from']" :to="$summary['to']" :metrics="$reportMetrics" />
    @php($hasVideoSection = $summary['video']['available'] || ($summary['video']['has_configuration'] ?? $summary['video']['configured'] ?? false))
    @php($hasAdFormats = isset($adFormats) && $adFormats['available'])
    @if($hasVideoSection || $hasAdFormats)
    <nav class="report-channel-nav" aria-label="Report sections">@if($hasVideoSection)<a href="#main-performance">Main performance</a>@endif @if($hasAdFormats)<a href="#ad-formats">Ad formats</a>@endif @if($hasVideoSection)<a href="#video-performance">Video performance</a>@endif</nav>
    @endif
    @if($hasVideoSection)
    <header id="main-performance" class="report-main-heading"><h2>Main performance</h2><p>Primary website reports, excluding independent Video results below.</p></header>
    @endif
    <x-report-coverage :coverage="$summary['coverage']['main'] ?? []" />
    @if($summary['has_estimates'])<p class="report-footnote muted">Estimated results are awaiting finalization and may change. These reporting totals are not payout balances.</p>@endif
    @if($summary['available'])
    <section class="report-metrics" aria-label="Website revenue totals">
        @foreach([['Gross revenue', 'gross_revenue_minor', $summary['currency']], ['Publisher earnings', 'publisher_earnings_minor', $summary['currency']], ['Horus margin', 'horus_earnings_minor', $summary['currency']], ['Impressions', 'impressions', '']] as [$label, $key, $unit])
            @php($value = $summary[$key])
            <article class="report-kpi {{ $loop->first ? 'report-kpi-primary' : '' }}"><h3 class="eyebrow">{{ $label }}</h3><div class="report-kpi-value">{{ $unit ? \App\Support\Money::formatMinor($value) : \App\Services\Reporting\PerformanceMetrics::display('impressions', $value) }} @if($unit)<span>{{ $unit }}</span>@endif</div>@if($previous ?? null)<x-report-delta :metric="$key" :current="$value" :previous="$previous[$key] ?? null" :period="$previousPeriod" />@endif</article>
        @endforeach
    </section>
    <p class="muted report-footnote">Reported amounts before statement adjustments.@if($summary['metric_basis_incomplete'] ?? false) Performance metrics are unavailable for periods containing legacy website GAM counters.@endif</p>
    <x-report-performance-totals :show-help="false" :totals="$summary" :currency="$summary['currency']" />
    <article class="report-chart-card">
        <div class="report-card-heading"><h3>Revenue over time</h3><span class="report-state">Gross · {{ $summary['currency'] }}</span></div>
        <x-report-chart :rows="$summary['days']" :currency="$summary['currency']" :from="$summary['from']" :to="$summary['to']" label="Website daily gross revenue" :responsive="true" />
    </article>
    <section class="publisher-report-details" aria-labelledby="website-daily-heading">
        <h3 id="website-daily-heading">Daily breakdown</h3>
        <x-report-performance-table :rows="$summary['days']->reverse()->values()" :metrics="$reportMetrics" :currency="$summary['currency']" label-key="date" revenue-key="gross_revenue_minor" revenue-label="Gross revenue" :mobile-cards="true" caption="Website daily performance" />
    </section>
    @if($summary['updated_at'])<p class="publisher-report-updated">Last updated: {{ $summary['updated_at']->format('j M Y, H:i') }} ({{ config('app.timezone') }}).</p>@endif
    @else<x-empty-state :title="($summary['video']['available'] || ($summary['video']['configured'] ?? false)) ? 'No main reports for these dates' : 'No reports for these dates'" description="Try another reporting period. Missing data does not mean zero earnings." />@endif
    @if($hasAdFormats)
    <section id="ad-formats" class="report-ad-formats-section" aria-labelledby="website-ad-formats-heading">
        <header class="report-main-heading"><h2 id="website-ad-formats-heading">Ad formats</h2><p>The same period for this website, split by Banner, Interstitial, Anchor, Rewarded and other formats.</p></header>
        <x-report-ad-format-breakdown :breakdown="$adFormats" :metrics="$adFormatMetrics" :compact="true" :previous="$previousAdFormats ?? null" :period="$previousPeriod ?? null"
            :full-url="$site->publisher ? route('admin.reporting.publishers.ad-formats', ['publisher' => $site->publisher, 'site' => $site->id, 'from' => $summary['from'], 'to' => $summary['to']]) : null" />
    </section>
    @endif
    <x-video-performance :coverage="$summary['coverage']['video'] ?? []" :video="$summary['video']" :from="$summary['from']" :to="$summary['to']" />
</div>
@endsection
