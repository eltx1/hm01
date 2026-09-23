@extends('layouts.admin')
@section('title', 'Publisher Home')
@section('heading', 'Publisher Home')
@section('content')
@php
    $activeContract = $publisher->contracts->first(fn ($contract) => $contract->status === \App\Enums\ContractStatus::Active);
    $activeSites = $publisher->sites->where('status', \App\Enums\SiteStatus::Active)->count();
    $pendingSites = $publisher->sites->whereIn('status', [\App\Enums\SiteStatus::PendingVerification, \App\Enums\SiteStatus::PendingReview])->count();
    $currency = $reporting['currency'] ?? 'USD';
@endphp

<section class="hero dashboard-hero">
    <div>
        <p class="eyebrow">Welcome back</p>
        <h2>{{ $publisher->display_name }}</h2>
        <p>Start here for the three things that matter most: what needs your attention, how your websites are performing, and what you have earned.</p>
        <div class="status-row">
            <x-status-badge :status="$publisher->status" />
            @if(auth()->user()->hasPermission('finance.publisher.view_own'))
                <a class="hm-button-primary button-link" href="{{ route('publisher.finance.overview') }}">View reports &amp; earnings</a>
            @endif
            @if(auth()->user()->hasPermission('sites.view'))
                <a class="hm-button-secondary button-link" href="{{ route('publisher.sites.index') }}">Manage websites</a>
            @endif
            @if(auth()->user()->hasPermission('sites.manage'))
                <a class="text-link" href="{{ route('publisher.sites.create') }}">+ Add website</a>
            @endif
        </div>
    </div>
    <div class="hero-stat">
        <span>Reporting currency</span>
        <strong>{{ $currency }}</strong>
        <small>US Dollar</small>
    </div>
</section>

<nav class="dashboard-shortcuts" aria-label="Publisher quick actions">
    @if(auth()->user()->hasPermission('finance.publisher.view_own'))
        <a href="{{ route('publisher.finance.overview') }}"><span class="shortcut-icon" aria-hidden="true">$</span><strong>Reports &amp; Earnings</strong><small>Today, month-to-date, statements and payouts</small></a>
    @endif
    @if(auth()->user()->hasPermission('sites.view'))
        <a href="{{ route('publisher.sites.index') }}"><span class="shortcut-icon" aria-hidden="true">◎</span><strong>Websites</strong><small>Status, setup, ads.txt and installation codes</small></a>
        <a href="{{ route('publisher.monetization.index') }}"><span class="shortcut-icon" aria-hidden="true">↗</span><strong>Monetization</strong><small>See what is live and what needs attention</small></a>
    @endif
    @if(auth()->user()->hasPermission('support.tickets.view_own'))
        <a href="{{ route('support.tickets.index') }}"><span class="shortcut-icon" aria-hidden="true">?</span><strong>Get help</strong><small>Open or review support tickets</small></a>
    @endif
</nav>

@if($actionItems !== [])
<section class="action-center" aria-labelledby="publisher-action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Needs attention</p><h2 id="publisher-action-center-heading">Your next actions</h2></div>
        <x-status-badge status="PENDING" />
    </div>
    <div class="action-center-grid">
        @foreach($actionItems as $item)
            <a href="{{ route($item['route'], $item['parameters']) }}" class="action-card action-card-{{ $item['severity'] }}">
                <span class="action-count">{{ $item['count'] }}</span>
                <div><h3>{{ $item['label'] }}</h3><p>{{ $item['description'] }}</p><span class="section-anchor">Take action →</span></div>
            </a>
        @endforeach
    </div>
</section>
@endif

<section class="dashboard-metrics" aria-label="Publisher performance summary">
    <article class="dashboard-metric-card is-primary">
        <p class="eyebrow">Today so far</p>
        <strong class="metric">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $reporting['today_estimated_earnings_minor']) }}</strong>
        <span class="muted">{{ $reporting['today_available'] ? number_format((int) $reporting['today_impressions']).' impressions · estimated' : 'Waiting for today’s first report' }}</span>
    </article>
    <article class="dashboard-metric-card">
        <p class="eyebrow">Estimated this month</p>
        <strong class="metric">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $reporting['estimated_earnings_minor']) }}</strong>
        <span class="muted">Can still change before finalization</span>
    </article>
    <article class="dashboard-metric-card">
        <p class="eyebrow">Finalized this month</p>
        <strong class="metric">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $reporting['finalized_earnings_minor']) }}</strong>
        <span class="muted">{{ number_format((int) $reporting['impressions']) }} finalized impressions</span>
    </article>
    <article class="dashboard-metric-card">
        <p class="eyebrow">Current balance</p>
        <strong class="metric">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $reporting['payment_balance_minor']) }}</strong>
        <span class="muted">Latest finalized statement balance</span>
    </article>
