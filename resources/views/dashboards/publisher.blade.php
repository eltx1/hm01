@extends('layouts.admin')
@section('title', 'Dashboard')
@section('heading', 'Dashboard')
@section('content')
@php
    $activeContract = $publisher->contracts->first(fn ($contract) => $contract->status === \App\Enums\ContractStatus::Active);
    $activeSites = $publisher->sites->where('status', \App\Enums\SiteStatus::Active)->count();
    $pendingSites = $publisher->sites->whereIn('status', [\App\Enums\SiteStatus::PendingVerification, \App\Enums\SiteStatus::PendingReview])->count();
    $money = $reporting['primary'];
    $currency = $reporting['canonical_currency'];
@endphp

<section class="hero publisher-home-hero">
    <div>
        <p class="eyebrow">Publisher home</p>
        <h2>{{ $publisher->display_name }}</h2>
        <p>See your earnings, check your websites, and fix anything that needs attention from one place.</p>
        <div class="status-row">
            <x-status-badge :status="$publisher->status" />
            @if(auth()->user()->hasPermission('finance.publisher.view_own'))
                <a class="hm-button-primary button-link" href="{{ route('publisher.finance.overview') }}">View reports &amp; earnings</a>
            @endif
            @if(auth()->user()->hasPermission('sites.view'))
                <a class="hm-button-secondary button-link" href="{{ route('publisher.sites.index') }}">Manage websites</a>
            @endif
        </div>
    </div>
</section>

<section class="workspace-section" aria-labelledby="publisher-start-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Start here</p><h2 id="publisher-start-heading">What do you want to do?</h2></div>
    </div>
    <div class="task-launcher">
        @if(auth()->user()->hasPermission('finance.publisher.view_own'))
        <a class="task-card" href="{{ route('publisher.finance.overview') }}">
            <strong>Reports &amp; earnings</strong>
            <span>Today, this month, statements and payouts.</span>
            <b>Open reports →</b>
        </a>
        @endif
        @if(auth()->user()->hasPermission('sites.view'))
        <a class="task-card" href="{{ route('publisher.sites.index') }}">
            <strong>Websites</strong>
            <span>Add a site, check its status, or copy installation codes.</span>
            <b>Manage websites →</b>
        </a>
        <a class="task-card" href="{{ route('publisher.monetization.index') }}">
            <strong>Monetization health</strong>
            <span>See whether ads can serve and exactly what needs fixing.</span>
            <b>Check health →</b>
        </a>
        @endif
        @if(auth()->user()->hasPermission('support.tickets.view_own'))
        <a class="task-card" href="{{ route('support.tickets.index') }}">
            <strong>Get help</strong>
            <span>Open or follow a support request without hunting through settings.</span>
            <b>Open support →</b>
        </a>
        @endif
    </div>
</section>

<section class="action-center" aria-labelledby="publisher-action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Needs your attention</p><h2 id="publisher-action-center-heading">Next actions</h2></div>
        <x-status-badge :status="$actionItems === [] ? 'HEALTHY' : 'PENDING'" />
    </div>
    @if($actionItems === [])
        <article class="calm-state"><h3>Nothing needs your attention right now</h3><p class="muted">Your current Publisher workflows have no unresolved action.</p></article>
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

<section class="metric-grid publisher-key-metrics" aria-label="Publisher USD summary">
    <article>
        <p class="eyebrow">Today · estimated</p>
        <strong class="metric">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $money['today_estimated_earnings_minor']) }}</strong>
        <span class="muted">{{ $money['today_available'] ? number_format((int) $money['today_impressions']).' impressions so far' : 'Waiting for today’s report' }}</span>
    </article>
    <article>
        <p class="eyebrow">This month · finalized</p>
        <strong class="metric">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $money['finalized_earnings_minor']) }}</strong>
        <span class="muted">Your finalized contractual earnings</span>
    </article>
    <article>
        <p class="eyebrow">Statement balance</p>
        <strong class="metric">{{ $currency }} {{ \App\Support\Money::formatMinor((int) $money['statement_balance_due_minor']) }}</strong>
        <span class="muted">Latest finalized USD statement</span>
    </article>
    <article>
        <p class="eyebrow">Impressions this month</p>
        <strong class="metric">{{ number_format($reporting['impressions']) }}</strong>
        <span class="muted">Finalized USD reporting data</span>
    </article>
