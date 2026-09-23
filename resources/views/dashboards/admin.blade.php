@extends('layouts.admin')
@section('title', 'Horus Admin Home')
@section('heading', 'Horus Admin Home')
@section('content')
<section class="hero dashboard-hero">
    <div>
        <p class="eyebrow">Operations overview</p>
        <h2>Run Horus Media from one starting point.</h2>
        <p>Resolve urgent work first, then jump directly to publishers, websites, monetization, reporting, or finance without hunting through the sidebar.</p>
    </div>
    @if($reporting)
        <div class="hero-stat"><span>Reporting currency</span><strong>{{ $reporting['currency'] }}</strong><small>Canonical dashboard currency</small></div>
    @endif
</section>

<nav class="dashboard-shortcuts" aria-label="Administrator quick actions">
    @if(auth()->user()->hasPermission('publishers.view'))
        <a href="{{ route('admin.publishers.index') }}"><span class="shortcut-icon" aria-hidden="true">P</span><strong>Publishers</strong><small>Accounts, websites and commercial state</small></a>
    @endif
    @if(auth()->user()->hasPermission('sites.view'))
        <a href="{{ route('admin.sites.index') }}"><span class="shortcut-icon" aria-hidden="true">◎</span><strong>Websites</strong><small>Site 360, reporting and serving status</small></a>
    @endif
    @if(auth()->user()->hasPermission('demand.manage'))
        <a href="{{ route('admin.demand.quick.create') }}"><span class="shortcut-icon" aria-hidden="true">+</span><strong>Quick Monetize</strong><small>Launch or update publisher ad surfaces</small></a>
    @endif
    @if(auth()->user()->hasPermission('reporting.admin.view'))
        <a href="{{ route('admin.reporting.index') }}"><span class="shortcut-icon" aria-hidden="true">↗</span><strong>Reporting</strong><small>USD performance and source health</small></a>
    @endif
    @if(auth()->user()->hasPermission('finance.operations.view'))
        <a href="{{ route('admin.finance.overview') }}"><span class="shortcut-icon" aria-hidden="true">$</span><strong>Finance</strong><small>Statements, balances and payout readiness</small></a>
    @endif
</nav>

<section class="action-center" aria-labelledby="action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Priority queue</p><h2 id="action-center-heading">What needs attention</h2></div>
        <x-status-badge :status="$actionItems === [] ? 'HEALTHY' : 'PENDING'" />
    </div>
    @if($actionItems === [])
        <div class="dashboard-empty-success"><strong>No urgent actions.</strong><span>All workflows visible to your role are currently clear.</span></div>
    @else
        <div class="action-center-grid">
            @foreach($actionItems as $item)
                <a href="{{ route($item['route'], $item['parameters']) }}" class="action-card action-card-{{ $item['severity'] }}">
                    <span class="action-count">{{ $item['count'] }}</span>
                    <div><h3>{{ $item['label'] }}</h3><p>{{ $item['description'] }}</p><span class="section-anchor">Open →</span></div>
                </a>
            @endforeach
        </div>
    @endif
</section>

@php
    $metrics = collect([
        ['Publishers', $totalPublishers, 'Active and onboarding accounts'],
        ['Websites', $totalWebsites, 'Managed publisher websites'],
        ['Advertisers', $totalAdvertisers, 'Advertiser accounts'],
        ['Active campaigns', $activeCampaigns, 'Scheduled, active or paused'],
        ['Managed impressions', $reporting ? number_format($reporting['managed_impressions']) : null, 'Finalized reporting'],
        ['Gross revenue', $reporting ? $reporting['currency'].' '.\App\Support\Money::formatMinor((int) $reporting['gross_revenue_minor']) : null, 'Canonical USD ledger'],
        ['Publisher payable', $reporting ? $reporting['currency'].' '.\App\Support\Money::formatMinor((int) $reporting['outstanding_publisher_payments_minor']) : null, 'Latest finalized balances'],
        ['Horus margin', $reporting && $showInternalMargin ? $reporting['currency'].' '.\App\Support\Money::formatMinor((int) $reporting['horus_margin_minor']) : null, 'Internal only'],
    ])->filter(fn ($metric) => $metric[1] !== null);
@endphp
@if($metrics->isNotEmpty())
<section class="dashboard-metrics dashboard-metrics-admin" aria-label="Platform summary">
    @foreach($metrics as [$label, $value, $note])
        <article class="dashboard-metric-card"><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong><span class="muted">{{ $note }}</span></article>
    @endforeach
</section>
@endif

<section class="dashboard-two-column">
    @if($reporting)
    <article class="workspace-section">
        <div class="workspace-heading">
            <div><p class="eyebrow">Reporting</p><h2>USD source of truth</h2></div>
            <a class="section-anchor" href="{{ route('admin.reporting.index') }}">Open reporting →</a>
        </div>
        <p class="muted">Google Ad Manager website reporting is normalized by requesting USD directly from Google. Source-network currency remains metadata, not a dashboard currency.</p>
        <div class="summary-grid">
            <div><strong>Publisher earnings</strong><span>{{ $reporting['currency'] }} {{ \App\Support\Money::formatMinor((int) $reporting['publisher_earnings_minor']) }}</span></div>
            @if($showInternalMargin)<div><strong>Horus margin</strong><span>{{ $reporting['currency'] }} {{ \App\Support\Money::formatMinor((int) $reporting['horus_margin_minor']) }}</span></div>@endif
        </div>
    </article>
    @endif

    @if(auth()->user()->hasPermission('audit.view'))
    <article class="workspace-section">
        <div class="workspace-heading"><div><p class="eyebrow">Governance</p><h2>Recent activity</h2></div><a class="section-anchor" href="{{ route('admin.audit.index') }}">Audit log →</a></div>
        <div class="compact-list">
            @forelse($auditEvents->take(6) as $event)
                <div class="compact-row"><div><strong>{{ str($event->event)->replace('.', ' ')->headline() }}</strong><p>{{ $event->auditable_type ? class_basename($event->auditable_type) : 'Platform' }}</p></div><span class="muted">{{ $event->created_at->diffForHumans() }}</span></div>
            @empty
                <p class="muted">No audit events yet.</p>
            @endforelse
        </div>
    </article>
    @endif
</section>

@if($aiSettings)
@php($aiConnection = $aiConnections->get($aiSettings->active_provider))
<article class="workspace-section ai-dashboard-card" aria-labelledby="ai-control-center-heading">
    <div>
        <p class="eyebrow">AI & Automation</p>
        <h2 id="ai-control-center-heading">THOTH AI Control Center</h2>
        <p>Publisher quality advisories remain secondary to the human review workflow.</p>
    </div>
    <div class="ai-dashboard-status">
        <x-status-badge :status="$aiSettings->enabled && $aiConnection?->isReady() ? 'READY' : 'SETUP REQUIRED'" />
        <a class="hm-button-secondary button-link" href="{{ route('admin.thoth.settings') }}">AI settings</a>
    </div>
</article>
@endif
@endsection
