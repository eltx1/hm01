@extends('layouts.admin')
@section('title', 'Website reports')
@section('heading', 'Website reports')
@section('content')
<div class="reports-page admin-website-reports">
    <header class="report-page-heading"><div><p class="eyebrow">WEBSITE PERFORMANCE</p><h2>Website reports</h2><p class="muted">{{ number_format($sites->total()) }} websites · Finalized reports and estimates · {{ $currency }}</p></div><a class="hm-button-secondary" href="{{ route('admin.reporting.index', ['from' => $from, 'to' => $to, 'metrics' => $metrics]) }}">All reports</a></header>
    <x-report-period :from="$from" :to="$to" :metrics="$metrics" :exportable="false" :context="['q' => $search]" />
    <form method="get" class="admin-website-search" role="search" aria-label="Search website reports">
        <input type="hidden" name="from" value="{{ $from }}"><input type="hidden" name="to" value="{{ $to }}">
        @foreach($metrics as $metric)<input type="hidden" name="metrics[]" value="{{ $metric }}">@endforeach
        <label>Find a website<input class="hm-input" type="search" name="q" value="{{ $search }}" placeholder="Website, domain or publisher" maxlength="150"></label>
        <button class="hm-button-primary" type="submit">Search</button>
        @if($search !== '')<a class="text-link" href="{{ route('admin.reporting.websites.index', ['from' => $from, 'to' => $to, 'metrics' => $metrics]) }}">Clear search</a>@endif
    </form>
    <p class="muted report-footnote">Cards show main performance. Open a website report for its separate Video results.</p>
    <x-report-coverage :coverage="$coverage['main'] ?? []" :page-scoped="true" />
    <section class="admin-website-grid" aria-label="Website performance reports">
    @forelse($sites as $site)
        @php($row = $totals->get($site->id))
        <article class="admin-website-card">
            <header><div><h3>{{ $site->display_name }}</h3><p>{{ $site->primary_domain }}</p><p class="muted">{{ $site->publisher?->display_name }}</p></div><x-status-badge :status="$site->status" /></header>
            @if($row)
                <p class="report-state report-finality-state">{{ $row['has_estimates'] ? 'Includes estimates · Awaiting finalization' : 'Finalized reports' }}</p>
                <div class="admin-website-revenue"><span>Main gross revenue</span><strong>{{ \App\Support\Money::formatMinor($row['gross_revenue_minor']) }} <small>{{ $currency }}</small></strong></div>
                <dl>@foreach($metrics as $metric)<div><dt>{{ \App\Services\Reporting\PerformanceMetrics::label($metric, $row) }}</dt><dd>{{ $row[$metric] === null ? 'Unavailable' : \App\Services\Reporting\PerformanceMetrics::display($metric, $row[$metric]) }}@if($metric === 'ecpm_minor' && $row[$metric] !== null) <small>{{ $currency }}</small>@endif</dd></div>@endforeach</dl>
            @else<p class="muted">No main reports for these dates. Missing data does not mean zero earnings.</p>@endif
            <a class="hm-button-secondary" href="{{ route('admin.reporting.websites.show', ['site' => $site, 'from' => $from, 'to' => $to, 'metrics' => $metrics]) }}" aria-label="View report for {{ $site->display_name }}">View report →</a>
        </article>
    @empty<x-empty-state title="No websites found" description="Try another website, domain or publisher name." />@endforelse
    </section>
    {{ $sites->links() }}
</div>
@endsection
