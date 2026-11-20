@extends('layouts.admin')
@section('title', ($publisher?->display_name ?? 'All publishers').' · Ad formats')
@section('heading', 'Breakdown by ad format')
@section('content')
@php($context = array_filter(['site' => $selectedSite, 'by' => request()->query('by')]))
@php($site = $selectedSite ? $sites->firstWhere('id', $selectedSite) : null)
<div class="reports-page admin-website-reports">
    <nav class="report-finance-links" aria-label="Report navigation">
        @if($site)<a class="text-link" href="{{ route('admin.reporting.websites.show', ['site' => $site, 'from' => $breakdown['from'], 'to' => $breakdown['to']]) }}">← Website report</a>@endif
        @if($publisher)<a class="text-link" href="{{ route('admin.reporting.ad-formats', ['from' => $breakdown['from'], 'to' => $breakdown['to']]) }}">← All publishers</a>
        <a class="text-link" href="{{ route('admin.reporting.websites.index', ['from' => $breakdown['from'], 'to' => $breakdown['to'], 'q' => $publisher->display_name]) }}">Publisher websites</a>@else<a class="text-link" href="{{ route('admin.reporting.index', ['from' => $breakdown['from'], 'to' => $breakdown['to']]) }}">← Reports</a>@endif
    </nav>
    <header class="report-page-heading"><div><p class="eyebrow">BREAKDOWN BY AD FORMAT</p><h2>{{ $publisher?->display_name ?? 'All publishers' }}</h2><p class="muted">{{ $site ? $site->display_name.' · '.$site->primary_domain : 'All websites ('.$sites->count().')' }}</p></div><span class="report-state report-finality-state"><x-report-data-state :available="$breakdown['available']" :has-estimates="$breakdown['has_estimates']" /> · {{ $breakdown['currency'] }}</span></header>
    <x-report-period :from="$breakdown['from']" :to="$breakdown['to']" :metrics="$reportMetrics" :context="$context" />
    @if(!$publisher && ($publishers ?? collect())->isNotEmpty())
    <form method="get" class="admin-website-search ad-format-site-filter" aria-label="Choose a publisher">
        <input type="hidden" name="from" value="{{ $breakdown['from'] }}"><input type="hidden" name="to" value="{{ $breakdown['to'] }}">@if(request()->query('by'))<input type="hidden" name="by" value="{{ request()->query('by') }}">@endif
        <label>Publisher<select class="hm-input" name="publisher" onchange="this.form.submit()"><option value="">All publishers</option>@foreach($publishers as $option)<option value="{{ $option->id }}">{{ $option->display_name }}</option>@endforeach</select></label>
        <noscript><button class="hm-button-secondary" type="submit">Show</button></noscript>
    </form>
    @endif
    @if($publisher && $sites->count() > 1)
    <form method="get" class="admin-website-search ad-format-site-filter" aria-label="Choose a website">
        <input type="hidden" name="from" value="{{ $breakdown['from'] }}"><input type="hidden" name="to" value="{{ $breakdown['to'] }}">@if(request()->query('by'))<input type="hidden" name="by" value="{{ request()->query('by') }}">@endif
        @foreach($reportMetrics as $metric)<input type="hidden" name="metrics[]" value="{{ $metric }}">@endforeach
        <label>Website<select class="hm-input" name="site" onchange="this.form.submit()"><option value="">All websites</option>@foreach($sites as $option)<option value="{{ $option->id }}" @selected($selectedSite === $option->id)>{{ $option->display_name }} · {{ $option->primary_domain }}</option>@endforeach</select></label>
        <noscript><button class="hm-button-secondary" type="submit">Show</button></noscript>
    </form>
    @endif
    @if(($drops ?? collect())->isNotEmpty() && ($breakdown['dimension'] ?? 'FORMAT') === 'FORMAT')
    <section class="ad-format-drops" aria-labelledby="ad-format-drops-heading" role="status">
        <h3 id="ad-format-drops-heading"><span aria-hidden="true">⚠</span> Drops detected yesterday ({{ $drops->count() }})</h3>
        <ul>
            @foreach($drops->take(20) as $drop)
                <li data-ad-format-drop="{{ $drop['format'] }}"><strong>{{ $drop['format_label'] }} {{ $drop['metric'] === 'ecpm' ? 'eCPM' : 'revenue' }} {{ number_format($drop['change_bp'] / 100, 0) }}%</strong>
                    · {{ $drop['site'] }} <span class="muted">({{ $drop['domain'] }})</span> · {{ \App\Support\Money::formatMinor($drop['current']) }} vs {{ \App\Support\Money::formatMinor($drop['baseline']) }} USD average
                    @if($drop['publisher_id'])<a class="text-link" href="{{ route('admin.reporting.publishers.ad-formats', ['publisher' => $drop['publisher_id'], 'site' => $drop['site_id'], 'from' => \Carbon\CarbonImmutable::parse($drop['day'])->subDays(13)->toDateString(), 'to' => $drop['day']]) }}">Open</a>@endif</li>
            @endforeach
        </ul>
        <p class="report-footnote muted">Compared with each website-format's own average over the previous {{ \App\Services\Reporting\AdFormatDropDetector::BASELINE_DAYS }} days (Google Ad Exchange revenue). Reporting staff are also notified once per drop.</p>
    </section>
    @endif
    @if($breakdown['available'])
    <section class="report-metrics" aria-label="Ad format revenue totals">
        @foreach([['Gross revenue', $breakdown['totals']['gross_revenue_minor']], ['Publisher earnings', $breakdown['totals']['publisher_earnings_minor']], ['Horus margin', $breakdown['totals']['horus_earnings_minor']]] as [$label, $value])
            <article class="report-kpi {{ $loop->first ? 'report-kpi-primary' : '' }}"><h3 class="eyebrow">{{ $label }}</h3><div class="report-kpi-value">{{ \App\Support\Money::formatMinor((int) $value) }} <span>{{ $breakdown['currency'] }}</span></div></article>
        @endforeach
        <article class="report-kpi"><h3 class="eyebrow">Impressions</h3><div class="report-kpi-value">{{ \App\Services\Reporting\PerformanceMetrics::display('impressions', $breakdown['totals']['impressions']) }}</div></article>
    </section>
    @endif
    <x-report-breakdown-selector :current="$breakdown['dimension']" :context="['from' => $breakdown['from'], 'to' => $breakdown['to']] + $context" />
    <x-report-ad-format-breakdown :breakdown="$breakdown" :metrics="$reportMetrics" :publisher="false" :previous="$previousBreakdown ?? null" :period="$previousPeriod ?? null" />
    <x-report-ad-format-ranking :ranking="($breakdown['dimension'] ?? 'FORMAT') === 'FORMAT' ? ($ranking ?? collect()) : collect()" :currency="$breakdown['currency']" />
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
