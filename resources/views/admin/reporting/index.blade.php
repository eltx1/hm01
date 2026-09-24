@extends('layouts.admin')
@section('title', 'Reports')
@section('heading', 'Reports')
@section('content')
<section class="hero"><div><p class="eyebrow">Performance overview · {{ $summary['currency'] }}</p><h2>Revenue, publishers and performance</h2><p>Review finalized delivery for your selected dates. Monthly statements, invoice review and payouts remain in Finance.</p></div></section>
<x-report-period :from="$summary['from']" :to="$summary['to']" />
<nav class="report-finance-links" aria-label="Reporting and Finance">
    @if(auth()->user()->hasPermission('finance.operations.view'))<a class="hm-button-secondary" href="{{ route('admin.finance.overview') }}">Finance overview →</a><a class="hm-button-secondary" href="{{ route('admin.finance.periods.index') }}">Close a monthly period →</a>@endif
    @if(auth()->user()->hasPermission('finance.publisher.view'))<a class="hm-button-secondary" href="{{ route('admin.finance.statements.index') }}">Statements &amp; invoices →</a>@endif
</nav>
<p class="muted report-context">Finalized reports only · {{ $summary['currency'] }}. Net revenue, publisher earnings and Horus margin include approved adjustments. The breakdowns below show gross revenue before adjustments.</p>
@if(!$summary['available'])<x-empty-state title="No finalized reports for these dates" description="Try another period or check source imports below. Missing reports do not mean zero revenue." />@endif
<section class="report-metrics">
@foreach ([
['Managed impressions',number_format($summary['managed_impressions'])],
['Horus GAM impressions',number_format($summary['horus_gam_impressions'])],
['Gross revenue',\App\Support\Money::formatMinor((int) $summary['gross_revenue_minor']).' '.$summary['currency']],
['Net revenue',\App\Support\Money::formatMinor((int) $summary['net_revenue_minor']).' '.$summary['currency']],
['Horus margin',\App\Support\Money::formatMinor((int) $summary['horus_margin_minor']).' '.$summary['currency']],
['Publisher earnings',\App\Support\Money::formatMinor((int) $summary['publisher_earnings_minor']).' '.$summary['currency']]
] as [$label,$value])<article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong></article>@endforeach
</section>
@if($summary['available'])
<article class="workspace-section"><h2>Daily revenue</h2><p class="muted">Gross revenue before adjustments · {{ $summary['currency'] }}</p><div class="report-trend">
@php($peak = max(1, (int) $summary['daily_revenue']->max('gross_revenue_minor')))
@foreach($summary['daily_revenue'] as $day)<div class="report-trend-row"><span>{{ $day['date'] }}</span><meter min="0" max="{{ $peak }}" value="{{ max(0, $day['gross_revenue_minor']) }}" aria-label="{{ $day['date'] }} gross revenue">{{ $day['gross_revenue_minor'] }}</meter><strong class="money">{{ \App\Support\Money::formatMinor($day['gross_revenue_minor']) }}</strong></div>@endforeach
</div></article>
@endif
<section class="split-grid">
@foreach ([['Revenue by publisher',$summary['revenue_by_publisher']],['Revenue by website',$summary['revenue_by_website']],['Revenue by demand source',$summary['revenue_by_source']],['Revenue by campaign',$summary['revenue_by_campaign']]] as [$heading,$rows])
<article><h2>{{ $heading }}</h2>@forelse($rows as $row)<div class="event"><div><strong>{{ $row['label'] }}</strong><br><span>{{ number_format($row['impressions']) }} impressions</span></div><span>{{ \App\Support\Money::formatMinor((int) $row['gross_revenue_minor']) }} {{ $summary['currency'] }}</span></div>@empty<p class="muted">No finalized data in this period.</p>@endforelse</article>
@endforeach
</section>
<details class="workspace-section report-disclosure"><summary>Source health and import details</summary>
<article><h2>Report source connections</h2><p class="muted">Horus requests GAM revenue from Google in USD regardless of the Ad Manager network currency. A disabled legacy row preserves historical accounting in its original denomination and is never mixed into the USD dashboard.</p>@forelse($connections as $connection)<div class="event"><div><strong>{{ $connection->source->name }} · {{ $connection->name }}</strong><br><span>@if($connection->connection_type === 'GAM_CONNECTION_LEGACY')Legacy accounting history · {{ $connection->currency }}@elseif(in_array($connection->connection_type, ['GAM_CONNECTION','SITE_GAM_AD_UNIT'], true))Reporting currency · {{ $connection->currency }}@if(data_get($connection->configuration, 'source_network_currency')) · Network base {{ data_get($connection->configuration, 'source_network_currency') }}@endif @else{{ $connection->connection_type }} · {{ $connection->currency }}@endif · {{ $connection->last_successful_import_at ?: 'Never imported' }}</span></div><span class="pill">{{ $connection->status->value }}</span></div>@empty<p class="muted">No report connections yet.</p>@endforelse</article>
<article><h2>Recent imports</h2>@forelse($imports as $import)<div class="event"><div><strong>{{ $import->connection->source->code->value }}</strong><br><span>{{ $import->period_start }} — {{ $import->period_end }} · {{ $import->row_count }} rows · {{ $import->duplicate_count }} duplicates</span></div><x-status-badge :status="$import->status" /></div>@empty<p class="muted">No imports yet.</p>@endforelse</article>
</details>
@endsection
