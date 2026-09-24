@extends('layouts.admin')
@section('title', 'Reports')
@section('heading', 'Reports')
@section('content')
<div class="reports-page">
<header class="report-page-heading"><div><p class="eyebrow">REVENUE INTELLIGENCE</p><h2>Performance overview</h2><p class="muted">A clear view of your revenue and the publishers behind it.</p></div><span class="report-state"><span aria-hidden="true">●</span> Finalized data · {{ $summary['currency'] }}</span></header>
<x-report-period :from="$summary['from']" :to="$summary['to']" />
@if(!$summary['available'])<x-empty-state title="No finalized reports for these dates" description="Try another period or check source imports below. Missing reports do not mean zero revenue." />@endif
<section class="report-metrics" aria-label="Performance totals">
@foreach ([
['Net revenue',\App\Support\Money::formatMinor((int) $summary['net_revenue_minor']),$summary['currency'],'After approved adjustments'],
['Publisher earnings',\App\Support\Money::formatMinor((int) $summary['publisher_earnings_minor']),$summary['currency'],'Publisher share'],
['Horus margin',\App\Support\Money::formatMinor((int) $summary['horus_margin_minor']),$summary['currency'],'Horus share'],
['Managed impressions',number_format($summary['managed_impressions']),'','Reported ad delivery']
] as [$label,$value,$unit,$hint])<article class="report-kpi {{ $loop->first ? 'report-kpi-primary' : '' }}"><p class="eyebrow">{{ $label }}</p><div class="report-kpi-value">{{ $value }} @if($unit)<span>{{ $unit }}</span>@endif</div><p class="report-kpi-hint">{{ $hint }}</p></article>@endforeach
</section>
<article class="report-chart-card">
    <div class="report-card-heading"><div><p class="eyebrow">REVENUE TREND</p><h3>Daily performance</h3></div><div class="report-chart-total"><strong>{{ \App\Support\Money::formatMinor((int) $summary['gross_revenue_minor']) }} <small>{{ $summary['currency'] }}</small></strong><span>Gross revenue · before adjustments</span></div></div>
    @if($summary['available'])
        <x-report-chart :rows="$summary['daily_revenue']" :currency="$summary['currency']" :from="$summary['from']" :to="$summary['to']" />
        <div class="report-chart-foot"><span><i aria-hidden="true"></i> Gross revenue</span><span>Only imported days are plotted · Gaps indicate missing data</span></div>
        <details class="report-data-details"><summary>View daily figures</summary><div class="table-wrap"><table><thead><tr><th scope="col">Date</th><th scope="col">Gross revenue ({{ $summary['currency'] }})</th></tr></thead><tbody>@foreach($summary['daily_revenue'] as $day)<tr><th scope="row">{{ $day['date'] }}</th><td>{{ \App\Support\Money::formatMinor((int) $day['gross_revenue_minor']) }}</td></tr>@endforeach</tbody></table></div></details>
    @else<p class="report-chart-empty muted">Your revenue trend will appear when finalized reports are available.</p>@endif
</article>
<section class="report-breakdowns">
@foreach ([['Revenue by publisher',$summary['revenue_by_publisher']],['Revenue by website',$summary['revenue_by_website']],['Revenue by demand source',$summary['revenue_by_source']],['Revenue by campaign',$summary['revenue_by_campaign']]] as [$heading,$rows])
<article class="report-breakdown"><div class="report-card-heading"><h3>{{ $heading }}</h3><span class="report-count">{{ count($rows) }}</span></div><div class="report-list-labels"><span>Name / impressions</span><span>Gross · {{ $summary['currency'] }}</span></div><div class="report-ranked-list">@forelse($rows as $row)<div class="report-ranked-row"><span class="report-rank">{{ $loop->iteration }}</span><div><strong>{{ $row['label'] }}</strong><span class="muted">{{ number_format($row['impressions']) }} impressions</span></div><strong class="money">{{ \App\Support\Money::formatMinor((int) $row['gross_revenue_minor']) }}</strong></div>@empty<p class="muted report-list-empty">No finalized data in this period.</p>@endforelse</div></article>
@endforeach
</section>
<aside class="report-finance-strip"><div><h3>From reports to payouts</h3><p class="muted">Close your month, review invoices and manage publisher payments.</p></div><nav class="report-finance-links" aria-label="Reporting and Finance">
    @if(auth()->user()->hasPermission('finance.operations.view'))<a class="hm-button-secondary" href="{{ route('admin.finance.overview') }}">Finance overview →</a><a class="text-link" href="{{ route('admin.finance.periods.index') }}">Monthly periods</a>@endif
    @if(auth()->user()->hasPermission('finance.publisher.view'))<a class="text-link" href="{{ route('admin.finance.statements.index') }}">Statements &amp; invoices</a>@endif
</nav></aside>
<p class="muted report-footnote">Finalized reports only. Net revenue, publisher earnings and Horus margin include approved adjustments. Breakdowns show gross revenue before adjustments. Horus GAM impressions: {{ number_format($summary['horus_gam_impressions']) }}.</p>
<details class="workspace-section report-disclosure"><summary>Source health and import details</summary>
<article><h2>Report source connections</h2><p class="muted">Horus requests GAM revenue from Google in USD regardless of the Ad Manager network currency. A disabled legacy row preserves historical accounting in its original denomination and is never mixed into the USD dashboard.</p>@forelse($connections as $connection)<div class="event"><div><strong>{{ $connection->source->name }} · {{ $connection->name }}</strong><br><span>@if($connection->connection_type === 'GAM_CONNECTION_LEGACY')Legacy accounting history · {{ $connection->currency }}@elseif(in_array($connection->connection_type, ['GAM_CONNECTION','SITE_GAM_AD_UNIT'], true))Reporting currency · {{ $connection->currency }}@if(data_get($connection->configuration, 'source_network_currency')) · Network base {{ data_get($connection->configuration, 'source_network_currency') }}@endif @else{{ $connection->connection_type }} · {{ $connection->currency }}@endif · {{ $connection->last_successful_import_at ?: 'Never imported' }}</span></div><span class="pill">{{ $connection->status->value }}</span></div>@empty<p class="muted">No report connections yet.</p>@endforelse</article>
<article><h2>Recent imports</h2>@forelse($imports as $import)<div class="event"><div><strong>{{ $import->connection->source->code->value }}</strong><br><span>{{ $import->period_start }} — {{ $import->period_end }} · {{ $import->row_count }} rows · {{ $import->duplicate_count }} duplicates</span></div><x-status-badge :status="$import->status" /></div>@empty<p class="muted">No imports yet.</p>@endforelse</article>
</details>
</div>
@endsection
