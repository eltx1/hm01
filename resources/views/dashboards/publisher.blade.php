@extends('layouts.admin')
@section('title', 'Home')
@section('heading', 'Publisher home')
@section('content')
@php
    $activeContract = $publisher->contracts->first(fn ($contract) => $contract->status === \App\Enums\ContractStatus::Active);
    $activeSites = $publisher->sites->where('status', \App\Enums\SiteStatus::Active)->count();
    $pendingSites = $publisher->sites->whereIn('status', [\App\Enums\SiteStatus::PendingVerification, \App\Enums\SiteStatus::PendingReview])->count();
    $currency = $reporting['currency'] ?? 'USD';
@endphp

<section class="hero publisher-home-hero">
    <div>
        <p class="eyebrow">{{ $publisher->display_name }}</p>
        <h2>Your publishing business at a glance.</h2>
        <p>See what needs attention, check today's estimated earnings, manage your websites, and follow payouts without hunting through the dashboard.</p>
        <div class="button-row">
            @if(auth()->user()->hasPermission('finance.publisher.view_own'))<a class="hm-button-primary button-link" href="{{ route('publisher.finance.overview') }}">View earnings</a>@endif
            @if(auth()->user()->hasPermission('sites.view'))<a class="hm-button-secondary button-link" href="{{ route('publisher.sites.index') }}">Manage websites</a>@endif
            @if(auth()->user()->hasPermission('sites.manage'))<a class="section-anchor" href="{{ route('publisher.sites.create') }}">+ Add website</a>@endif
        </div>
    </div>
    <div class="hero-stat"><span>Active websites</span><strong>{{ $activeSites }}</strong><small>{{ $pendingSites }} pending</small></div>
</section>

<section class="action-center" aria-labelledby="publisher-action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Start here</p><h2 id="publisher-action-center-heading">What needs your attention</h2></div>
        <x-status-badge :status="$actionItems === [] ? 'HEALTHY' : 'PENDING'" />
    </div>
    @if($actionItems === [])
        <article class="next-step-clear"><h3>You're all caught up</h3><p class="muted">There are no website, compliance, or account actions waiting for you.</p></article>
    @else
        <div class="action-center-grid">
            @foreach($actionItems as $item)
                <a href="{{ route($item['route'], $item['parameters']) }}" class="action-card action-card-{{ $item['severity'] }}">
                    <span class="action-count">{{ $item['count'] }}</span>
                    <div><h3>{{ $item['label'] }}</h3><p>{{ $item['description'] }}</p><span class="section-anchor">Fix this →</span></div>
                </a>
            @endforeach
        </div>
    @endif
</section>

<section class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Performance · USD</p><h2>Today and this month</h2></div>
        @if(auth()->user()->hasPermission('finance.publisher.view_own'))<a class="section-anchor" href="{{ route('publisher.finance.overview') }}">Full earnings report →</a>@endif
    </div>
    <div class="metric-grid publisher-kpis">
        <article>
            <p class="eyebrow">Today so far</p>
            <strong class="metric">{{ $reporting['today_available'] ? $currency.' '.\App\Support\Money::formatMinor((int) $reporting['today_estimated_earnings_minor']) : 'Waiting' }}</strong>
            <span class="muted">Estimated Publisher earnings</span>
        </article>
        <article>
            <p class="eyebrow">Today's impressions</p>
            <strong class="metric">{{ $reporting['today_available'] ? number_format((int) $reporting['today_impressions']) : '—' }}</strong>
            <span class="muted">{{ $reporting['today_available'] ? number_format((int) $reporting['today_clicks']).' clicks' : 'Waiting for today\'s report' }}</span>
        </article>
        <article>
            <p class="eyebrow">Finalized this month</p>
            <strong class="metric">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $reporting['finalized_earnings_minor']) }}</strong>
            <span class="muted">{{ number_format((int) $reporting['impressions']) }} finalized impressions</span>
        </article>
        <article>
            <p class="eyebrow">Payment balance</p>
            <strong class="metric">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $reporting['payment_balance_minor']) }}</strong>
            <span class="muted">Latest finalized USD statement</span>
        </article>
    </div>
    <p class="muted dashboard-explainer">Reporting revenue is standardized to USD. Today is estimated and can change before Google finalizes the reporting day.</p>
