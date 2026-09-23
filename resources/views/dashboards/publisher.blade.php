@extends('layouts.admin')
@section('title', 'Overview')
@section('heading', 'Publisher overview')
@section('content')
@php
    $activeContract = $publisher->contracts->first(fn ($contract) => $contract->status === \App\Enums\ContractStatus::Active);
    $activeSites = $publisher->sites->where('status', \App\Enums\SiteStatus::Active)->count();
    $pendingSites = $publisher->sites->whereIn('status', [\App\Enums\SiteStatus::PendingVerification, \App\Enums\SiteStatus::PendingReview])->count();
    $currency = collect($reporting['currencies'])->firstWhere('currency', 'USD') ?? collect($reporting['currencies'])->first();
    $todayEarnings = (int) ($currency['today_estimated_earnings_minor'] ?? 0);
    $monthFinalized = (int) ($currency['finalized_earnings_minor'] ?? 0);
    $balanceDue = (int) ($currency['statement_balance_due_minor'] ?? 0);
    $currencyCode = $currency['currency'] ?? 'USD';
@endphp

<section class="hero publisher-home-hero">
    <div>
        <p class="eyebrow">Welcome back</p>
        <h2>{{ $publisher->display_name }}</h2>
        <p>See what needs attention, check today's performance, and jump directly to the work you came here to do.</p>
        <div class="status-row">
            <x-status-badge :status="$publisher->status" />
            @if(auth()->user()->hasPermission('finance.publisher.view_own'))
                <a class="hm-button-primary button-link" href="{{ route('publisher.finance.overview') }}">View earnings</a>
            @endif
            @if(auth()->user()->hasPermission('sites.view'))
                <a class="hm-button-secondary button-link" href="{{ route('publisher.sites.index') }}">View websites</a>
            @endif
        </div>
    </div>
    <div class="hero-stat" aria-label="Today's estimated earnings">
        <span>Today so far</span>
        <strong>{{ $currencyCode }} {{ \App\Support\Money::formatMinor($todayEarnings) }}</strong>
        <small>{{ number_format((int) ($currency['today_impressions'] ?? 0)) }} impressions</small>
    </div>
</section>

<section class="publisher-kpi-grid" aria-label="Publisher performance summary">
    @if(auth()->user()->hasPermission('finance.publisher.view_own'))
        <a class="publisher-kpi-card" href="{{ route('publisher.finance.overview') }}">
            <span>Today estimated</span>
            <strong>{{ $currencyCode }} {{ \App\Support\Money::formatMinor($todayEarnings) }}</strong>
            <small>Live estimate · may change</small>
        </a>
        <a class="publisher-kpi-card" href="{{ route('publisher.finance.overview') }}">
            <span>This month finalized</span>
            <strong>{{ $currencyCode }} {{ \App\Support\Money::formatMinor($monthFinalized) }}</strong>
            <small>{{ number_format((int) $reporting['impressions']) }} finalized impressions</small>
        </a>
    @endif
    @if(auth()->user()->hasPermission('sites.view'))
        <a class="publisher-kpi-card" href="{{ route('publisher.sites.index') }}">
            <span>Websites</span>
            <strong>{{ $activeSites }} live</strong>
            <small>{{ $pendingSites }} pending · {{ $publisher->sites->count() }} total</small>
        </a>
    @endif
    @if(auth()->user()->hasPermission('finance.publisher.view_own'))
        <a class="publisher-kpi-card" href="{{ route('publisher.finance.statements.index') }}">
            <span>Balance due</span>
            <strong>{{ $currencyCode }} {{ \App\Support\Money::formatMinor($balanceDue) }}</strong>
            <small>Latest finalized statement</small>
        </a>
    @endif
</section>

@if($actionItems !== [])
<section class="action-center publisher-priority-actions" aria-labelledby="publisher-action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Needs your attention</p><h2 id="publisher-action-center-heading">Next actions</h2></div>
        <x-status-badge status="PENDING" />
    </div>
    <div class="action-center-grid">
        @foreach($actionItems as $item)
            <a href="{{ route($item['route'], $item['parameters']) }}" class="action-card action-card-{{ $item['severity'] }}">
                <span class="action-count">{{ $item['count'] }}</span>
                <div><h3>{{ $item['label'] }}</h3><p>{{ $item['description'] }}</p><span class="section-anchor">Resolve now →</span></div>
            </a>
        @endforeach
    </div>
</section>
@endif

<section class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Start here</p><h2>Common tasks</h2></div></div>
    <div class="shortcut-grid">
        @if(auth()->user()->hasPermission('finance.publisher.view_own'))
        <a class="shortcut-card" href="{{ route('publisher.finance.overview') }}"><strong>Earnings</strong><span>Today, monthly earnings and payout status</span></a>
        @endif
        @if(auth()->user()->hasPermission('sites.view'))
        <a class="shortcut-card" href="{{ route('publisher.sites.index') }}"><strong>Websites</strong><span>Status, verification and installation</span></a>
        <a class="shortcut-card" href="{{ route('publisher.monetization.index') }}"><strong>Monetization</strong><span>See serving health across your sites</span></a>
        @endif
        @if(auth()->user()->hasPermission('support.tickets.view_own'))
        <a class="shortcut-card" href="{{ route('support.tickets.index') }}"><strong>Get support</strong><span>Open or follow a support ticket</span></a>
        @endif
    </div>
</section>

<section class="split-grid">
    <article>
        <div class="workspace-heading">
            <div><p class="eyebrow">Your inventory</p><h2>Websites</h2></div>
            @if(auth()->user()->hasPermission('sites.view'))<a class="section-anchor" href="{{ route('publisher.sites.index') }}">All websites →</a>@endif
        </div>
        <div class="compact-list">
            @forelse($publisher->sites->take(5) as $site)
                <a class="compact-row" href="{{ route('publisher.sites.show', $site) }}">
                    <div><strong>{{ $site->display_name }}</strong><p>{{ $site->primary_domain }}</p></div>
                    <x-status-badge :status="$site->status" />
                </a>
            @empty
                <x-empty-state title="No websites yet" description="Add your first website to begin monetization.">
                    @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
                </x-empty-state>
            @endforelse
        </div>
    </article>

    <article>
        <div class="workspace-heading"><div><p class="eyebrow">Account readiness</p><h2>Payments & terms</h2></div></div>
        @if(auth()->user()->hasPermission('finance.publisher.view_own'))
        <a class="compact-row" href="{{ route('publisher.finance.payment-method.edit') }}">
            <div><strong>Payment method</strong><p>{{ $publisher->paymentProfile ? $publisher->paymentProfile->payment_method.' · '.$publisher->paymentProfile->currency : 'Not configured yet' }}</p></div>
            <x-status-badge :status="$publisher->paymentProfile?->verification_status?->value ?? 'INCOMPLETE'" />
        </a>
        @endif
        @if(auth()->user()->hasPermission('contracts.view'))
        <a class="compact-row" href="{{ route('publisher.contracts.index') }}">
            <div><strong>Commercial terms</strong><p>{{ $activeContract?->contract_reference ?: 'No active terms' }}</p></div>
            <x-status-badge :status="$activeContract?->status?->value ?: 'PENDING'" />
        </a>
        @endif
        @if(auth()->user()->hasPermission('finance.publisher.view_own'))
        <a class="compact-row" href="{{ route('publisher.finance.statements.index') }}">
            <div><strong>Statements</strong><p>Finalized earnings and payout documents</p></div>
            <span class="section-anchor">Open →</span>
        </a>
        @endif
    </article>
</section>
@endsection
