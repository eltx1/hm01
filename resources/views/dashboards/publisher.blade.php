@extends('layouts.admin')
@section('title', 'Home')
@section('heading', 'Publisher Home')
@section('content')
@php
    $activeContract = $publisher->contracts->first(fn ($contract) => $contract->status === \App\Enums\ContractStatus::Active);
    $activeSites = $publisher->sites->where('status', \App\Enums\SiteStatus::Active)->count();
    $pendingSites = $publisher->sites->whereIn('status', [\App\Enums\SiteStatus::PendingVerification, \App\Enums\SiteStatus::PendingReview])->count();
    $primary = $reporting['primary'];
    $currency = $reporting['currency'];
@endphp

<section class="hero">
    <div>
        <p class="eyebrow">Welcome back</p>
        <h2>{{ $publisher->display_name }}</h2>
        <p>Start here: check earnings, see what needs attention, or open a website. Horus reports new ad revenue in {{ $currency }}.</p>
        <div class="status-row">
            <x-status-badge :status="$publisher->status" />
            @if(auth()->user()->hasPermission('finance.publisher.view_own'))
                <a class="hm-button-primary button-link" href="{{ route('publisher.finance.overview') }}">View reports &amp; earnings</a>
            @endif
            @if(auth()->user()->hasPermission('sites.view'))
                <a class="hm-button-secondary button-link" href="{{ route('publisher.sites.index') }}">Open websites</a>
            @endif
            @if(auth()->user()->hasPermission('sites.manage'))
                <a class="text-link" href="{{ route('publisher.sites.create') }}">+ Add website</a>
            @endif
        </div>
    </div>
</section>

<section class="workspace-launchpad" aria-label="Publisher shortcuts">
    @if(auth()->user()->hasPermission('finance.publisher.view_own'))
    <a class="workspace-launch-card" href="{{ route('publisher.finance.overview') }}">
        <div>
            <p class="eyebrow">Reports &amp; Earnings</p>
            <h2>See your money first</h2>
            @if($primary['today_available'])
                <span class="workspace-launch-value">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $primary['today_estimated_earnings_minor']) }}</span>
                <p>Estimated earnings today · {{ number_format((int) $primary['today_impressions']) }} impressions</p>
            @else
                <span class="workspace-launch-value">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $primary['finalized_earnings_minor']) }}</span>
                <p>{{ number_format((int) $reporting['impressions']) }} finalized impressions this month. Today's estimate will appear when reporting arrives.</p>
            @endif
        </div>
        <span class="workspace-launch-action">Open reports &amp; earnings →</span>
    </a>
    @endif

    @if(auth()->user()->hasPermission('sites.view'))
    <a class="workspace-launch-card" href="{{ route('publisher.sites.index') }}">
        <div>
            <p class="eyebrow">Websites</p>
            <h2>{{ $activeSites }} active</h2>
            <span class="workspace-launch-value">{{ $publisher->sites->count() }}</span>
            <p>Total websites · {{ $pendingSites }} awaiting verification or review</p>
        </div>
        <span class="workspace-launch-action">Manage websites →</span>
    </a>
    @endif

    @if(auth()->user()->hasPermission('finance.publisher.view_own'))
    <a class="workspace-launch-card" href="{{ route('publisher.finance.payouts.index') }}">
        <div>
            <p class="eyebrow">Payments</p>
            <h2>Current balance</h2>
            <span class="workspace-launch-value">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $primary['statement_balance_due_minor']) }}</span>
            <p>{{ $primary['readiness']['label'] }}</p>
        </div>
        <span class="workspace-launch-action">View payouts →</span>
    </a>
    @endif
</section>

<section class="action-center" aria-labelledby="publisher-action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">What needs attention</p><h2 id="publisher-action-center-heading">Your next actions</h2></div>
        <x-status-badge :status="$actionItems === [] ? 'HEALTHY' : 'PENDING'" />
    </div>
    @if($actionItems === [])
        <article><h3>You're all caught up</h3><p class="muted">There are no Publisher actions waiting for you right now.</p></article>
    @else
        <div class="action-center-grid">
            @foreach($actionItems as $item)
                <a href="{{ route($item['route'], $item['parameters']) }}" class="action-card action-card-{{ $item['severity'] }}">
                    <span class="action-count">{{ $item['count'] }}</span>
                    <div><h3>{{ $item['label'] }}</h3><p>{{ $item['description'] }}</p><span class="section-anchor">Open and fix →</span></div>
                </a>
            @endforeach
        </div>
    @endif
</section>

<div class="dashboard-section-heading">
    <div><p class="eyebrow">Your inventory</p><h2>Websites</h2></div>
    @if(auth()->user()->hasPermission('sites.view'))<a class="section-anchor" href="{{ route('publisher.sites.index') }}">View all websites →</a>@endif
</div>
<article>
    <div class="compact-list">
        @forelse($publisher->sites->take(6) as $site)
            <a class="compact-row" href="{{ route('publisher.sites.show', $site) }}">
                <div><strong>{{ $site->display_name }}</strong><p>{{ $site->primary_domain }} · {{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</p></div>
                <x-status-badge :status="$site->status" />
            </a>
        @empty
            <x-empty-state title="Add your first website" description="Your website is the starting point for verification, monetization and reporting.">
                @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
            </x-empty-state>
        @endforelse
    </div>
</article>

<section class="split-grid">
    <article>
        <div class="workspace-heading"><div><p class="eyebrow">Account setup</p><h2>Payments &amp; terms</h2></div></div>
        <div class="compact-row">
            <div><strong>Payment method</strong><p>{{ $publisher->paymentProfile ? $publisher->paymentProfile->payment_method : 'Not configured yet' }}</p></div>
            <x-status-badge :status="$publisher->paymentProfile?->verification_status?->value ?? 'INCOMPLETE'" />
        </div>
        <div class="compact-row">
            <div><strong>Commercial terms</strong><p>{{ $activeContract?->contract_reference ?: 'No active terms' }}</p></div>
            <x-status-badge :status="$activeContract?->status?->value ?: 'PENDING'" />
        </div>
        <div class="button-row">
            @if(auth()->user()->hasPermission('finance.publisher.view_own'))<a class="hm-button-secondary button-link" href="{{ route('publisher.finance.payment-method.edit') }}">Payment method</a>@endif
            @if(auth()->user()->hasPermission('contracts.view'))<a class="text-link" href="{{ route('publisher.contracts.index') }}">Commercial terms</a>@endif
        </div>
    </article>

    @if(auth()->user()->hasPermission('finance.publisher.view_own'))
    <article>
        <div class="workspace-heading"><div><p class="eyebrow">Recent finance</p><h2>Latest statements</h2></div><a class="section-anchor" href="{{ route('publisher.finance.statements.index') }}">All statements →</a></div>
        <div class="compact-list">
            @forelse($reporting['statements']->take(4) as $statement)
                <a class="compact-row" href="{{ route('publisher.finance.statements.show', $statement['id']) }}">
                    <div><strong>{{ $statement['statement_number'] }}</strong><p>{{ $statement['period_key'] }} · {{ $statement['currency'] }} {{ \App\Support\Money::formatMinor((int) $statement['balance_due_minor']) }} due</p></div>
                    <x-status-badge :status="$statement['status']" />
                </a>
            @empty
                <p class="muted">Your first statement will appear after a financial period is finalized.</p>
            @endforelse
        </div>
        @if($reporting['legacy_currency_count'] > 0)
            <p class="muted">Historical non-USD accounting remains preserved in statements; new GAM reporting is standardized to USD.</p>
        @endif
    </article>
    @endif
</section>
@endsection
