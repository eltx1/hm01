@extends('layouts.admin')
@section('title', 'Publisher overview')
@section('heading', 'Publisher overview')
@section('content')
@php
    $activeSites = $publisher->sites->where('status', \App\Enums\SiteStatus::Active)->count();
    $pendingSites = $publisher->sites->whereIn('status', [\App\Enums\SiteStatus::PendingVerification, \App\Enums\SiteStatus::PendingReview])->count();
@endphp

<section class="hero">
    <div>
        <p class="eyebrow">Publisher workspace</p>
        <h2>{{ $publisher->display_name }}</h2>
        <p>Start here: check your earnings, fix anything that needs attention, and manage your websites.</p>
        <div class="status-row">
            <x-status-badge :status="$publisher->status" />
            @if(auth()->user()->hasPermission('finance.publisher.view_own'))<a class="hm-button-primary button-link" href="{{ route('publisher.finance.overview') }}">View reports &amp; earnings</a>@endif
            @if(auth()->user()->hasPermission('sites.view'))<a class="hm-button-secondary button-link" href="{{ route('publisher.sites.index') }}">Manage websites</a>@endif
            @if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-secondary button-link" href="{{ route('publisher.sites.create') }}">Add website</a>@endif
        </div>
    </div>
    <span class="canonical-currency-note">Reporting currency · {{ $reporting['currency'] }}</span>
</section>

<section class="dashboard-primary" aria-label="Publisher summary">
    <article>
        <p class="eyebrow">Today so far</p>
        <strong class="metric">{{ $reporting['currency'] }} {{ \App\Support\Money::formatMinor((int) $reporting['today_estimated_earnings_minor']) }}</strong>
        <span class="muted">{{ $reporting['today_available'] ? number_format($reporting['today_impressions']).' impressions · estimated' : 'Waiting for today’s reporting data' }}</span>
    </article>
    <article>
        <p class="eyebrow">This month</p>
        <strong class="metric">{{ $reporting['currency'] }} {{ \App\Support\Money::formatMinor((int) $reporting['finalized_earnings_minor']) }}</strong>
        <span class="muted">Finalized publisher earnings</span>
    </article>
    <article>
        <p class="eyebrow">Impressions</p>
        <strong class="metric">{{ number_format($reporting['impressions']) }}</strong>
        <span class="muted">Finalized this month</span>
    </article>
    <article>
        <p class="eyebrow">Websites</p>
        <strong class="metric">{{ $activeSites }}</strong>
        <span class="muted">{{ $pendingSites }} pending · {{ $publisher->sites->count() }} total</span>
    </article>
</section>

@if($actionItems !== [])
<section class="action-center" aria-labelledby="publisher-action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Needs your attention</p><h2 id="publisher-action-center-heading">Next actions</h2></div>
        <x-status-badge status="PENDING" />
    </div>
    <div class="action-center-grid">
        @foreach($actionItems as $item)
            <a href="{{ route($item['route'], $item['parameters']) }}" class="action-card action-card-{{ $item['severity'] }}">
                <span class="action-count">{{ $item['count'] }}</span>
                <div><h3>{{ $item['label'] }}</h3><p>{{ $item['description'] }}</p><span class="section-anchor">Open →</span></div>
            </a>
        @endforeach
    </div>
</section>
@else
<div class="notice" role="status">No action is required from you right now.</div>
@endif

<div class="dashboard-section-title"><p class="eyebrow">Quick navigation</p><h2>Where do you want to go?</h2></div>
<section class="destination-grid" aria-label="Publisher destinations">
    @if(auth()->user()->hasPermission('finance.publisher.view_own'))
    <a class="destination-card" href="{{ route('publisher.finance.overview') }}">
        <strong>Reports &amp; earnings</strong>
        <span>See today’s estimate, finalized monthly earnings, statements, and payout status.</span>
        <span class="section-anchor">Open reports →</span>
    </a>
    @endif
    @if(auth()->user()->hasPermission('sites.view'))
    <a class="destination-card" href="{{ route('publisher.sites.index') }}">
        <strong>Websites</strong>
        <span>Check status, verification, installation, and site-specific setup.</span>
        <span class="section-anchor">Manage websites →</span>
    </a>
    <a class="destination-card" href="{{ route('publisher.monetization.index') }}">
        <strong>Monetization health</strong>
        <span>See whether monetization is healthy and what action is required when it is not.</span>
        <span class="section-anchor">Check monetization →</span>
    </a>
    @endif
</section>

<section class="split-grid">
    <article>
        <div class="workspace-heading">
            <div><p class="eyebrow">Your websites</p><h2>Recent sites</h2></div>
            @if(auth()->user()->hasPermission('sites.view'))<a class="section-anchor" href="{{ route('publisher.sites.index') }}">View all</a>@endif
        </div>
        <div class="compact-list">
            @forelse($publisher->sites->take(5) as $site)
                <a class="compact-row" href="{{ route('publisher.sites.show', $site) }}">
                    <div><strong>{{ $site->display_name }}</strong><p>{{ $site->primary_domain }}</p></div>
                    <x-status-badge :status="$site->status" />
                </a>
            @empty
                <p class="muted">No websites yet. Add your first website to begin.</p>
            @endforelse
        </div>
    </article>

    @if(auth()->user()->hasPermission('finance.publisher.view_own'))
    <article>
        <div class="workspace-heading">
            <div><p class="eyebrow">Payments</p><h2>Latest statements</h2></div>
            <a class="section-anchor" href="{{ route('publisher.finance.statements.index') }}">View all</a>
        </div>
        <div class="compact-list">
            @forelse($reporting['statements']->take(5) as $statement)
                <a class="compact-row" href="{{ route('publisher.finance.statements.show', $statement['id']) }}">
                    <div><strong>{{ $statement['statement_number'] }}</strong><p>{{ $statement['period_key'] }} · {{ $statement['currency'] }} {{ \App\Support\Money::formatMinor((int) $statement['balance_due_minor']) }} due</p></div>
                    <x-status-badge :status="$statement['status']" />
                </a>
            @empty
                <p class="muted">Statements appear after a financial period is finalized.</p>
            @endforelse
        </div>
        <div class="status-row">
            <span class="muted">Current USD balance</span>
            <strong>{{ $reporting['currency'] }} {{ \App\Support\Money::formatMinor((int) $reporting['payment_balance_minor']) }}</strong>
        </div>
    </article>
    @endif
</section>
@endsection
