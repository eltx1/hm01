@extends('layouts.admin')
@section('title', $site->display_name)
@section('heading', $internal ? 'Site 360' : $site->display_name)
@section('content')
@if($internal)
@php
    $tabs = [
        ['label' => 'Overview', 'href' => '#overview'],
        ['label' => 'Monetization', 'href' => '#monetization-health'],
        ['label' => 'Inventory', 'href' => '#inventory', 'visible' => auth()->user()->hasPermission('inventory.view')],
        ['label' => 'Serving', 'href' => '#serving'],
        ['label' => 'GAM', 'href' => '#gam'],
        ['label' => 'Prebid', 'href' => '#prebid'],
        ['label' => 'Direct Monetization', 'href' => '#native-demand'],
        ['label' => 'Configuration', 'href' => '#configuration'],
        ['label' => 'Compliance', 'href' => '#compliance'],
        ['label' => 'Health', 'href' => '#health'],
        ['label' => 'History', 'href' => '#history'],
    ];
    $productionVersion = $site->siteConfig?->versions?->where('environment', \App\Enums\ConfigEnvironment::Production)->sortByDesc('version')->first();
    $latestProbe = $site->syntheticProbeResults->sortByDesc('observed_at')->first();
    $moduleHealth = collect($monetization['modules'])->keyBy('key');
@endphp
<x-control-plane.workspace-tabs :items="$tabs" label="Site 360 sections" />

<section id="overview" class="hero workspace-section">
    <div>
        <p class="eyebrow">{{ $site->publisher->display_name }} · Website</p><h2>{{ $site->display_name }}</h2>
        <p>{{ $site->primary_domain }} · {{ $site->language }}/{{ $site->country }} · {{ $site->content_category }}</p>
        <div class="status-row"><x-status-badge :status="$site->status" /><x-status-badge :status="$site->serving_mode" /><x-status-badge :status="$monetization['overall']['status']" /></div>
        <a class="hm-button-secondary button-link" href="{{ route('admin.publishers.show', $site->publisher) }}">Open Publisher 360</a>
    </div>
</section>

<section class="metric-grid" aria-label="Site summary">
    <article><p class="eyebrow">Ad units</p><strong class="metric">{{ $site->adUnits->count() }}</strong></article>
    <article><p class="eyebrow">Placements</p><strong class="metric">{{ $site->placements->count() }}</strong></article>
    <article><p class="eyebrow">Authorized domains</p><strong class="metric">{{ $site->domains->count() }}</strong></article>
    <article><p class="eyebrow">Prebid mappings</p><strong class="metric">{{ $site->bidderSiteMappings->count() }}</strong></article>
    <article><p class="eyebrow">Native mappings</p><strong class="metric">{{ $site->demandSites->count() }}</strong></article>
</section>

@include('publisher.monetization.site-health')

@if(auth()->user()->hasPermission('inventory.view'))
<article id="inventory" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Inventory lifecycle</p><h2>Ad units and placements</h2></div><a class="hm-button-primary button-link" href="{{ route('admin.sites.inventory.index', $site) }}">Manage inventory</a></div>
    <div class="health-grid"><div><span class="muted">Ad units</span><strong class="metric-small">{{ $site->adUnits->count() }}</strong></div><div><span class="muted">Placements</span><strong class="metric-small">{{ $site->placements->count() }}</strong></div><div><span class="muted">Estimated pageviews</span><strong class="metric-small">{{ number_format($site->estimated_monthly_pageviews) }}</strong></div></div>
</article>
@endif

@include('admin.sites.serving-control-center')