</section>

@if($reporting['has_legacy_currencies'])
    <div class="notice" role="status">Your main dashboard is standardized to US Dollar (USD). Historical statements created in another currency remain available in Earnings for audit and payout history.</div>
@endif

<section class="dashboard-two-column">
    <article class="workspace-section">
        <div class="workspace-heading">
            <div><p class="eyebrow">Your inventory</p><h2>Websites</h2><p class="muted">{{ $activeSites }} active · {{ $pendingSites }} pending</p></div>
            @if(auth()->user()->hasPermission('sites.view'))<a class="section-anchor" href="{{ route('publisher.sites.index') }}">All websites →</a>@endif
        </div>
        <div class="compact-list">
            @forelse($publisher->sites->take(5) as $site)
                <a class="compact-row compact-row-link" href="{{ route('publisher.sites.show', $site) }}">
                    <div><strong>{{ $site->display_name }}</strong><p>{{ $site->primary_domain }} · {{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</p></div>
                    <x-status-badge :status="$site->status" />
                </a>
            @empty
                <x-empty-state title="No websites yet" description="Add your first website to begin verification and monetization.">
                    @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary button-link" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
                </x-empty-state>
            @endforelse
        </div>
    </article>

    <article class="workspace-section">
        <div class="workspace-heading"><div><p class="eyebrow">Account readiness</p><h2>Payments & terms</h2></div></div>
        @if(auth()->user()->hasPermission('finance.publisher.payment_profile.manage'))
            <a class="compact-row compact-row-link" href="{{ route('publisher.finance.payment-method.edit') }}">
                <div><strong>Payment method</strong><p>{{ $publisher->paymentProfile ? $publisher->paymentProfile->payment_method.' · '.$publisher->paymentProfile->currency : 'Not configured yet' }}</p></div>
                <x-status-badge :status="$publisher->paymentProfile?->verification_status?->value ?? 'INCOMPLETE'" />
            </a>
        @else
            <div class="compact-row">
                <div><strong>Payment method</strong><p>{{ $publisher->paymentProfile ? $publisher->paymentProfile->payment_method.' · '.$publisher->paymentProfile->currency : 'Not configured yet' }}</p></div>
                <x-status-badge :status="$publisher->paymentProfile?->verification_status?->value ?? 'INCOMPLETE'" />
            </div>
        @endif
        @if(auth()->user()->hasPermission('contracts.view'))
            <a class="compact-row compact-row-link" href="{{ route('publisher.contracts.index') }}">
                <div><strong>Commercial terms</strong><p>{{ $activeContract?->contract_reference ?: 'No active terms yet' }}</p></div>
                <x-status-badge :status="$activeContract?->status?->value ?: 'PENDING'" />
            </a>
        @endif
        @if(auth()->user()->hasPermission('finance.publisher.view_own'))
            <a class="compact-row compact-row-link" href="{{ route('publisher.finance.payouts.index') }}">
                <div><strong>Payout history</strong><p>{{ $currency }} {{ \App\Support\Money::formatMinor((int) $reporting['paid_minor']) }} paid</p></div>
                <span class="section-anchor">Open →</span>
            </a>
        @endif
    </article>
</section>

@if(auth()->user()->hasPermission('finance.publisher.view_own'))
<article class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Accounting record</p><h2>Recent USD statements</h2></div>
        <a class="section-anchor" href="{{ route('publisher.finance.statements.index') }}">All statements →</a>
    </div>
    <div class="compact-list">
        @forelse($reporting['statements']->take(3) as $statement)
            <a class="compact-row compact-row-link" href="{{ route('publisher.finance.statements.show', $statement['id']) }}">
                <div><strong>{{ $statement['statement_number'] }}</strong><p>{{ $statement['period_key'] }} · {{ $statement['currency'] }} {{ \App\Support\Money::formatMinor((int) $statement['balance_due_minor']) }} due</p></div>
                <x-status-badge :status="$statement['status']" />
            </a>
        @empty
            <p class="muted">No finalized USD statements yet.</p>
        @endforelse
    </div>
</article>
@endif
@endsection
