@extends('layouts.admin')
@section('title', 'Admin home')
@section('heading', 'Horus Media')
@section('content')
<section class="hero admin-home-hero">
    <div>
        <p class="eyebrow">Admin workspace</p>
        <h2>Run the network from one place.</h2>
        <p>Start with anything that needs attention, then jump directly to Publishers, Websites, monetization, reports, or Finance.</p>
        <div class="button-row">
            @if(auth()->user()->hasPermission('publishers.view'))<a class="hm-button-primary button-link" href="{{ route('admin.publishers.index') }}">Publishers</a>@endif
            @if(auth()->user()->hasPermission('sites.view'))<a class="hm-button-secondary button-link" href="{{ route('admin.sites.index') }}">Websites</a>@endif
            @if(auth()->user()->hasPermission('demand.manage'))<a class="hm-button-secondary button-link" href="{{ route('admin.demand.quick.create') }}">Quick Monetize</a>@endif
            @if(auth()->user()->hasPermission('reporting.admin.view'))<a class="section-anchor" href="{{ route('admin.reporting.index') }}">Reports →</a>@endif
            @if(auth()->user()->hasPermission('finance.operations.view'))<a class="section-anchor" href="{{ route('admin.finance.overview') }}">Finance →</a>@endif
        </div>
    </div>
    @if($reporting)
        <div class="hero-stat"><span>Reporting currency</span><strong>USD</strong><small>Canonical platform currency</small></div>
    @endif
</section>

<section class="action-center" aria-labelledby="action-center-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Start here</p><h2 id="action-center-heading">What needs attention</h2></div>
        <x-status-badge :status="$actionItems === [] ? 'HEALTHY' : 'PENDING'" />
    </div>
    @if($actionItems === [])
        <article class="next-step-clear"><h3>No urgent work</h3><p class="muted">The workflows visible to your role have no unresolved conditions.</p></article>
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
        ['Publishers', $totalPublishers, 'Publisher accounts'],
        ['Websites', $totalWebsites, 'Managed inventory'],
        ['Managed impressions', $reporting ? number_format($reporting['managed_impressions']) : null, 'Finalized · USD ledger'],
        ['Gross revenue', $reporting ? 'USD '.\App\Support\Money::formatMinor((int) $reporting['gross_revenue_minor']) : null, 'Finalized reporting'],
        ['Publisher payable', $reporting ? 'USD '.\App\Support\Money::formatMinor((int) $reporting['outstanding_publisher_payments_minor']) : null, 'Latest finalized statements'],
        ['Horus margin', $reporting && $showInternalMargin ? 'USD '.\App\Support\Money::formatMinor((int) $reporting['horus_margin_minor']) : null, 'Internal only'],
    ])->filter(fn ($metric) => $metric[1] !== null);
@endphp
@if($metrics->isNotEmpty())
<section class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Network snapshot</p><h2>Key numbers</h2></div>@if($reporting)<a class="section-anchor" href="{{ route('admin.reporting.index') }}">Open reports →</a>@endif</div>
    <div class="metric-grid admin-kpis">
        @foreach($metrics as [$label, $value, $note])
            <article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong><span class="muted">{{ $note }}</span></article>
        @endforeach
    </div>
</section>
@endif

<section class="split-grid workspace-section">
    <article>
        <p class="eyebrow">Common tasks</p><h2>Fast paths</h2>
        <div class="quick-link-grid">
            @if(auth()->user()->hasPermission('publishers.view'))<a href="{{ route('admin.publishers.index') }}"><strong>Publisher accounts</strong><span>Review publishers and open Publisher 360</span></a>@endif
            @if(auth()->user()->hasPermission('sites.view'))<a href="{{ route('admin.sites.index') }}"><strong>Websites</strong><span>Activation, reporting and site controls</span></a>@endif
            @if(auth()->user()->hasPermission('demand.manage'))<a href="{{ route('admin.demand.quick.create') }}"><strong>Quick Monetize</strong><span>Add or update monetization without digging into Advanced setup</span></a>@endif
            @if(auth()->user()->hasPermission('finance.operations.view'))<a href="{{ route('admin.finance.overview') }}"><strong>Finance</strong><span>Statements, readiness and payouts</span></a>@endif
        </div>
    </article>

    @if(auth()->user()->hasPermission('audit.view'))
    <article>
        <div class="workspace-heading"><div><p class="eyebrow">Governance</p><h2>Recent activity</h2></div><a class="section-anchor" href="{{ route('admin.audit.index') }}">Audit log →</a></div>
        @forelse($auditEvents->take(6) as $event)
            <div class="event"><strong>{{ str($event->event)->replace('.', ' ')->headline() }}</strong><span>{{ $event->created_at }}</span></div>
        @empty
            <p class="muted">No audit events yet.</p>
        @endforelse
    </article>
    @endif
</section>

@if($aiSettings)
@php($aiConnection = $aiConnections->get($aiSettings->active_provider))
<section class="workspace-section secondary-workflow">
    <article class="ai-dashboard-card" aria-labelledby="ai-control-center-heading">
        <div>
            <p class="eyebrow">AI &amp; Automation</p>
            <h2 id="ai-control-center-heading">THOTH AI</h2>
            <p>Quality-review automation is available here when you need it; it no longer competes with daily publisher and revenue work.</p>
        </div>
        <div class="ai-dashboard-status">
            <x-status-badge :status="$aiSettings->enabled && $aiConnection?->isReady() ? 'READY' : 'SETUP REQUIRED'" />
            <a class="hm-button-secondary" href="{{ route('admin.thoth.settings') }}">Open AI settings</a>
        </div>
    </article>
</section>
@endif
@endsection
