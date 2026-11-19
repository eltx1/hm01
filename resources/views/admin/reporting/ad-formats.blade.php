@extends('layouts.admin')
@section('title', $publisher->display_name.' · Ad formats')
@section('heading', 'Breakdown by ad format')
@section('content')
@php($context = $selectedSite ? ['site' => $selectedSite] : [])
@php($site = $selectedSite ? $sites->firstWhere('id', $selectedSite) : null)
<div class="reports-page admin-website-reports">
    <nav class="report-finance-links" aria-label="Report navigation">
        @if($site)<a class="text-link" href="{{ route('admin.reporting.websites.show', ['site' => $site, 'from' => $breakdown['from'], 'to' => $breakdown['to']]) }}">← Website report</a>@endif
        <a class="text-link" href="{{ route('admin.reporting.websites.index', ['from' => $breakdown['from'], 'to' => $breakdown['to'], 'q' => $publisher->display_name]) }}">Publisher websites</a>
    </nav>
    <header class="report-page-heading"><div><p class="eyebrow">BREAKDOWN BY AD FORMAT</p><h2>{{ $publisher->display_name }}</h2><p class="muted">{{ $site ? $site->display_name.' · '.$site->primary_domain : 'All websites ('.$sites->count().')' }}</p></div><span class="report-state report-finality-state"><x-report-data-state :available="$breakdown['available']" :has-estimates="$breakdown['has_estimates']" /> · {{ $breakdown['currency'] }}</span></header>
    <x-report-period :from="$breakdown['from']" :to="$breakdown['to']" :metrics="$reportMetrics" :context="$context" />
    @if($sites->count() > 1)
    <form method="get" class="admin-website-search ad-format-site-filter" aria-label="Choose a website">
        <input type="hidden" name="from" value="{{ $breakdown['from'] }}"><input type="hidden" name="to" value="{{ $breakdown['to'] }}">
        @foreach($reportMetrics as $metric)<input type="hidden" name="metrics[]" value="{{ $metric }}">@endforeach
        <label>Website<select class="hm-input" name="site" onchange="this.form.submit()"><option value="">All websites</option>@foreach($sites as $option)<option value="{{ $option->id }}" @selected($selectedSite === $option->id)>{{ $option->display_name }} · {{ $option->primary_domain }}</option>@endforeach</select></label>
        <noscript><button class="hm-button-secondary" type="submit">Show</button></noscript>
    </form>
    @endif
    @if($breakdown['available'])
    <section class="report-metrics" aria-label="Ad format revenue totals">
        @foreach([['Gross revenue', $breakdown['totals']['gross_revenue_minor']], ['Publisher earnings', $breakdown['totals']['publisher_earnings_minor']], ['Horus margin', $breakdown['totals']['horus_earnings_minor']]] as [$label, $value])
            <article class="report-kpi {{ $loop->first ? 'report-kpi-primary' : '' }}"><h3 class="eyebrow">{{ $label }}</h3><div class="report-kpi-value">{{ \App\Support\Money::formatMinor((int) $value) }} <span>{{ $breakdown['currency'] }}</span></div></article>
        @endforeach
        <article class="report-kpi"><h3 class="eyebrow">Impressions</h3><div class="report-kpi-value">{{ \App\Services\Reporting\PerformanceMetrics::display('impressions', $breakdown['totals']['impressions']) }}</div></article>
    </section>
    @endif
    <x-report-ad-format-breakdown :breakdown="$breakdown" :metrics="$reportMetrics" :publisher="false" />
</div>
@endsection
