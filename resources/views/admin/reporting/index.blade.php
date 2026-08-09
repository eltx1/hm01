@extends('layouts.admin')
@section('title', 'Reporting and finance')
@section('heading', 'Unified reporting and finance')
@section('content')
<section class="hero"><div><p class="eyebrow">Horus Media source of truth</p><h2>Aggregated delivery, revenue, reconciliation and payments</h2><p>Horus GAM remains clearly identified while optional GAM, Prebid and native sources are normalized into the same financial ledger.</p></div></section>
<form method="get" class="form-grid"><label>From<input type="date" name="from" value="{{ $summary['from']->toDateString() }}"></label><label>To<input type="date" name="to" value="{{ $summary['to']->toDateString() }}"></label><label>Currency<input name="currency" value="{{ request('currency','USD') }}" maxlength="3"></label><button class="hm-button-primary" type="submit">Apply</button></form>
<section class="metric-grid">
@foreach ([
['Managed impressions',number_format($summary['managed_impressions'])],
['Horus GAM impressions',number_format($summary['horus_gam_impressions'])],
['Gross revenue',number_format($summary['gross_revenue_minor']/100,2).' '.request('currency','USD')],
['Net revenue',number_format($summary['net_revenue_minor']/100,2).' '.request('currency','USD')],
['Horus margin',number_format($summary['horus_margin_minor']/100,2).' '.request('currency','USD')],
['Publisher payable',number_format($summary['outstanding_publisher_payments_minor']/100,2).' '.request('currency','USD')],
['Advertiser balances',number_format($summary['advertiser_balances_minor']/100,2).' '.request('currency','USD')]
] as [$label,$value])<article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong></article>@endforeach
</section>
<section class="split-grid">
@foreach ([['Revenue by publisher',$summary['revenue_by_publisher']],['Revenue by website',$summary['revenue_by_website']],['Revenue by demand source',$summary['revenue_by_source']],['Revenue by campaign',$summary['revenue_by_campaign']]] as [$heading,$rows])
<article><h2>{{ $heading }}</h2>@forelse($rows as $row)<div class="event"><div><strong>{{ $row['label'] }}</strong><br><span>{{ number_format($row['impressions']) }} impressions</span></div><span>{{ number_format($row['gross_revenue_minor']/100,2) }}</span></div>@empty<p class="muted">No finalized data in this period.</p>@endforelse</article>
@endforeach
</section>
<article><h2>Report source connections</h2><p class="muted">Horus GAM is the primary source. Other sources remain separately traceable.</p>@forelse($connections as $connection)<div class="event"><div><strong>{{ $connection->source->name }} · {{ $connection->name }}</strong><br><span>{{ $connection->connection_type }} · {{ $connection->currency }} · {{ $connection->last_successful_import_at ?: 'Never imported' }}</span></div><span class="pill">{{ $connection->status->value }}</span></div>@empty<p class="muted">No report connections yet.</p>@endforelse</article>
<section class="split-grid"><article><h2>Recent imports</h2>@forelse($imports as $import)<div class="event"><div><strong>{{ $import->connection->source->code->value }}</strong><br><span>{{ $import->period_start }} — {{ $import->period_end }} · {{ $import->row_count }} rows · {{ $import->duplicate_count }} duplicates</span></div><span class="pill">{{ $import->status->value }}</span></div>@empty<p class="muted">No imports yet.</p>@endforelse</article><article><h2>Financial periods</h2>@forelse($periods as $period)<div class="event"><div><strong>{{ $period->period_key }} · {{ $period->currency }}</strong><br><span>{{ $period->starts_on->toDateString() }} — {{ $period->ends_on->toDateString() }}</span></div><span class="pill">{{ $period->status->value }}</span></div>@empty<p class="muted">Periods are opened automatically on first import.</p>@endforelse</article></section>
<article><h2>Publisher statements and payments</h2>@forelse($statements as $statement)<div class="event"><div><strong><a href="{{ route('admin.reporting.statements.show',$statement) }}">{{ $statement->statement_number }}</a></strong><br><span>{{ $statement->publisher->display_name }} · {{ $statement->period->period_key }} · {{ number_format($statement->balance_due_minor/100,2) }} {{ $statement->currency }}</span></div><span class="pill">{{ $statement->status->value }}</span></div>@empty<p class="muted">Statements are generated when a period closes.</p>@endforelse</article>
@endsection
