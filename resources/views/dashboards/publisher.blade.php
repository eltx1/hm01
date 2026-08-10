@extends('layouts.admin')
@section('title', 'Publisher overview')
@section('heading', 'Publisher overview')
@section('content')
@php
    $activeContract = $publisher->contracts->first(fn ($contract) => $contract->status === \App\Enums\ContractStatus::Active);
    $activeSites = $publisher->sites->where('status', \App\Enums\SiteStatus::Active)->count();
    $pendingSites = $publisher->sites->whereIn('status', [\App\Enums\SiteStatus::PendingVerification, \App\Enums\SiteStatus::PendingReview])->count();
@endphp
<section class="hero">
    <div>
        <p class="eyebrow">Publisher workspace</p>
        <h2>{{ $publisher->display_name }}</h2>
        <p>Manage websites, follow onboarding and serving readiness, and review finalized aggregated earnings, statements, and payment balances.</p>
        <div class="status-row">
            <x-status-badge :status="$publisher->status" />
            @if(auth()->user()->hasPermission('sites.view'))<a class="hm-button-primary button-link" href="{{ route('publisher.sites.index') }}">Manage websites</a>@endif
            @if(auth()->user()->hasPermission('reporting.publisher.view'))<a class="hm-button-secondary button-link" href="{{ route('publisher.reporting.index') }}">Open reports</a>@endif
        </div>
    </div>
</section>

<section class="metric-grid" aria-label="Publisher summary">
    <article><p class="eyebrow">Websites</p><strong class="metric">{{ $publisher->sites->count() }}</strong><span class="muted">{{ $activeSites }} serving · {{ $pendingSites }} pending</span></article>
    <article><p class="eyebrow">Impressions</p><strong class="metric">{{ number_format($reporting['impressions']) }}</strong><span class="muted">Finalized aggregated data</span></article>
    <article><p class="eyebrow">Publisher earnings</p><strong class="metric">{{ number_format($reporting['revenue_minor'] / 100, 2) }}</strong><span class="muted">Your contractual share</span></article>
    <article><p class="eyebrow">Payment balance</p><strong class="metric">{{ number_format($reporting['payment_balance_minor'] / 100, 2) }}</strong><span class="muted">Across finalized statements</span></article>
    <article><p class="eyebrow">Onboarding</p><strong class="metric">{{ $publisher->onboarding_step }}/7</strong><span class="muted">{{ $publisher->onboarding_submitted_at ? 'Submitted' : 'In progress' }}</span></article>
</section>

<section class="split-grid">
    <article>
        <div class="workspace-heading"><div><p class="eyebrow">Serving readiness</p><h2>Websites</h2></div>@if(auth()->user()->hasPermission('sites.view'))<a class="section-anchor" href="{{ route('publisher.sites.index') }}">View all</a>@endif</div>
        <div class="compact-list">
            @forelse($publisher->sites->take(6) as $site)
                <div class="compact-row"><div><strong>{{ $site->display_name }}</strong><p>{{ $site->primary_domain }} · {{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</p></div><x-status-badge :status="$site->status" /></div>
            @empty
                <p class="muted">No websites have been created yet.</p>
            @endforelse
        </div>
    </article>
    <article>
        <p class="eyebrow">Commercial readiness</p><h2>Contract and payment profile</h2>
        <div class="compact-row"><div><strong>Payment profile</strong><p>{{ $publisher->paymentProfile ? $publisher->paymentProfile->payment_method.' · '.$publisher->paymentProfile->currency : 'Not configured' }}</p></div><x-status-badge :status="$publisher->paymentProfile?->verification_status?->value ?? 'INCOMPLETE'" /></div>
        <div class="compact-row"><div><strong>Contract</strong><p>{{ $activeContract?->contract_reference ?: 'No active contract' }}</p></div><x-status-badge :status="$activeContract?->status?->value ?: 'PENDING'" /></div>
        @if(auth()->user()->hasPermission('contracts.view'))<a class="hm-button-secondary button-link" href="{{ route('publisher.contracts.index') }}">Review contracts</a>@endif
    </article>
</section>

@if(auth()->user()->hasPermission('reporting.publisher.view'))
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Finalized finance</p><h2>Latest statements</h2></div><a class="section-anchor" href="{{ route('publisher.reporting.index') }}">All reports</a></div>
    <div class="compact-list">
        @forelse($reporting['statements']->take(6) as $statement)
            <a class="compact-row" href="{{ route('publisher.reporting.statements.show', $statement) }}"><div><strong>{{ $statement->statement_number }}</strong><p>{{ $statement->period->period_key }} · {{ number_format($statement->balance_due_minor / 100, 2) }} {{ $statement->currency }} due</p></div><x-status-badge :status="$statement->status" /></a>
        @empty
            <p class="muted">Statements appear after a financial period is finalized.</p>
        @endforelse
    </div>
</article>
@endif
@endsection
