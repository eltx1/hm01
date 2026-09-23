@extends('layouts.admin')
@section('title', 'Administrator Action Center')
@section('heading', 'Horus Media Action Center')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Operational command surface</p>
        <h2>Control what needs attention now.</h2>
        <p>Review live workflow conditions across publisher onboarding, websites, integrations, reporting, finance, campaigns, and production delivery.</p>
    </div>
</section>

<section class="workspace-section">
    <div class="workspace-heading">
        <div><p class="eyebrow">Go straight to the work</p><h2>Common admin tasks</h2></div>
    </div>
    <div class="shortcut-grid">
        @if(auth()->user()->hasPermission('publishers.view'))
            <a class="shortcut-card" href="{{ route('admin.publishers.index') }}"><strong>Publishers</strong><span>Review accounts, terms and publisher status</span></a>
        @endif
        @if(auth()->user()->hasPermission('sites.view'))
            <a class="shortcut-card" href="{{ route('admin.sites.index') }}"><strong>Websites</strong><span>Open Site 360, reporting and serving controls</span></a>
        @endif
        @if(auth()->user()->hasPermission('demand.manage'))
            <a class="shortcut-card" href="{{ route('admin.demand.quick.create') }}"><strong>Quick Monetize</strong><span>Connect and publish ad formats quickly</span></a>
        @endif
        @if(auth()->user()->hasPermission('reporting.admin.view'))
            <a class="shortcut-card" href="{{ route('admin.reporting.index') }}"><strong>Reporting</strong><span>Performance, sources and data health</span></a>
        @endif
        @if(auth()->user()->hasPermission('finance.operations.view'))
            <a class="shortcut-card" href="{{ route('admin.finance.overview') }}"><strong>Finance</strong><span>Statements, balances and payout readiness</span></a>
        @endif
    </div>
</section>

@if($aiSettings)
@php
    $aiConnection = $aiConnections->get($aiSettings->active_provider);
@endphp
<section class="ai-dashboard-card" aria-labelledby="ai-control-center-heading">
    <div>
        <p class="eyebrow">AI &amp; Automation</p>
        <h2 id="ai-control-center-heading">THOTH AI Control Center</h2>
        <p>Configure Gemini or OpenAI, store the API key securely, test the real connection, then activate Publisher quality advisories.</p>
    </div>
    <div class="ai-dashboard-status">
        <x-status-badge :status="$aiSettings->enabled && $aiConnection?->isReady() ? 'READY' : 'SETUP REQUIRED'" />
        <span>{{ $aiSettings->active_provider }} · {{ $aiConnection?->readiness() ?? 'CREDENTIAL_MISSING' }}</span>
        <a class="hm-button-primary" href="{{ route('admin.thoth.settings') }}">Configure AI</a>
    </div>
</section>
@endif

<section class="action-center" aria-labelledby="action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Prioritized work</p><h2 id="action-center-heading">Action Center</h2></div>
        <x-status-badge :status="$actionItems === [] ? 'HEALTHY' : 'PENDING'" />
    </div>
    @if($actionItems === [])
        <article><h3>No current action items</h3><p class="muted">The workflows visible to your role have no unresolved conditions.</p></article>
    @else
        <div class="action-center-grid">
            @foreach($actionItems as $item)
                <a href="{{ route($item['route'], $item['parameters']) }}" class="action-card action-card-{{ $item['severity'] }}">
                    <span class="action-count">{{ $item['count'] }}</span>
                    <div><h3>{{ $item['label'] }}</h3><p>{{ $item['description'] }}</p><span class="section-anchor">Open remediation →</span></div>
                </a>
            @endforeach
        </div>
    @endif
</section>

@php
    $metrics = collect([
        ['Total publishers', $totalPublishers],
        ['Total websites', $totalWebsites],
        ['Total advertisers', $totalAdvertisers],
        ['Active campaigns', $activeCampaigns],
        ['Managed impressions', $reporting ? number_format($reporting['managed_impressions']) : null],
        ['Gross revenue', $reporting ? \App\Support\Money::formatMinor((int) $reporting['gross_revenue_minor']).' '.$reporting['currency'] : null],
        ['Horus margin', $reporting && $showInternalMargin ? \App\Support\Money::formatMinor((int) $reporting['horus_margin_minor']).' '.$reporting['currency'] : null],
        ['Publisher payable', $reporting ? \App\Support\Money::formatMinor((int) $reporting['outstanding_publisher_payments_minor']).' '.$reporting['currency'] : null],
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
        <p class="eyebrow">Aggregated ledger</p><h2>Unified reporting</h2>
        <p class="muted">Horus GAM and approved optional sources are normalized into one aggregated reporting ledger.</p>
        <a class="hm-button-primary button-link" href="{{ route('admin.reporting.index') }}">Open reporting sources</a>
    </article>
    @endif
    @if(auth()->user()->hasPermission('audit.view'))
    <article>
        <p class="eyebrow">Governance evidence</p><h2>Recent audit events</h2>
        @forelse($auditEvents as $event)
            <div class="event"><strong>{{ str($event->event)->replace('.', ' ')->headline() }}</strong><span>{{ $event->created_at }}</span></div>
        @empty
            <p class="muted">No audit events yet.</p>
        @endforelse
    </article>
    @endif
</section>
@endsection
