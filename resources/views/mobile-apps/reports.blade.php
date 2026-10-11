@extends('layouts.admin')
@section('title', $app->name.' reports')
@section('heading', 'Mobile app reports')
@section('content')
<section class="hero"><div><p class="eyebrow">{{ $app->name }} · {{ $app->public_id }}</p><h2>{{ $publisherView ? 'Your app earnings' : 'App performance' }}</h2><p>{{ $publisherView ? 'Revenue and eCPM reflect your earnings after the platform share.' : 'Gross revenue before publisher and platform shares.' }}</p></div><a class="hm-button-secondary button-link" href="{{ route($prefix.'.show', $app) }}">App details</a></section>
<article class="workspace-section"><form class="button-row" method="get"><label>From<input class="hm-input" type="date" name="from" value="{{ $from }}"></label><label>To<input class="hm-input" type="date" name="to" value="{{ $to }}"></label><button class="hm-button-primary">Apply dates</button><button class="hm-button-secondary" name="export" value="1">Export CSV</button></form></article>
<div class="publisher-report-overview"><article class="publisher-earnings"><div class="publisher-earnings-topline"><h3>{{ $publisherView ? 'Your earnings' : 'Gross revenue' }}</h3><span class="report-state">{{ $hasEstimates ? 'Includes estimates' : 'Finalized reports' }}</span></div><p class="publisher-earnings-value"><span>{{ $currency }}</span> {{ \App\Support\Money::formatMinor($totals['earnings_minor']) }}</p><p class="muted">{{ number_format($totals['ad_requests']) }} ad requests</p></article><x-publisher-report-metrics :totals="$totals" :currency="$currency" /></div>
@if($hasEstimates)<p class="muted">Includes estimates awaiting Google finalization. Payouts use finalized eligible earnings.</p>@endif
<article class="report-chart-card"><div class="report-card-heading"><h3>Earnings over time</h3><span class="report-state">{{ $currency }}</span></div>
<x-report-chart :rows="$days" date-key="label" value-key="earnings_minor" :currency="$currency" :from="$from" :to="$to" :label="$publisherView ? 'Your daily earnings' : 'Daily gross revenue'" :responsive="true" />
</article>
<x-report-performance-table :rows="$days" :metrics="\App\Services\Reporting\PerformanceMetrics::defaultColumns($totals)" :currency="$currency" :revenue-label="$publisherView ? 'Your earnings' : 'Gross revenue'" :mobile-cards="true" />
@endsection
