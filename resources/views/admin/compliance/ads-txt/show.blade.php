@extends('layouts.admin')
@section('title', $site->display_name.' ads.txt')
@section('heading', 'Ads.txt · '.$site->display_name)
@section('content')
@php($activationPhase = ($summary['phase'] ?? 'PRODUCTION') === 'ACTIVATION')
<section class="hero"><div><p class="eyebrow">{{ $site->publisher->display_name }} · Supply Chain & Compliance</p><h2>{{ $site->primary_domain }}</h2><div class="status-row"><x-status-badge :status="$summary['status']" /><x-status-badge :status="$summary['verification_state']" />@if($activationPhase)<span class="status-badge">ACTIVATION</span>@endif</div><p>{{ $summary['action'] }}</p></div><a class="hm-button-secondary button-link" href="{{ route('admin.compliance.ads-txt.index') }}">All websites</a></section>

<x-control-plane.workspace-tabs :items="[
    ['label' => 'Ads.txt', 'href' => route('admin.compliance.ads-txt.index')],
    ['label' => 'Sellers & schain', 'href' => route('admin.compliance.sellers.index'), 'visible' => auth()->user()->hasPermission('supply_chain.sellers.view')],
]" label="Supply chain compliance sections" />

@if($activationPhase)
<article class="workspace-section">
    <p class="eyebrow">Website activation</p>
    <h2>Horus activation ads.txt</h2>
    <p class="muted">This website is not in production yet. The two Horus HMP/HMS DIRECT records are the activation-critical records. <strong>Canonical required file</strong> for activation is limited to those two records; master and demand records included in the complete copy block are supporting records and do not block website approval or activation.</p>
    <div class="status-row"><x-status-badge :status="$summary['core_verified'] ? 'VERIFIED' : 'PENDING'" /><span>{{ $summary['core_verified'] ? 'Both Horus records are currently verified.' : 'Verification may complete while human website review continues.' }}</span></div>
</article>
@endif

<section class="metric-grid compliance-metrics" aria-label="Ads.txt summary">
    <article><p class="eyebrow">{{ $activationPhase ? 'Activation required' : 'Required' }}</p><strong class="metric">{{ $summary['required_count'] }}</strong></article>
    <article><p class="eyebrow">Correct</p><strong class="metric">{{ $summary['correct_count'] }}</strong></article>
    <article><p class="eyebrow">Missing</p><strong class="metric">{{ $summary['missing_count'] }}</strong></article>
    <article><p class="eyebrow">Invalid / conflict</p><strong class="metric">{{ $summary['invalid_count'] }}</strong></article>
    <article><p class="eyebrow">Last check</p><strong>{{ $summary['last_checked']?->diffForHumans() ?: 'Never' }}</strong></article>
</section>

<div class="compliance-actions">
    <button class="hm-button-primary" type="button" data-copy-target="canonical-ads-txt" data-copy-label="{{ $activationPhase ? 'Copy complete installation file' : 'Copy canonical file' }}">{{ $activationPhase ? 'Copy complete installation file' : 'Copy canonical file' }}</button>
    <a class="hm-button-secondary button-link" href="{{ route('admin.compliance.ads-txt.download', $site) }}">{{ $activationPhase ? 'Download installation file' : 'Download canonical file' }}</a>
    @if(auth()->user()->hasPermission('supply_chain.ads_txt.verify'))<form method="POST" action="{{ route('admin.compliance.ads-txt.verify', $site) }}">@csrf<button class="hm-button-secondary">Recheck safely</button></form>@endif
</div>

<section class="detail-grid">
    <article><p class="eyebrow">Authoritative output</p><h2>{{ $activationPhase ? 'Complete Horus installation file' : 'Production canonical required file' }}</h2><pre id="canonical-ads-txt" class="compliance-code">{{ $summary['canonical']['content'] }}</pre></article>
    <article><p class="eyebrow">Latest public response</p><h2>Live fetched file</h2>@if($summary['live_content'] !== null)<pre class="compliance-code">{{ $summary['live_content'] }}</pre>@else<p class="muted">No safe live response has been stored.</p>@endif</article>
</section>

<section class="detail-grid">
    <article><p class="eyebrow">{{ $activationPhase ? 'Activation requirements vs live' : 'Canonical vs live' }}</p><h2>Correct and missing</h2>
        <h3>Correct</h3>@forelse($summary['comparison']['correct'] ?? [] as $item)<code class="record-line record-correct">{{ $item['canonical'] }}</code>@empty<p class="muted">No required records confirmed yet.</p>@endforelse
        <h3>Missing</h3>@forelse($summary['comparison']['missing'] ?? [] as $item)<code class="record-line record-missing">{{ $item['canonical'] }}</code>@empty<p class="muted">No missing seller records.</p>@endforelse
        @foreach($summary['comparison']['missing_directives'] ?? [] as $item)<code class="record-line record-missing">{{ $item['canonical'] }}</code>@endforeach
    </article>
    <article><p class="eyebrow">Diagnostics</p><h2>Invalid, conflicting and additional</h2>
        @forelse($summary['comparison']['invalid'] ?? [] as $item)<div class="finding finding-danger"><strong>Line {{ $item['line'] }} · {{ $item['code'] }}</strong><code>{{ $item['content'] }}</code><span>{{ $item['message'] }}</span></div>@empty<p class="muted">No invalid lines.</p>@endforelse
        @foreach($summary['comparison']['conflicts'] ?? [] as $item)<div class="finding finding-danger"><strong>Conflict</strong><span>{{ $item['message'] }}</span>@foreach($item['records'] as $line)<code>{{ $line }}</code>@endforeach</div>@endforeach
        <h3>{{ $activationPhase ? 'Additional live records (not required for Horus activation)' : 'Additional unmanaged live records' }}</h3>@forelse($summary['comparison']['additional'] ?? [] as $item)<code class="record-line">{{ $item['canonical'] }}</code>@empty<p class="muted">No additional live seller records.</p>@endforelse
    </article>
