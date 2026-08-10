@extends('layouts.admin')
@section('title', $publisher->display_name.' · Publisher 360')
@section('heading', 'Publisher 360')
@section('content')
@php
    $tabs = [
        ['label' => 'Overview', 'href' => '#overview'],
        ['label' => 'Websites', 'href' => '#websites'],
        ['label' => 'Contracts', 'href' => '#contracts', 'visible' => auth()->user()->hasPermission('contracts.view')],
        ['label' => 'Monetization', 'href' => '#monetization'],
        ['label' => 'Compliance', 'href' => '#compliance'],
        ['label' => 'Reporting', 'href' => '#reporting', 'visible' => $reporting !== null],
        ['label' => 'Finance', 'href' => '#finance', 'visible' => $reporting !== null],
        ['label' => 'Users', 'href' => '#users', 'visible' => auth()->user()->hasPermission('users.view')],
        ['label' => 'Audit', 'href' => '#audit', 'visible' => auth()->user()->hasPermission('audit.view')],
    ];
    $activeSites = $publisher->sites->where('status', \App\Enums\SiteStatus::Active)->count();
    $verifiedDomains = $publisher->sites->flatMap->domains->where('verification_status', 'VERIFIED')->count();
@endphp

<x-control-plane.workspace-tabs :items="$tabs" label="Publisher 360 sections" />

<section id="overview" class="hero workspace-section">
    <div>
        <p class="eyebrow">Publisher account</p><h2>{{ $publisher->display_name }}</h2>
        <p>{{ $publisher->legal_name }} · {{ $publisher->organization->name }}</p>
        <div class="status-row"><x-status-badge :status="$publisher->status" /><span class="status">Onboarding {{ $publisher->onboarding_step }}/7</span></div>
        @if(auth()->user()->hasPermission('publishers.manage'))<a class="hm-button-primary button-link" href="{{ route('admin.publishers.edit', $publisher) }}">Edit publisher</a>@endif
    </div>
</section>

<section class="metric-grid" aria-label="Publisher 360 summary">
    <article><p class="eyebrow">Websites</p><strong class="metric">{{ $publisher->sites->count() }}</strong><span class="muted">{{ $activeSites }} active</span></article>
    <article><p class="eyebrow">Verified domains</p><strong class="metric">{{ $verifiedDomains }}</strong><span class="muted">{{ $publisher->sites->flatMap->domains->count() }} authorized</span></article>
    <article><p class="eyebrow">Users</p><strong class="metric">{{ $publisher->organization->users->count() }}</strong><span class="muted">Organization members</span></article>
    <article><p class="eyebrow">Contracts</p><strong class="metric">{{ $publisher->contracts->count() }}</strong><span class="muted">{{ $publisher->contracts->where('status', \App\Enums\ContractStatus::Active)->count() }} active</span></article>
    @if($reporting)<article><p class="eyebrow">Balance due</p><strong class="metric">{{ \App\Support\Money::formatMinor((int) $reporting['payment_balance_minor']) }} {{ $reporting['currency'] }}</strong><span class="muted">Latest finalized {{ $reporting['currency'] }} statement</span></article>@endif
</section>

<article id="websites" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Inventory ownership</p><h2>Websites</h2></div><a class="section-anchor" href="{{ route('admin.sites.index') }}">All websites</a></div>
    <div class="compact-list">
        @forelse($publisher->sites as $site)
            <a class="compact-row" href="{{ route('admin.sites.show', $site) }}"><div><strong>{{ $site->display_name }}</strong><p>{{ $site->primary_domain }} · {{ str($site->serving_mode->value)->replace('_', ' ')->headline() }}</p></div><x-status-badge :status="$site->status" /></a>
        @empty<p class="muted">No websites are attached to this publisher.</p>@endforelse
    </div>
</article>

@if(auth()->user()->hasPermission('contracts.view'))
<article id="contracts" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Commercial terms</p><h2>Contracts</h2></div><a class="section-anchor" href="{{ route('admin.publishers.contracts.index', $publisher) }}">Manage contracts</a></div>
    @forelse($publisher->contracts as $contract)<div class="compact-row"><div><strong>{{ $contract->contract_reference }}</strong><p>{{ $contract->starts_at?->format('M j, Y') }} – {{ $contract->ends_at?->format('M j, Y') ?: 'Open ended' }} · {{ $contract->revenue_share_percent }}%</p></div><x-status-badge :status="$contract->status" /></div>@empty<p class="muted">No publisher contracts.</p>@endforelse
</article>
@endif

