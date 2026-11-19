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
    @if($probe = session('adFormatProbe'))
    @php($good = $probe['verdict'] === 'SUPPORTED')
    <section class="ad-format-probe {{ $good ? 'ad-format-probe--ok' : 'ad-format-probe--warn' }}" aria-labelledby="ad-format-probe-heading" data-ad-format-probe="{{ $probe['verdict'] }}">
        <h3 id="ad-format-probe-heading">Live Google check: {{ match($probe['verdict']) { 'SUPPORTED' => 'Supported — Ad Exchange by ad format works', 'PARTIALLY_SUPPORTED' => 'Partially supported', 'UNSUPPORTED' => 'Not supported by this network', 'INCONCLUSIVE' => 'Inconclusive — try again', default => str_replace('_', ' ', $probe['verdict']) } }}</h3>
        <p class="muted">{{ $probe['message'] ?? '' }} @isset($probe['date'])Day tested: {{ $probe['date'] }}.@endisset Nothing was stored.</p>
        @if($good)
            <dl class="ad-format-probe-totals">
                <div><dt>Google AdX revenue (sum of formats)</dt><dd>{{ \App\Support\Money::formatMinor($probe['google_format_revenue_minor']) }} {{ $breakdown['currency'] }}</dd></div>
                <div><dt>Stored report gross revenue</dt><dd>{{ $probe['ledger_gross_revenue_minor'] === null ? 'No stored report for this day' : \App\Support\Money::formatMinor($probe['ledger_gross_revenue_minor']).' '.$breakdown['currency'] }}</dd></div>
                <div><dt>Difference</dt><dd>{{ $probe['difference_minor'] === null ? '—' : \App\Support\Money::formatMinor($probe['difference_minor']).' '.$breakdown['currency'] }}</dd></div>
            </dl>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Formats returned by Google">
                <table><caption class="sr-only">Formats returned by Google</caption>
                    <thead><tr><th scope="col">Google inventory format</th><th scope="col">AdX requests</th><th scope="col">AdX impressions</th><th scope="col">AdX clicks</th><th scope="col">AdX revenue ({{ $breakdown['currency'] }})</th></tr></thead>
                    <tbody>@forelse($probe['formats'] as $format)<tr><th scope="row">{{ $format['format'] }}@if($format['google_name'] && $format['google_name'] !== $format['format'])<span class="muted"> · {{ $format['google_name'] }}</span>@endif</th><td>{{ $format['ad_requests'] === null ? '—' : number_format($format['ad_requests']) }}</td><td>{{ number_format($format['impressions']) }}</td><td>{{ number_format($format['clicks']) }}</td><td class="money">{{ \App\Support\Money::formatMinor($format['revenue_minor']) }}</td></tr>@empty<tr><td colspan="5">Google accepted the query; this website had no Ad Exchange delivery on that day.</td></tr>@endforelse</tbody>
                </table>
            </div>
        @endif
        @if(!empty($probe['attempts']))
            <details class="report-disclosure"><summary>Technical details</summary><ul>
                @foreach($probe['attempts'] as $name => $attempt)<li><strong>{{ str_replace('_', ' ', $name) }}</strong>: {{ $attempt['status'] }}@if(!empty($attempt['error'])) — {{ $attempt['error'] }}@endif <span class="muted">({{ implode(', ', $attempt['dimensions'] ?? []) }} · {{ implode(', ', $attempt['columns'] ?? []) }})</span></li>@endforeach
            </ul></details>
        @endif
    </section>
    @endif
    <x-report-ad-format-breakdown :breakdown="$breakdown" :metrics="$reportMetrics" :publisher="false" />
    @if($selectedSite && auth()->user()->hasPermission('reporting.sources.manage'))
    <form method="post" action="{{ route('admin.reporting.publishers.ad-formats.verify', $publisher) }}" class="ad-format-probe-form">
        @csrf
        <input type="hidden" name="site" value="{{ $selectedSite }}">
        <label>Day to test<input class="hm-input" type="date" name="date" value="{{ $breakdown['to'] < now()->toDateString() ? $breakdown['to'] : now()->subDay()->toDateString() }}" max="{{ now()->subDay()->toDateString() }}"></label>
        <button class="hm-button-secondary" type="submit">Verify with Google now</button>
        <p class="report-footnote muted">Runs the exact Ad Exchange by ad format query for this website and day, read-only. Nothing is stored and financial reports are unchanged.</p>
    </form>
    @endif
    @if($syncStatuses->isNotEmpty())
    <details class="report-disclosure ad-format-sync-status" @if($syncStatuses->contains(fn ($status) => !in_array($status['state'], ['CURRENT', 'NOT_CONNECTED'], true))) open @endif>
        <summary>Ad format data status</summary>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Ad format data status">
            <table>
                <caption class="sr-only">Ad format synchronization by website</caption>
                <thead><tr><th scope="col">Website</th><th scope="col">Status</th><th scope="col">Formats available through</th><th scope="col">Last synchronized</th></tr></thead>
                <tbody>
                @foreach($syncStatuses as $siteId => $status)
                    @php($statusSite = $sites->firstWhere('id', $siteId))
                    <tr data-ad-format-sync="{{ $status['state'] }}"><th scope="row">{{ $statusSite?->display_name }}<span class="muted"> · {{ $statusSite?->primary_domain }}</span></th>
                        <td>{{ $status['label'] }}@if($status['retry_at']) <span class="muted">· next attempt {{ \Carbon\CarbonImmutable::parse($status['retry_at'])->setTimezone(config('app.timezone'))->format('j M, H:i') }}</span>@endif</td>
                        <td>{{ $status['synced_through'] ? \Carbon\CarbonImmutable::parse($status['synced_through'])->format('j M Y') : '—' }}</td>
                        <td>{{ $status['synced_at'] ? \Carbon\CarbonImmutable::parse($status['synced_at'])->setTimezone(config('app.timezone'))->format('j M Y, H:i') : '—' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="report-footnote muted">Synchronized automatically with website reports. Financial reports are never affected by this status.</p>
    </details>
    @endif
</div>
@endsection