</section>

<article class="workspace-section"><p class="eyebrow">{{ $activationPhase ? 'Installation sources' : 'Canonical sources' }}</p><h2>{{ $activationPhase ? 'Activation and supporting records' : 'Required records and provenance' }}</h2>
    @forelse($summary['canonical']['records'] as $record)<div class="compact-row"><div><code>{{ $record['canonical'] }}</code><p>{{ $record['account_label'] }} · {{ $record['scope'] }} · source {{ $record['source'] }}</p></div><x-status-badge :status="$record['status']" /></div>@empty<p class="muted">No eligible seller records are currently configured. OWNERDOMAIN and MANAGERDOMAIN remain generated.</p>@endforelse
</article>

@if(auth()->user()->hasPermission('supply_chain.ads_txt.manage'))
<article class="workspace-section"><p class="eyebrow">Structured management only</p><h2>Create managed record</h2><p class="muted">Raw scripts and arbitrary code are never accepted. Choose an account already mapped to this website.</p>
    <form method="POST" action="{{ route('admin.compliance.ads-txt.records.store') }}" class="form-grid">@csrf
        <label>Demand account<select class="hm-input" name="demand_account_id" required>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->network?->name }} · {{ $account->name }}</option>@endforeach</select></label>
        <label>Scope<select class="hm-input" name="site_id"><option value="{{ $site->id }}">This website</option><option value="">Every website mapped to this account</option></select></label>
        <label>Advertising system domain<input class="hm-input" name="domain" required></label><label>Publisher account ID<input class="hm-input" name="publisher_account_id" required></label>
        <label>Relationship<select class="hm-input" name="relationship"><option>DIRECT</option><option>RESELLER</option></select></label><label>Certification authority ID<input class="hm-input" name="certification_authority_id"></label>
        <button class="hm-button-primary">Create record</button>
    </form>
</article>
@endif

<article class="workspace-section"><p class="eyebrow">Managed inventory</p><h2>Applicable records</h2>
@forelse($records as $record)
    <div class="managed-record"><div class="compact-row"><div><code>{{ $record->raw_record }}</code><p>{{ $record->account?->network?->name }} · {{ $record->site_id ? 'Website' : 'Account global' }} · source {{ $record->source }}</p></div><div class="status-row"><x-status-badge :status="$record->status" /><x-status-badge :status="$record->review_status" /></div></div>
    @if($record->source === 'MANUAL' && auth()->user()->hasPermission('supply_chain.ads_txt.manage'))
        <details><summary>Edit structured record</summary><form method="POST" action="{{ route('admin.compliance.ads-txt.records.update', $record) }}" class="form-grid">@csrf @method('PUT')
            <label>Demand account<select class="hm-input" name="demand_account_id">@foreach($accounts as $account)<option value="{{ $account->id }}" @selected($account->id === $record->demand_account_id)>{{ $account->network?->name }} · {{ $account->name }}</option>@endforeach</select></label>
            <label>Scope<select class="hm-input" name="site_id"><option value="{{ $site->id }}" @selected($record->site_id === $site->id)>This website</option><option value="" @selected($record->site_id === null)>Every mapped website</option></select></label>
            <label>Domain<input class="hm-input" name="domain" value="{{ $record->domain }}" required></label><label>Publisher account ID<input class="hm-input" name="publisher_account_id" value="{{ $record->publisher_account_id }}" required></label>
            <label>Relationship<select class="hm-input" name="relationship"><option @selected($record->relationship === 'DIRECT')>DIRECT</option><option @selected($record->relationship === 'RESELLER')>RESELLER</option></select></label><label>Certification authority ID<input class="hm-input" name="certification_authority_id" value="{{ $record->certification_authority_id }}"></label>
            <button class="hm-button-secondary">Update record</button>
        </form><form method="POST" action="{{ route('admin.compliance.ads-txt.records.disable', $record) }}" class="inline-form danger-zone">@csrf @method('PATCH')<button class="hm-button-danger">Disable managed record</button></form></details>
    @else<p class="muted">Connector-managed source; change it at the authoritative connector.</p>@endif</div>
@empty<p class="muted">No applicable records.</p>@endforelse
</article>

<section class="detail-grid">
    <article><p class="eyebrow">Verification snapshots</p><h2>History</h2>@forelse($history as $check)<div class="event"><div><x-status-badge :status="$check->status" /> <strong>{{ $check->trigger }}</strong><p>{{ $check->http_status ? 'HTTP '.$check->http_status : data_get($check->findings, 'fetch.error_code') }} · {{ $check->occurrence_count }} occurrence(s)</p></div><span>{{ $check->first_checked_at }} → {{ $check->checked_at }}</span></div>@empty<p class="muted">No verification history.</p>@endforelse</article>
    <article><p class="eyebrow">Immutable evidence</p><h2>Ads.txt audit history</h2>@forelse($auditEvents as $event)<div class="event"><strong>{{ str($event->event)->replace('.', ' ')->headline() }}</strong><span>{{ $event->created_at }}</span></div>@empty<p class="muted">No manual ads.txt audit events.</p>@endforelse</article>
</section>
@endsection