<article id="monetization" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Serving architecture</p><h2>Monetization</h2></div></div>
    <div class="health-grid">
        <div><span class="muted">HORUS_GAM sites</span><strong class="metric-small">{{ $publisher->sites->where('serving_mode', \App\Enums\ServingMode::HorusGam)->count() }}</strong></div>
        <div><span class="muted">Prebid enabled</span><strong class="metric-small">{{ $publisher->sites->where('prebid_enabled', true)->count() }}</strong></div>
        <div><span class="muted">Native demand enabled</span><strong class="metric-small">{{ $publisher->sites->where('native_demand_enabled', true)->count() }}</strong></div>
    </div>
</article>

<article id="compliance" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Identity and domain evidence</p><h2>Compliance</h2></div></div>
    <p class="muted">The publisher business identity is canonical for OWNERDOMAIN. Tasks 3–4 add live Ads.txt checks and the complete seller control center.</p>
    <div class="compact-row"><div><strong>{{ $publisher->business_domain ?: 'Business domain not declared' }}</strong><p>{{ $publisher->business_domain ? 'Publisher business domain · canonical OWNERDOMAIN' : 'Legacy per-site OWNERDOMAIN fallback · Admin review required' }}</p></div><x-status-badge :status="$publisher->supply_chain_review_status" /></div>
    @foreach($publisher->sites as $site)@foreach($site->domains as $domain)<div class="compact-row"><div><strong>{{ $domain->domain }}</strong><p>{{ $site->display_name }}</p></div><x-status-badge :status="$domain->verification_status" /></div>@endforeach @endforeach
</article>

@if($reporting)
<article id="reporting" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Aggregated reporting</p><h2>Publisher performance</h2></div><a class="section-anchor" href="{{ route('admin.reporting.index') }}">Reporting control</a></div>
    <div class="health-grid"><div><span class="muted">Impressions</span><strong class="metric-small">{{ number_format($reporting['impressions']) }}</strong></div><div><span class="muted">Publisher earnings</span><strong class="metric-small">{{ \App\Support\Money::formatMinor((int) $reporting['revenue_minor']) }} {{ $reporting['currency'] }}</strong></div><div><span class="muted">Balance due</span><strong class="metric-small">{{ \App\Support\Money::formatMinor((int) $reporting['payment_balance_minor']) }} {{ $reporting['currency'] }}</strong></div></div>
</article>
<article id="finance" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Payout readiness</p><h2>Finance</h2></div>@if(auth()->user()->hasPermission('publisher_payments.manage'))<a class="section-anchor" href="{{ route('admin.publishers.payment-profile.edit', $publisher) }}">Payment profile</a>@endif</div>
    <div class="compact-row"><div><strong>Payment profile</strong><p>{{ $publisher->paymentProfile?->payment_method ?: 'Not configured' }} · {{ $publisher->paymentProfile?->currency ?: 'No currency' }}</p></div><x-status-badge :status="$publisher->paymentProfile?->verification_status?->value ?? 'INCOMPLETE'" /></div>
    @forelse($statements as $statement)<a class="compact-row" href="{{ route('admin.reporting.statements.show', $statement) }}"><div><strong>{{ $statement->statement_number }}</strong><p>{{ $statement->period->period_key }} · {{ \App\Support\Money::formatMinor((int) $statement->balance_due_minor) }} {{ $statement->currency }} due</p></div><x-status-badge :status="$statement->status" /></a>@empty<p class="muted">No finalized statements.</p>@endforelse
</article>
@endif

@if(auth()->user()->hasPermission('users.view'))
<article id="users" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Organization access</p><h2>Users</h2></div>@if(auth()->user()->hasPermission('users.invite'))<a class="section-anchor" href="{{ route('admin.invitations.create') }}">Invite user</a>@endif</div>
    @forelse($publisher->organization->users as $member)<div class="compact-row"><div><strong>{{ $member->name }}</strong><p>{{ $member->email }} · {{ $member->roles->pluck('display_name')->join(', ') }}</p></div><x-status-badge :status="$member->status" /></div>@empty<p class="muted">No organization users.</p>@endforelse
</article>
@endif

@if(auth()->user()->hasPermission('audit.view'))
<article id="audit" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Immutable evidence</p><h2>Recent audit activity</h2></div></div>
    @forelse($auditEvents as $event)<div class="compact-row"><div><strong>{{ str($event->event)->replace('.', ' ')->headline() }}</strong><p>{{ $event->auditable_type ? class_basename($event->auditable_type) : 'Organization' }}</p></div><span class="muted">{{ $event->created_at }}</span></div>@empty<p class="muted">No audit activity for this organization.</p>@endforelse
</article>
@endif
@endsection