<section id="serving" class="detail-grid workspace-section">
    <article>
        <p class="eyebrow">Publisher installation</p><h2>One permanent loader</h2>
        <p class="muted">Serving mode and demand configuration can change without asking the publisher to replace this code.</p>
        <code class="installation-code">{{ $site->installationCode() }}</code>
        <dl><dt>Serving mode</dt><dd>{{ $site->serving_mode->value }}</dd><dt>Revenue share</dt><dd>{{ $site->default_revenue_share_percent }}%</dd><dt>Immediate pause</dt><dd>{{ $site->siteConfig?->immediate_pause ? 'Enabled' : 'Disabled' }}</dd></dl>
    </article>
    <article>
        <p class="eyebrow">Operational controls</p><h2>Serving</h2>
        @if(auth()->user()->hasPermission('sites.serving.manage'))
            <form method="POST" action="{{ route('admin.sites.serving-mode', $site) }}" class="form-stack">@csrf<label>Serving mode<select class="hm-input" name="serving_mode">@foreach(\App\Enums\ServingMode::cases() as $mode)<option value="{{ $mode->value }}" @selected($site->serving_mode === $mode)>{{ $mode->value }}</option>@endforeach</select></label><label>Required reason<input class="hm-input" name="reason" required></label><button class="hm-button-secondary">Change serving mode</button></form>
            <form method="POST" action="{{ route('admin.sites.revenue-share', $site) }}" class="form-stack">@csrf<label>Revenue share percent<input class="hm-input" type="number" min="0" max="100" step=".01" name="revenue_share_percent" value="{{ $site->default_revenue_share_percent }}" required></label><label>Required reason<input class="hm-input" name="reason" required></label><button class="hm-button-secondary">Change revenue share</button></form>
            <form method="POST" action="{{ route('admin.sites.emergency-pause', $site) }}" class="form-stack danger-zone">@csrf<label>Emergency reason<input class="hm-input" name="reason" required></label><button class="hm-button-danger">Emergency pause</button></form>
        @else<p class="muted">Your role has read-only serving access.</p>@endif
    </article>
</section>

<article id="gam" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Google Ad Manager</p><h2>GAM routing</h2></div>@if($site->gamConnection && auth()->user()->hasPermission('gam.connections.view'))<a class="section-anchor" href="{{ route('admin.gam.connections.show', $site->gamConnection) }}">Connection details</a>@endif</div>
    @if($site->gamConnection)
        <div class="compact-row"><div><strong>{{ $site->gamConnection->name }}</strong><p>Network {{ $site->gamConnection->network_code }} · {{ $site->gamConnection->driver }}</p></div><x-status-badge :status="$site->gamConnection->health_status" /></div>
    @else
        <p class="muted">The resolver will use the eligible primary Horus GAM connection when this site is activated. Resolved connection truth is shown in Monetization diagnostics above.</p>
    @endif
</article>

<article id="prebid" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Header bidding</p><h2>Prebid</h2></div>@if(auth()->user()->hasPermission('inventory.view'))<a class="section-anchor" href="{{ route('admin.sites.prebid.index', $site) }}">Manage Prebid</a>@endif</div>
    <div class="compact-row"><div><strong>{{ $site->prebid_enabled ? 'Enabled for this website' : 'Disabled for this website' }}</strong><p>{{ $site->bidderSiteMappings->where('enabled', true)->count() }} enabled bidder mappings; line-item setup remains centrally managed.</p></div><x-status-badge :status="$moduleHealth['prebid']['status']" /></div>
    @foreach($site->bidderSiteMappings as $mapping)<div class="compact-row"><div><strong>{{ $mapping->account->bidder->display_name ?? $mapping->account->name }}</strong><p>{{ $mapping->account->name }} · sequence {{ $mapping->sequence }}</p></div><x-status-badge :status="$mapping->enabled ? 'ACTIVE' : 'DISABLED'" /></div>@endforeach
</article>

<article id="native-demand" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Monetization</p><h2>Direct Monetization</h2></div></div>
    <div class="compact-row"><div><strong>{{ $site->native_demand_enabled ? 'Enabled for this website' : 'Disabled for this website' }}</strong><p>{{ $site->demandSites->where('is_enabled', true)->count() }} active mappings.</p></div><x-status-badge :status="$moduleHealth['native']['status']" /></div>
    @foreach($site->demandSites as $mapping)<div class="compact-row"><div><strong>{{ $mapping->account->network->name ?? $mapping->account->name }}</strong><p>{{ $mapping->account->name }} · {{ $mapping->integration_mode->value }} · sync {{ $mapping->sync_status->value }}</p></div><x-status-badge :status="$mapping->approval_status" /></div>@endforeach
