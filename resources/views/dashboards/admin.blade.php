@extends('layouts.admin')
@section('title', 'Dashboard')
@section('heading', 'Admin dashboard')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Admin home</p>
        <h2>Run Horus Media from one starting point.</h2>
        <p>Open the workflow you need, then use Action Center for anything that requires attention.</p>
    </div>
</section>

<section class="workspace-section" aria-labelledby="admin-start-heading">
    <div class="workspace-heading"><div><p class="eyebrow">Common tasks</p><h2 id="admin-start-heading">Where do you want to go?</h2></div></div>
    <div class="task-launcher">
        @if(auth()->user()->hasPermission('publishers.view'))
        <a class="task-card" href="{{ route('admin.publishers.index') }}"><strong>Publishers</strong><span>Review accounts, onboarding and commercial relationships.</span><b>Open publishers →</b></a>
        @endif
        @if(auth()->user()->hasPermission('sites.view'))
        <a class="task-card" href="{{ route('admin.sites.index') }}"><strong>Websites</strong><span>Manage sites, serving, reports and installation.</span><b>Open websites →</b></a>
        @endif
        @if(auth()->user()->hasPermission('reporting.admin.view'))
        <a class="task-card" href="{{ route('admin.reporting.index') }}"><strong>Reports</strong><span>Review unified USD reporting and source health.</span><b>Open reports →</b></a>
        @endif
        @if(auth()->user()->hasPermission('finance.operations.view'))
        <a class="task-card" href="{{ route('admin.finance.overview') }}"><strong>Finance</strong><span>Statements, payouts, close readiness and reconciliation.</span><b>Open finance →</b></a>
        @endif
        @if(auth()->user()->hasPermission('demand.view'))
        <a class="task-card" href="{{ route('admin.demand.index') }}"><strong>Direct Demand</strong><span>Provider accounts, Quick Monetize and demand mappings.</span><b>Open monetization →</b></a>
        @endif
        @if(auth()->user()->hasPermission('operations.view'))
        <a class="task-card" href="{{ route('admin.operations.index') }}"><strong>Production</strong><span>Delivery, runtime health and operational controls.</span><b>Open production →</b></a>
        @endif
    </div>
</section>

<section class="action-center" aria-labelledby="action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Needs attention</p><h2 id="action-center-heading">Action Center</h2></div>
        <x-status-badge :status="$actionItems === [] ? 'HEALTHY' : 'PENDING'" />
    </div>
    @if($actionItems === [])
        <article class="calm-state"><h3>No current action items</h3><p class="muted">The workflows available to your role have no unresolved conditions.</p></article>
    @else
        <div class="action-center-grid">
            @foreach($actionItems as $item)
                <a href="{{ route($item['route'], $item['parameters']) }}" class="action-card action-card-{{ $item['severity'] }}">
                    <span class="action-count">{{ $item['count'] }}</span>
                    <div><h3>{{ $item['label'] }}</h3><p>{{ $item['description'] }}</p><span class="section-anchor">Review →</span></div>
                </a>
            @endforeach
        </div>
    @endif
</section>

@php
    $metrics = collect([
        ['Total publishers', $totalPublishers],
        ['Total websites', $totalWebsites],
        ['Managed impressions', $reporting ? number_format($reporting['managed_impressions']) : null],
        ['Gross revenue · '.($reporting['currency'] ?? 'USD'), $reporting ? \App\Support\Money::formatMinor((int) $reporting['gross_revenue_minor']).' '.$reporting['currency'] : null],
        ['Horus margin · '.($reporting['currency'] ?? 'USD'), $reporting && $showInternalMargin ? \App\Support\Money::formatMinor((int) $reporting['horus_margin_minor']).' '.$reporting['currency'] : null],
        ['Publisher payable · '.($reporting['currency'] ?? 'USD'), $reporting ? \App\Support\Money::formatMinor((int) $reporting['outstanding_publisher_payments_minor']).' '.$reporting['currency'] : null],
        ['Total advertisers', $totalAdvertisers],
        ['Active campaigns', $activeCampaigns],
    ])->filter(fn ($metric) => $metric[1] !== null);
@endphp
@if($metrics->isNotEmpty())
<section class="metric-grid" aria-label="Platform summary">
    @foreach($metrics as [$label, $value])
        <article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong></article>
    @endforeach
</section>
@endif

<section class="split-grid">
    @if($reporting)
    <article>
        <p class="eyebrow">Reporting</p><h2>Unified USD ledger</h2>
        <p class="muted">Google Ad Manager and approved sources feed one financial reporting workflow. GAM reporting is standardized to USD.</p>
        <a class="hm-button-primary button-link" href="{{ route('admin.reporting.index') }}">Open reports</a>
    </article>
    @endif
    @if(auth()->user()->hasPermission('audit.view'))
    <article>
        <p class="eyebrow">Governance</p><h2>Recent audit events</h2>
        @forelse($auditEvents as $event)
            <div class="event"><strong>{{ str($event->event)->replace('.', ' ')->headline() }}</strong><span>{{ $event->created_at }}</span></div>
        @empty
            <p class="muted">No audit events yet.</p>
        @endforelse
    </article>
    @endif
</section>

@if($aiSettings)
@php($aiConnection = $aiConnections->get($aiSettings->active_provider))
<section class="ai-dashboard-card" aria-labelledby="ai-control-center-heading">
    <div>
        <p class="eyebrow">AI &amp; Automation</p>
        <h2 id="ai-control-center-heading">THOTH AI</h2>
        <p>AI quality advisories are a supporting workflow, not the starting point for day-to-day operations.</p>
    </div>
    <div class="ai-dashboard-status">
        <x-status-badge :status="$aiSettings->enabled && $aiConnection?->isReady() ? 'READY' : 'SETUP REQUIRED'" />
        <span>{{ $aiSettings->active_provider }} · {{ $aiConnection?->readiness() ?? 'CREDENTIAL_MISSING' }}</span>
        <a class="hm-button-secondary button-link" href="{{ route('admin.thoth.settings') }}">AI settings</a>
    </div>
</section>
@endif
@endsection