</section>

@if(($reporting['legacy_currency_count'] ?? 0) > 0)
    <p class="muted workspace-note">Your dashboard uses {{ $currency }} as the standard reporting currency. Older records in other currencies remain available in Statements for accounting history.</p>
@endif

<section class="split-grid publisher-home-grid">
    <article>
        <div class="workspace-heading">
            <div><p class="eyebrow">Your websites</p><h2>{{ $activeSites }} active · {{ $pendingSites }} pending</h2></div>
            @if(auth()->user()->hasPermission('sites.view'))<a class="section-anchor" href="{{ route('publisher.sites.index') }}">All websites →</a>@endif
        </div>
        <div class="compact-list">
            @forelse($publisher->sites->take(5) as $site)
                <a class="compact-row" href="{{ route('publisher.sites.show', $site) }}">
                    <div><strong>{{ $site->display_name }}</strong><p>{{ $site->primary_domain }}</p></div>
                    <div class="row-status"><x-status-badge :status="$site->status" /><span class="section-anchor">Open →</span></div>
                </a>
            @empty
                <x-empty-state title="No websites yet" description="Add your first website to start monetization setup.">
                    @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary button-link" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
                </x-empty-state>
            @endforelse
        </div>
    </article>

    <article>
        <div class="workspace-heading"><div><p class="eyebrow">Account setup</p><h2>Payments &amp; commercial terms</h2></div></div>
        @if(auth()->user()->hasPermission('finance.publisher.view_own'))
        <a class="compact-row" href="{{ route('publisher.finance.payment-method.edit') }}">
            <div><strong>Payment method</strong><p>{{ $publisher->paymentProfile ? $publisher->paymentProfile->payment_method.' · '.$publisher->paymentProfile->currency : 'Add your payout details' }}</p></div>
            <x-status-badge :status="$publisher->paymentProfile?->verification_status?->value ?? 'INCOMPLETE'" />
        </a>
        @else
        <div class="compact-row">
            <div><strong>Payment method</strong><p>Payment details are not available to this role.</p></div>
        </div>
        @endif
        @if(auth()->user()->hasPermission('contracts.view'))
        <a class="compact-row" href="{{ route('publisher.contracts.index') }}">
            <div><strong>Commercial terms</strong><p>{{ $activeContract?->contract_reference ?: 'No active terms' }}</p></div>
            <x-status-badge :status="$activeContract?->status?->value ?: 'PENDING'" />
        </a>
        @endif
    </article>
</section>

@if(auth()->user()->hasPermission('finance.publisher.view_own'))
<article class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Recent accounting</p><h2>Latest USD statements</h2></div>
        <a class="section-anchor" href="{{ route('publisher.finance.statements.index') }}">All statements →</a>
    </div>
    <div class="compact-list">
        @forelse($reporting['statements']->take(3) as $statement)
            <a class="compact-row" href="{{ route('publisher.finance.statements.show', $statement['id']) }}">
                <div><strong>{{ $statement['statement_number'] }}</strong><p>{{ $statement['period_key'] }} · {{ $statement['currency'] }} {{ \App\Support\Money::formatMinor((int) $statement['balance_due_minor']) }} due</p></div>
                <x-status-badge :status="$statement['status']" />
            </a>
        @empty
            <p class="muted">Statements appear here after a financial period is finalized.</p>
        @endforelse
    </div>
</article>
@endif
@endsection