</article>

<article id="configuration" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Static runtime</p><h2>Configuration</h2></div>@if(auth()->user()->hasPermission('inventory.view'))<a class="section-anchor" href="{{ route('admin.sites.inventory.index', $site) }}#configuration">Manage configuration</a>@endif</div>
    <div class="health-grid"><div><span class="muted">Configuration state</span><strong>{{ $site->siteConfig?->status ?: 'Not created' }}</strong></div><div><span class="muted">Production version</span><strong>{{ $productionVersion?->version ?: 'Not published' }}</strong></div><div><span class="muted">Delivery</span><x-status-badge :status="$productionVersion?->deliveryItem?->status?->value ?: 'PENDING'" /></div></div>
</article>

<article id="compliance" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Domain evidence</p><h2>Compliance</h2></div></div>
    <p class="muted">Domain ownership and supply-chain evidence are managed together. @if(auth()->user()->hasPermission('supply_chain.ads_txt.view'))<a class="section-anchor" href="{{ route('admin.compliance.ads-txt.show', $site) }}">Open the Ads.txt Compliance Center</a>@endif</p>
    @foreach($site->domains as $domain)
        <div class="domain-card"><div class="compact-row"><div><strong>{{ $domain->domain }}</strong><p>{{ $domain->is_primary ? 'Primary authorized domain' : 'Authorized domain' }}</p></div><x-status-badge :status="$domain->verification_status" /></div>
        @if(auth()->user()->hasPermission('sites.review'))<form method="POST" action="{{ route('admin.sites.domains.manual-verify', [$site, $domain]) }}" class="inline-form">@csrf<input class="hm-input" name="reason" aria-label="Manual verification evidence" placeholder="Manual verification evidence/reason" required><button class="hm-button-secondary">Verify manually</button></form>@endif</div>
    @endforeach
</article>

<article id="health" class="workspace-section">
    <div class="workspace-heading"><div><p class="eyebrow">Production evidence</p><h2>Health</h2></div>@if(auth()->user()->hasPermission('operations.view'))<a class="section-anchor" href="{{ route('admin.operations.index') }}">Operations</a>@endif</div>
    <div class="health-grid"><div><span class="muted">Latest synthetic probe</span><x-status-badge :status="$latestProbe?->status ?: 'UNKNOWN'" /><small>{{ $latestProbe?->observed_at ?: 'No probe recorded' }}</small></div><div><span class="muted">GAM connection</span><x-status-badge :status="$monetization['diagnostics']['gam_health'] ?: 'UNKNOWN'" /><small>Persisted health only</small></div><div><span class="muted">Static delivery</span><x-status-badge :status="$monetization['diagnostics']['static_delivery_status'] ?: 'PENDING'" /><small>Production v{{ $monetization['diagnostics']['production_config_version'] ?: '—' }}</small></div></div>
</article>

