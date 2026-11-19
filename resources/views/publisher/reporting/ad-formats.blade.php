@extends('layouts.admin')
@section('title', 'Breakdown by ad format')
@section('heading', 'Reports & earnings')
@section('content')
@include('publisher.finance._tabs')
@php($context = $selectedSite ? ['site' => $selectedSite] : [])
<section class="reports-page publisher-reports" aria-labelledby="ad-format-heading">
    <nav class="report-finance-links" aria-label="Report navigation"><a class="text-link" href="{{ route('publisher.reporting.index', ['from' => $breakdown['from'], 'to' => $breakdown['to']]) }}">← Back to reports</a></nav>
    <header class="publisher-report-heading">
        <h2 id="ad-format-heading">Breakdown by ad format</h2>
        <p>Your earnings for the selected period, split by ad format: Banner, Interstitial, Anchor, Rewarded and more.</p>
    </header>
    <x-publisher-report-period :from="$breakdown['from']" :to="$breakdown['to']" :metrics="$reportMetrics" :context="$context" />
    @if($sites->count() > 1)
    <form method="get" class="admin-website-search ad-format-site-filter" aria-label="Choose a website">
        <input type="hidden" name="from" value="{{ $breakdown['from'] }}"><input type="hidden" name="to" value="{{ $breakdown['to'] }}">
        @foreach($reportMetrics as $metric)<input type="hidden" name="metrics[]" value="{{ $metric }}">@endforeach
        <label>Website<select class="hm-input" name="site" onchange="this.form.submit()"><option value="">All websites</option>@foreach($sites as $site)<option value="{{ $site->id }}" @selected($selectedSite === $site->id)>{{ $site->display_name }}</option>@endforeach</select></label>
        <noscript><button class="hm-button-secondary" type="submit">Show</button></noscript>
    </form>
    @endif
    <x-publisher-report-columns :metrics="$reportMetrics" />
    <x-report-ad-format-breakdown :breakdown="$breakdown" :metrics="$reportMetrics" :publisher="true" :previous="$previousBreakdown ?? null" :period="$previousPeriod ?? null" />
</section>
@endsection
