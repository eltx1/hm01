@extends('layouts.admin')
@section('title', 'Reporting sources')
@section('heading', 'Unified reporting sources')
@section('content')
<section class="hero"><div><p class="eyebrow">Horus Media source of truth</p><h2>Aggregated delivery and source imports</h2><p>Horus GAM remains clearly identified while optional GAM, Prebid and native sources are normalized into the authoritative reporting ledger. Monthly liability and payouts are operated in Finance Operations.</p></div>@if(auth()->user()->hasPermission('finance.operations.view'))<a class="hm-button-primary button-link" href="{{ route('admin.finance.overview') }}">Open Finance Operations</a>@endif</section>
<form method="get" class="form-grid"><label>From<input type="date" name="from" value="{{ $summary['from']->toDateString() }}"></label><label>To<input type="date" name="to" value="{{ $summary['to']->toDateString() }}"></label><label>Currency<input name="currency" value="{{ $summary['currency'] }}" maxlength="3"></label><button class="hm-button-primary" type="submit">Apply</button></form>
<section class="metric-grid">
@foreach ([
['Managed impressions',number_format($summary['managed_impressions'])],
['Horus GAM impressions',number_format($summary['horus_gam_impressions'])],
['Gross revenue',\App\Support\Money::formatMinor((int) $summary['gross_revenue_minor']).' '.$summary['currency']],
['Net revenue',\App\Support\Money::formatMinor((int) $summary['net_revenue_minor']).' '.$summary['currency']],
['Horus margin',\App\Support\Money::formatMinor((int) $summary['horus_margin_minor']).' '.$summary['currency']],
['Publisher earnings',\App\Support\Money::formatMinor((int) $summary['publisher_earnings_minor']).' '.$summary['currency']]
] as [$label,$value])<article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong></article>@endforeach
</section>
<section class="split-grid">
@foreach ([['Revenue by publisher',$summary['revenue_by_publisher']],['Revenue by website',$summary['revenue_by_website']],['Revenue by demand source',$summary['revenue_by_source']],['Revenue by campaign',$summary['revenue_by_campaign']]] as [$heading,$rows])
<article><h2>{{ $heading }}</h2>@forelse($rows as $row)<div class="event"><div><strong>{{ $row['label'] }}</strong><br><span>{{ number_format($row['impressions']) }} impressions</span></div><span>{{ \App\Support\Money::formatMinor((int) $row['gross_revenue_minor']) }}</span></div>@empty<p class="muted">No finalized data in this period.</p>@endforelse</article>
@endforeach
</section>
<article><h2>Report source connections</h2><p class="muted">Horus GAM is the primary source. Other sources remain separately traceable.</p>@forelse($connections as $connection)<div class="event"><div><strong>{{ $connection->source->name }} · {{ $connection->name }}</strong><br><span>{{ $connection->connection_type }} · {{ $connection->currency }} · {{ $connection->last_successful_import_at ?: 'Never imported' }}</span></div><span class="pill">{{ $connection->status->value }}</span></div>@empty<p class="muted">No report connections yet.</p>@endforelse</article>
<article><h2>Recent imports</h2>@forelse($imports as $import)<div class="event"><div><strong>{{ $import->connection->source->code->value }}</strong><br><span>{{ $import->period_start }} — {{ $import->period_end }} · {{ $import->row_count }} rows · {{ $import->duplicate_count }} duplicates</span></div><x-status-badge :status="$import->status" /></div>@empty<p class="muted">No imports yet.</p>@endforelse</article>
@endsection