<section id="history" class="detail-grid workspace-section">
    <article>
        <p class="eyebrow">Lifecycle</p><h2>Status and serving history</h2>
        @foreach($site->statusHistory->sortByDesc('created_at') as $item)<div class="event"><strong>{{ $item->previous_status?->value ?? 'CREATED' }} → {{ $item->new_status->value }}</strong><span>{{ $item->created_at }} · {{ $item->reason }}</span></div>@endforeach
        @foreach($site->servingModeChanges->sortByDesc('created_at') as $item)<div class="event"><strong>{{ $item->previous_mode?->value ?? 'NONE' }} → {{ $item->new_mode->value }}</strong><span>{{ $item->created_at }} · {{ $item->reason }}</span></div>@endforeach
    </article>
    <article>
        <p class="eyebrow">Review controls</p><h2>Decision and lifecycle actions</h2>
        @if(auth()->user()->hasPermission('sites.review'))
            @if($site->status === \App\Enums\SiteStatus::PendingReview)
                <form method="POST" action="{{ route('admin.sites.approve', $site) }}" class="form-stack">@csrf<label>Publisher message<textarea class="hm-input" name="publisher_message"></textarea></label><label>Internal reason<textarea class="hm-input" name="internal_reason"></textarea></label><button class="hm-button-primary">Approve website</button></form>
                <form method="POST" action="{{ route('admin.sites.reject', $site) }}" class="form-stack danger-zone">@csrf<label>Publisher explanation<textarea class="hm-input" name="publisher_message" required></textarea></label><label>Internal reason<textarea class="hm-input" name="internal_reason" required></textarea></label><button class="hm-button-danger">Reject website</button></form>
            @elseif($site->status === \App\Enums\SiteStatus::Approved)
                <form method="POST" action="{{ route('admin.sites.activate', $site) }}" class="inline-form">@csrf<input class="hm-input" name="reason" aria-label="Activation note" placeholder="Activation note"><button class="hm-button-primary">Activate</button></form>
                <form method="POST" action="{{ route('admin.sites.suspend', $site) }}" class="inline-form danger-zone">@csrf<input class="hm-input" name="reason" aria-label="Suspension reason" placeholder="Required reason" required><button class="hm-button-danger">Suspend</button></form>
            @elseif($site->status === \App\Enums\SiteStatus::Active)
                <form method="POST" action="{{ route('admin.sites.suspend', $site) }}" class="inline-form danger-zone">@csrf<input class="hm-input" name="reason" aria-label="Suspension reason" placeholder="Required reason" required><button class="hm-button-danger">Suspend</button></form>
            @elseif($site->status === \App\Enums\SiteStatus::Suspended)
                <form method="POST" action="{{ route('admin.sites.reactivate', $site) }}" class="inline-form">@csrf<input class="hm-input" name="reason" aria-label="Reactivation reason" placeholder="Required reason" required><button class="hm-button-primary">Reactivate</button></form>
            @endif
            @if(in_array($site->status, [\App\Enums\SiteStatus::Approved, \App\Enums\SiteStatus::Active, \App\Enums\SiteStatus::Suspended, \App\Enums\SiteStatus::Rejected], true))<form method="POST" action="{{ route('admin.sites.archive', $site) }}" class="inline-form danger-zone">@csrf<input class="hm-input" name="reason" aria-label="Archive reason" placeholder="Archive reason" required><button class="hm-button-danger">Archive website</button></form>@endif
        @endif
    </article>
</section>

@if(auth()->user()->hasPermission('sites.review'))
<article class="workspace-section"><p class="eyebrow">Horus Media only</p><h2>Internal notes</h2><form method="POST" action="{{ route('admin.sites.notes.store', $site) }}" class="inline-form">@csrf<textarea class="hm-input" name="note" aria-label="Internal note" placeholder="Horus Media only" required></textarea><button class="hm-button-secondary">Add note</button></form>@foreach($site->notes->sortByDesc('created_at') as $note)<div class="event"><p>{{ $note->note }}</p><span>{{ $note->created_at }}</span></div>@endforeach</article>
@endif

@if(auth()->user()->hasPermission('audit.view'))
<article class="workspace-section"><p class="eyebrow">Immutable evidence</p><h2>Site audit events</h2>@forelse($auditEvents as $event)<div class="event"><strong>{{ str($event->event)->replace('.', ' ')->headline() }}</strong><span>{{ $event->created_at }}</span></div>@empty<p class="muted">No site-specific audit events.</p>@endforelse</article>
@endif

