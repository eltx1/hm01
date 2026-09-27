@extends('layouts.admin')
@section('title', $site->display_name.' · Reports')
@section('heading', 'Website report')
@section('content')
<div class="reports-page admin-website-reports">
    <nav class="report-finance-links" aria-label="Website report navigation"><a class="text-link" href="{{ route('admin.reporting.websites.index', ['from' => $summary['from'], 'to' => $summary['to'], 'metrics' => $reportMetrics]) }}">← Website reports</a>@if(auth()->user()->hasPermission('sites.view'))<a class="text-link" href="{{ route('admin.sites.show', $site) }}">Website settings</a>@endif</nav>
    <header class="report-page-heading"><div><p class="eyebrow">WEBSITE PERFORMANCE</p><h2>{{ $site->display_name }}</h2><p class="muted">{{ $site->primary_domain }} · {{ $site->publisher?->display_name }}</p></div><span class="report-state">Finalized reports · {{ $summary['currency'] }}</span></header>
    <x-report-period :from="$summary['from']" :to="$summary['to']" :metrics="$reportMetrics" />
    @if($summary['available'])
    <section class="report-metrics" aria-label="Website revenue totals">
        @foreach([['Gross revenue', $summary['gross_revenue_minor'], $summary['currency']], ['Publisher earnings', $summary['publisher_earnings_minor'], $summary['currency']], ['Horus margin', $summary['horus_earnings_minor'], $summary['currency']], ['Impressions', $summary['impressions'], '']] as [$label, $value, $unit])
            <article class="report-kpi {{ $loop->first ? 'report-kpi-primary' : '' }}"><h3 class="eyebrow">{{ $label }}</h3><div class="report-kpi-value">{{ $unit ? \App\Support\Money::formatMinor($value) : number_format($value) }} @if($unit)<span>{{ $unit }}</span>@endif</div></article>
        @endforeach
    </section>
    <p class="muted report-footnote">Reported amounts before statement adjustments.</p>
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
    @else<x-empty-state title="No finalized reports for these dates" description="Try another reporting period." />@endif
</div>
@endsection
