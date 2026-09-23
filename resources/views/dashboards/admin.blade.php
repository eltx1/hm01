@extends('layouts.admin')
@section('title', 'Administrator Action Center')
@section('heading', 'Horus Media Action Center')
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Horus Admin</p>
        <h2>Run the platform from one starting point.</h2>
        <p>Handle urgent work first, then jump directly to publishers, websites, monetization, reporting, or finance without hunting through the control plane.</p>
        <div class="status-row">
            @if(auth()->user()->hasPermission('publishers.view'))<a class="hm-button-primary button-link" href="{{ route('admin.publishers.index') }}">Publishers</a>@endif
            @if(auth()->user()->hasPermission('sites.view'))<a class="hm-button-secondary button-link" href="{{ route('admin.sites.index') }}">Websites</a>@endif
            @if(auth()->user()->hasPermission('demand.manage'))<a class="hm-button-secondary button-link" href="{{ route('admin.demand.quick.create') }}">Quick Monetize</a>@endif
            @if(auth()->user()->hasPermission('reporting.admin.view'))<a class="hm-button-secondary button-link" href="{{ route('admin.reporting.index') }}">Reporting</a>@endif
        </div>
    </div>
    @if($reporting)<span class="canonical-currency-note">Reporting currency · {{ $reporting['currency'] }}</span>@endif
</section>

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



@php
    $primaryMetrics = collect([
        ['Publishers', $totalPublishers],
        ['Websites', $totalWebsites],
        ['Managed impressions', $reporting ? number_format($reporting['managed_impressions']) : null],
        ['Gross revenue', $reporting ? $reporting['currency'].' '.\App\Support\Money::formatMinor((int) $reporting['gross_revenue_minor']) : null],
    ])->filter(fn ($metric) => $metric[1] !== null);
@endphp
@if($primaryMetrics->isNotEmpty())
<div class="dashboard-section-title"><p class="eyebrow">Platform snapshot</p><h2>What matters now</h2></div>
<section class="dashboard-primary" aria-label="Platform summary">
    @foreach($primaryMetrics as [$label, $value])
        <article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong></article>
    @endforeach
</section>
@endif

<div class="dashboard-section-title"><p class="eyebrow">Common workflows</p><h2>Go straight to the work</h2></div>
<section class="destination-grid" aria-label="Administrator destinations">
    @if(auth()->user()->hasPermission('publishers.view'))
    <a class="destination-card" href="{{ route('admin.publishers.index') }}"><strong>Publishers &amp; websites</strong><span>Review accounts, site status, verification, and publisher-level operations.</span><span class="section-anchor">Open publishers →</span></a>
    @endif
    @if(auth()->user()->hasPermission('demand.manage'))
    <a class="destination-card" href="{{ route('admin.demand.quick.create') }}"><strong>Monetization</strong><span>Launch Quick Monetize or continue into advanced demand and GAM configuration.</span><span class="section-anchor">Open Quick Monetize →</span></a>
    @endif
    @if(auth()->user()->hasPermission('reporting.admin.view'))
    <a class="destination-card" href="{{ route('admin.reporting.index') }}"><strong>Reporting &amp; finance</strong><span>Review canonical USD reporting, imports, source health, revenue, and payout readiness.</span><span class="section-anchor">Open reporting →</span></a>
    @endif
</section>

<section class="metric-grid" aria-label="Secondary platform metrics">
    @if($totalAdvertisers !== null)<article><p class="eyebrow">Advertisers</p><strong class="metric-small">{{ $totalAdvertisers }}</strong></article>@endif
    @if($activeCampaigns !== null)<article><p class="eyebrow">Active campaigns</p><strong class="metric-small">{{ $activeCampaigns }}</strong></article>@endif
    @if($reporting && $showInternalMargin)<article><p class="eyebrow">Horus margin</p><strong class="metric-small">{{ $reporting['currency'] }} {{ \App\Support\Money::formatMinor((int) $reporting['horus_margin_minor']) }}</strong></article>@endif
    @if($reporting)<article><p class="eyebrow">Publisher payable</p><strong class="metric-small">{{ $reporting['currency'] }} {{ \App\Support\Money::formatMinor((int) $reporting['outstanding_publisher_payments_minor']) }}</strong></article>@endif
</section>

<section class="split-grid">
    @if($reporting)
    <article>
        <p class="eyebrow">Aggregated ledger</p><h2>Unified reporting</h2>
        <p class="muted">Horus GAM and approved optional sources are normalized into one aggregated reporting ledger.</p>
        <a class="hm-button-primary button-link" href="{{ route('admin.reporting.index') }}">Open reporting</a>
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