@else
<section class="hero">
    <div><p class="eyebrow">Publisher website</p><h2>{{ $site->display_name }}</h2><p>{{ $site->primary_domain }}</p><div class="status-row"><x-status-badge :status="$site->status" /><x-status-badge :status="$monetization['overall']['status']" /></div>@if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-secondary button-link" href="{{ route('publisher.sites.edit', $site) }}">Edit website</a>@endif</div>
</section>

@include('publisher.monetization.site-health')

@if(auth()->user()->hasPermission('publisher.ads_txt.view'))<p><a class="hm-button-secondary button-link" href="{{ route('publisher.ads-txt.index') }}">Open Ads.txt &amp; Compliance</a></p>@endif
<section class="detail-grid">
    <article><p class="eyebrow">Website</p><h2>Account details</h2><dl><dt>Language / country</dt><dd>{{ $site->language }} / {{ $site->country }}</dd><dt>Category</dt><dd>{{ $site->content_category }}</dd><dt>Monthly pageviews / users</dt><dd>{{ number_format($site->estimated_monthly_pageviews) }} / {{ number_format($site->estimated_monthly_users) }}</dd></dl></article>
    <article><p class="eyebrow">Permanent installation</p><h2>One loader</h2><p class="muted">This code never changes when serving mode or demand configuration changes.</p><code class="installation-code">{{ $site->installationCode() }}</code><p>Installation status: <strong>{{ $site->status === \App\Enums\SiteStatus::Active ? 'Active' : 'Configuration pending' }}</strong></p></article>
</section>
<article><div class="section-heading"><div><p class="eyebrow">Authorized domains</p><h2>Ownership verification</h2></div></div>
    @foreach($site->domains as $domain)<div class="domain-card"><div class="compact-row"><div><strong>{{ $domain->domain }}</strong><p>{{ $domain->is_primary ? 'Primary authorized domain' : 'Authorized domain' }}</p></div><x-status-badge :status="$domain->verification_status" /></div><details><summary>Verification instructions</summary><ul><li>Meta tag: <code>&lt;meta name="horus-site-verification" content="{{ $domain->verification_token }}"&gt;</code></li><li>Text file: publish <code>{{ $domain->verification_token }}</code> at <code>/.well-known/horus-verification.txt</code></li><li>DNS TXT: host <code>_horus-verify.{{ $domain->domain }}</code>, value <code>horus-site-verification={{ $domain->verification_token }}</code></li></ul></details>@if(auth()->user()->hasPermission('sites.manage'))<form method="POST" action="{{ route('publisher.sites.domains.verify', [$site, $domain]) }}" class="inline-form">@csrf<label class="sr-only" for="method-{{ $domain->id }}">Verification method</label><select id="method-{{ $domain->id }}" class="hm-input" name="method"><option>META_TAG</option><option>TEXT_FILE</option><option>DNS_TXT</option></select><button class="hm-button-secondary">Verify now</button></form>@endif</div>@endforeach
    @if(auth()->user()->hasPermission('sites.manage'))<form method="POST" action="{{ route('publisher.sites.domains.store', $site) }}" class="inline-form">@csrf<input class="hm-input" name="domain" aria-label="Additional authorized domain" placeholder="additional.example.com" required><button class="hm-button-secondary">Add authorized domain</button></form>@endif
</article>
<article><p class="eyebrow">Review</p><h2>Submission status</h2>@forelse($site->reviews->sortByDesc('created_at') as $review)<div class="event"><div><strong>{{ $review->decision }}</strong><p>{{ $review->publisher_message }}</p></div><span>{{ $review->created_at }}</span></div>@empty<p class="muted">Not submitted yet.</p>@endforelse
    @if(auth()->user()->hasPermission('sites.manage') && in_array($site->status, [\App\Enums\SiteStatus::Draft, \App\Enums\SiteStatus::PendingVerification, \App\Enums\SiteStatus::Rejected], true))<form method="POST" action="{{ route('publisher.sites.submit', $site) }}">@csrf<button class="hm-button-primary">Submit for review</button></form>@endif
</article>
@endif
@endsection