@extends('layouts.admin')
@section('title', $site->display_name.' · Reports')
@section('heading', 'Website report')
@section('content')
<div class="reports-page admin-website-reports">
    <nav class="report-finance-links" aria-label="Website report navigation"><a class="text-link" href="{{ route('admin.reporting.websites.index', ['from' => $summary['from'], 'to' => $summary['to'], 'metrics' => $reportMetrics]) }}">← Website reports</a>@if(auth()->user()->hasPermission('sites.view'))<a class="text-link" href="{{ route('admin.sites.show', $site) }}">Website settings</a>@endif</nav>
    <header class="report-page-heading"><div><p class="eyebrow">WEBSITE PERFORMANCE</p><h2>{{ $site->display_name }}</h2><p class="muted">{{ $site->primary_domain }} · {{ $site->publisher?->display_name }}</p></div><span class="report-state report-finality-state">{{ $summary['has_estimates'] ? 'Includes estimates · Awaiting finalization' : ($summary['available'] ? 'Finalized reports' : 'Awaiting data') }} · {{ $summary['currency'] }}</span></header>
    <x-report-period :from="$summary['from']" :to="$summary['to']" :metrics="$reportMetrics" />
    @if($summary['video']['available'] || ($summary['video']['has_configuration'] ?? $summary['video']['configured'] ?? false))
    <nav class="report-channel-nav" aria-label="Report sections"><a href="#main-performance">Main performance</a><a href="#video-performance">Video performance</a></nav>
    <header id="main-performance" class="report-main-heading"><h2>Main performance</h2><p>Primary website reports, excluding independent Video results below.</p></header>
    @endif
    <x-report-coverage :coverage="$summary['coverage']['main'] ?? []" />
    @if($summary['has_estimates'])<p class="report-footnote muted">Estimated results are awaiting finalization and may change. These reporting totals are not payout balances.</p>@endif
    @if($summary['available'])
    <section class="report-metrics" aria-label="Website revenue totals">
        @foreach([['Gross revenue', $summary['gross_revenue_minor'], $summary['currency']], ['Publisher earnings', $summary['publisher_earnings_minor'], $summary['currency']], ['Horus margin', $summary['horus_earnings_minor'], $summary['currency']], ['Impressions', $summary['impressions'], '']] as [$label, $value, $unit])
            <article class="report-kpi {{ $loop->first ? 'report-kpi-primary' : '' }}"><h3 class="eyebrow">{{ $label }}</h3><div class="report-kpi-value">{{ $unit ? \App\Support\Money::formatMinor($value) : \App\Services\Reporting\PerformanceMetrics::display('impressions', $value) }} @if($unit)<span>{{ $unit }}</span>@endif</div></article>
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
    <x-video-performance :coverage="$summary['coverage']['video'] ?? []" :video="$summary['video']" :from="$summary['from']" :to="$summary['to']" />
</div>
@endsection