</section>

<section class="split-grid workspace-section">
    <article>
        <div class="workspace-heading">
            <div><p class="eyebrow">Your inventory</p><h2>Websites</h2></div>
            @if(auth()->user()->hasPermission('sites.view'))<a class="section-anchor" href="{{ route('publisher.sites.index') }}">View all →</a>@endif
        </div>
        <div class="compact-list">
            @forelse($publisher->sites->take(6) as $site)
                <a class="compact-row" href="{{ route('publisher.sites.show', $site) }}">
                    <div><strong>{{ $site->display_name }}</strong><p>{{ $site->primary_domain }}</p></div>
                    <div class="row-action"><x-status-badge :status="$site->status" /><span class="section-anchor">Open →</span></div>
                </a>
            @empty
                <x-empty-state title="No websites yet" description="Add your first website to start verification and monetization.">
                    @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
                </x-empty-state>
            @endforelse
        </div>
    </article>

    <article>
        <p class="eyebrow">Common tasks</p><h2>Go straight to what you need</h2>
        <div class="quick-link-grid">
            @if(auth()->user()->hasPermission('finance.publisher.view_own'))<a href="{{ route('publisher.finance.overview') }}"><strong>Earnings</strong><span>Today, monthly earnings and payouts</span></a>@endif
            @if(auth()->user()->hasPermission('sites.view'))<a href="{{ route('publisher.monetization.index') }}"><strong>Monetization</strong><span>Check serving status for your websites</span></a>@endif
            @if(auth()->user()->hasPermission('publisher.ads_txt.view'))<a href="{{ route('publisher.ads-txt.index') }}"><strong>Ads.txt</strong><span>Fix compliance and authorization issues</span></a>@endif
            @if(auth()->user()->hasPermission('support.tickets.view_own'))<a href="{{ route('support.tickets.index') }}"><strong>Support</strong><span>Get help from Horus Media</span></a>@endif
        </div>
    </article>
</section>

@if(auth()->user()->hasPermission('finance.publisher.view_own'))
<section class="split-grid workspace-section">
    <article>
        <div class="workspace-heading"><div><p class="eyebrow">Money</p><h2>Recent statements</h2></div><a class="section-anchor" href="{{ route('publisher.finance.statements.index') }}">All statements →</a></div>
        <div class="compact-list">
            @forelse($reporting['statements']->take(4) as $statement)
                <a class="compact-row" href="{{ route('publisher.finance.statements.show', $statement['id']) }}">
                    <div><strong>{{ $statement['period_key'] }}</strong><p>{{ $statement['statement_number'] }}</p></div>
                    <div class="row-action"><strong>{{ $statement['currency'] }} {{ \App\Support\Money::formatMinor((int) $statement['balance_due_minor']) }}</strong><x-status-badge :status="$statement['status']" /></div>
                </a>
            @empty
                <p class="muted">Your first statement will appear after a financial period is finalized.</p>
            @endforelse
        </div>
    </article>
    <article>
        <p class="eyebrow">Account readiness</p><h2>Payments and terms</h2>
        <div class="compact-row"><div><strong>Payment details</strong><p>{{ $publisher->paymentProfile ? $publisher->paymentProfile->payment_method.' · '.$publisher->paymentProfile->currency : 'Not configured yet' }}</p></div><x-status-badge :status="$publisher->paymentProfile?->verification_status?->value ?? 'INCOMPLETE'" /></div>
        <div class="compact-row"><div><strong>Commercial terms</strong><p>{{ $activeContract?->contract_reference ?: 'No active terms' }}</p></div><x-status-badge :status="$activeContract?->status?->value ?: 'PENDING'" /></div>
        <div class="button-row">
            <a class="hm-button-secondary button-link" href="{{ route('publisher.finance.payment-method.edit') }}">Payment details</a>
            @if(auth()->user()->hasPermission('contracts.view'))<a class="section-anchor" href="{{ route('publisher.contracts.index') }}">Commercial terms →</a>@endif
        </div>
    </article>
</section>
@endif
@endsection
