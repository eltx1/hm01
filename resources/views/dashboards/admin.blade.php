@extends('layouts.admin')
@section('title', 'Home')
@section('heading', 'Horus Operations')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Operations home</p>
        <h2>See what needs attention, then act.</h2>
        <p>The dashboard is the starting point for Publisher operations, websites, reporting, finance and production health.</p>
        <div class="status-row">
            @if(auth()->user()->hasPermission('publishers.view'))<a class="hm-button-primary button-link" href="{{ route('admin.publishers.index') }}">Publishers</a>@endif
            @if(auth()->user()->hasPermission('sites.view'))<a class="hm-button-secondary button-link" href="{{ route('admin.sites.index') }}">Websites</a>@endif
            @if(auth()->user()->hasPermission('reporting.admin.view'))<a class="text-link" href="{{ route('admin.reporting.index') }}">Reporting &amp; revenue</a>@endif
        </div>
    </div>
</section>

<section class="action-center" aria-labelledby="action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">What needs attention</p><h2 id="action-center-heading">Action Center</h2></div>
        <x-status-badge :status="$actionItems === [] ? 'HEALTHY' : 'PENDING'" />
    </div>
    @if($actionItems === [])
        <article><h3>No current action items</h3><p class="muted">The workflows visible to your role have no unresolved conditions.</p></article>
    @else
        <div class="action-center-grid">
            @foreach($actionItems as $item)
                <a href="{{ route($item['route'], $item['parameters']) }}" class="action-card action-card-{{ $item['severity'] }}">
                    <span class="action-count">{{ $item['count'] }}</span>
                    <div><h3>{{ $item['label'] }}</h3><p>{{ $item['description'] }}</p><span class="section-anchor">Open and resolve →</span></div>
                </a>
            @endforeach
        </div>
    @endif
</section>

<div class="dashboard-section-heading"><div><p class="eyebrow">Common workflows</p><h2>Go straight to the work</h2></div></div>
<section class="workspace-launchpad">
    @if(auth()->user()->hasPermission('publishers.view'))
    <a class="workspace-launch-card" href="{{ route('admin.publishers.index') }}">
        <div><p class="eyebrow">Publishers</p><span class="workspace-launch-value">{{ number_format((int) $totalPublishers) }}</span><p>Accounts, review, commercial terms and Publisher 360.</p></div>
        <span class="workspace-launch-action">Open publishers →</span>
    </a>
    @endif
    @if(auth()->user()->hasPermission('sites.view'))
    <a class="workspace-launch-card" href="{{ route('admin.sites.index') }}">
        <div><p class="eyebrow">Websites</p><span class="workspace-launch-value">{{ number_format((int) $totalWebsites) }}</span><p>Activation, reporting, monetization, compliance and site health.</p></div>
        <span class="workspace-launch-action">Open websites →</span>
    </a>
    @endif
    @if($reporting)
    <a class="workspace-launch-card" href="{{ route('admin.reporting.index') }}">
        <div><p class="eyebrow">Reporting &amp; Revenue</p><span class="workspace-launch-value">{{ $reporting['currency'] }} {{ \App\Support\Money::formatMinor((int) $reporting['gross_revenue_minor']) }}</span><p>Current finalized gross revenue · {{ number_format((int) $reporting['managed_impressions']) }} impressions.</p></div>
        <span class="workspace-launch-action">Open reporting →</span>
    </a>
    @endif
    @if(auth()->user()->hasPermission('finance.operations.view'))
    <a class="workspace-launch-card" href="{{ route('admin.finance.overview') }}">
        <div><p class="eyebrow">Finance</p><span class="workspace-launch-value">{{ $reporting ? $reporting['currency'].' '.\App\Support\Money::formatMinor((int) $reporting['outstanding_publisher_payments_minor']) : 'Open' }}</span><p>Publisher liabilities, statements, payout readiness and reconciliation.</p></div>
        <span class="workspace-launch-action">Open finance →</span>
    </a>
    @endif
</section>

@php
    $metrics = collect([
        ['Advertisers', $totalAdvertisers],
        ['Active campaigns', $activeCampaigns],
        ['Horus margin', $reporting && $showInternalMargin ? $reporting['currency'].' '.\App\Support\Money::formatMinor((int) $reporting['horus_margin_minor']) : null],
        ['Publisher payable', $reporting ? $reporting['currency'].' '.\App\Support\Money::formatMinor((int) $reporting['outstanding_publisher_payments_minor']) : null],
    ])->filter(fn ($metric) => $metric[1] !== null);
@endphp
@if($metrics->isNotEmpty())
<div class="dashboard-section-heading"><div><p class="eyebrow">Platform snapshot</p><h2>At a glance</h2></div></div>
<section class="metric-grid" aria-label="Platform summary">
    @foreach($metrics as [$label, $value])
        <article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong></article>
    @endforeach
</section>
@endif

@if($aiSettings)
@php($aiConnection = $aiConnections->get($aiSettings->active_provider))
<section class="ai-dashboard-card" aria-labelledby="ai-control-center-heading">
    <div>
        <p class="eyebrow">AI &amp; Automation</p>
        <h2 id="ai-control-center-heading">THOTH AI Control Center</h2>
        <p>Publisher quality advisories remain separate from human approval and finance.</p>
    </div>
    <div class="ai-dashboard-status">
        <x-status-badge :status="$aiSettings->enabled && $aiConnection?->isReady() ? 'READY' : 'SETUP REQUIRED'" />
        <span>{{ $aiSettings->active_provider }} · {{ $aiConnection?->readiness() ?? 'CREDENTIAL_MISSING' }}</span>
        <a class="hm-button-secondary" href="{{ route('admin.thoth.settings') }}">AI settings</a>
    </div>
</section>
@endif

@if(auth()->user()->hasPermission('audit.view'))
<article class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Governance</p><h2>Recent audit activity</h2></div><a class="section-anchor" href="{{ route('admin.audit.index') }}">Full audit log →</a></div>
    @forelse($auditEvents->take(6) as $event)
        <div class="compact-row"><div><strong>{{ str($event->event)->replace('.', ' ')->headline() }}</strong></div><span class="muted">{{ $event->created_at }}</span></div>
    @empty
        <p class="muted">No audit events yet.</p>
    @endforelse
</article>
@endif
@endsection
