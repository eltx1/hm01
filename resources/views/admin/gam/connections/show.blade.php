@extends('layouts.admin')
@section('title', $connection->name)
@section('heading', $connection->name)
@section('content')
<section class="hero">
    <div><p class="eyebrow">{{ $connection->type->value }}</p><h2>{{ $connection->network_code ?: 'Network code pending' }}</h2><p>{{ $connection->application_name }} · {{ $connection->driver }} · credential reference encrypted</p></div>
    <div class="status-row"><span class="pill">{{ $connection->health_status->value }}</span>@if($connection->is_primary)<span class="pill">Primary HORUS_GAM</span>@endif</div>
</section>

<div class="status-row" style="margin:1rem 0">
    <a class="hm-button-secondary button-link" href="{{ route('admin.gam.connections.edit', $connection) }}">Edit</a>
    <form method="POST" action="{{ route('admin.gam.connections.test', $connection) }}">@csrf<button class="hm-button-primary">Test and synchronize</button></form>
    @if($connection->type === \App\Enums\GamConnectionType::HorusGam && ! $connection->is_primary)
    <form method="POST" action="{{ route('admin.gam.connections.primary', $connection) }}">@csrf<button class="hm-button-secondary">Make primary HORUS_GAM</button></form>
    @endif
</div>

<div class="metric-grid">
    <article><span class="muted">Assigned sites</span><strong class="metric">{{ $connection->sites->count() }}</strong></article>
    <article><span class="muted">Accessible networks</span><strong class="metric">{{ $connection->networks->count() }}</strong></article>
    <article><span class="muted">Recent operations</span><strong class="metric">{{ $connection->operations->count() }}</strong></article>
    <article><span class="muted">Open errors</span><strong class="metric">{{ $connection->errors->count() }}</strong></article>
    <article><span class="muted">Last successful sync</span><strong class="metric-small">{{ $connection->last_successful_sync_at?->diffForHumans() ?? 'Never' }}</strong></article>
</div>

<article style="margin-top:1rem">
    <div class="section-heading"><div><p class="eyebrow">Hybrid capability router</p><h3>REST-first execution matrix</h3></div><span class="pill">SOAP {{ $soapFallbackVersion ?: 'not installed' }}</span></div>
    <p class="muted">The transport is selected before each request. A failed REST write is never replayed through SOAP.</p>
    <div class="table-wrap"><table><thead><tr><th>Operation</th><th>Transport</th></tr></thead><tbody>
        @foreach($capabilityMatrix as $operation => $transport)<tr><td>{{ $operation }}</td><td><span class="pill">{{ $transport }}</span></td></tr>@endforeach
    </tbody></table></div>
</article>

<div class="split-grid">
<article>
    <div class="section-heading"><div><p class="eyebrow">Website routing</p><h3>Assign this connection</h3></div></div>
    <form class="form-stack" method="POST" action="{{ route('admin.gam.connections.assign-site', $connection) }}">@csrf
        <label>Website<select class="hm-input" name="site_id" required>@foreach($sites as $site)<option value="{{ $site->id }}">{{ $site->display_name }} · {{ $site->primary_domain }} · {{ $site->serving_mode->value }}</option>@endforeach</select></label>
        <label>Reason<textarea class="hm-input" name="reason" rows="3" required>Assign selected GAM connection from Horus Media control plane.</textarea></label>
        <button class="hm-button-primary">Assign connection</button>
    </form>
    <div class="domain-card"><strong>Currently assigned</strong>@forelse($connection->sites as $site)<p><a class="text-link" href="{{ route('admin.sites.show', $site) }}">{{ $site->display_name }}</a> <span class="muted">{{ $site->primary_domain }}</span></p>@empty<p class="muted">No website is explicitly assigned.</p>@endforelse</div>
</article>

<article>
    <p class="eyebrow">Credential posture</p><h3>Protected reference</h3>
    <dl><dt>Type</dt><dd>{{ $connection->credential?->credential_type?->value }}</dd><dt>Reference</dt><dd>[ENCRYPTED]</dd><dt>Client hint</dt><dd>{{ $connection->credential?->client_email_hint ?: $connection->credential?->oauth_client_id_hint ?: 'Not supplied' }}</dd><dt>Rotated</dt><dd>{{ $connection->credential?->rotated_at?->toDayDateTimeString() ?? 'Unknown' }}</dd><dt>Dry-run default</dt><dd>{{ $connection->dry_run_default ? 'Enabled' : 'Disabled' }}</dd></dl>
</article>
</div>

<div class="split-grid">
<article><p class="eyebrow">Accessible networks</p><h3>Network metadata</h3>@forelse($connection->networks as $network)<div class="event"><div><strong>{{ $network->display_name ?: $network->network_code }}</strong><br><span>{{ $network->currency_code }} · {{ $network->time_zone }}</span></div><span>{{ $network->is_current ? 'Current' : 'Accessible' }}</span></div>@empty<p class="muted">Run a connection test to synchronize networks.</p>@endforelse</article>
<article><p class="eyebrow">Permission validation</p><h3>Verified capabilities</h3>@forelse($connection->permissions as $permission)<div class="event"><strong>{{ $permission->permission_name }}</strong><span>{{ $permission->status }}</span></div>@empty<p class="muted">No permission checks have been recorded.</p>@endforelse</article>
</div>

<article style="margin-top:1rem"><p class="eyebrow">API operation audit</p><h3>Recent operations</h3><div class="table-wrap"><table><thead><tr><th>Operation</th><th>Service</th><th>Status</th><th>Dry-run</th><th>Attempts</th><th>Time</th></tr></thead><tbody>@forelse($connection->operations as $operation)<tr><td>{{ $operation->operation }}</td><td>{{ $operation->service }}.{{ $operation->method }}</td><td>{{ $operation->status->value }}</td><td>{{ $operation->dry_run ? 'Yes' : 'No' }}</td><td>{{ $operation->attempts }}</td><td>{{ $operation->created_at->diffForHumans() }}</td></tr>@empty<tr><td colspan="6" class="muted">No API operations recorded.</td></tr>@endforelse</tbody></table></div></article>

@if($connection->errors->isNotEmpty())<article class="danger-zone"><p class="eyebrow">Open errors</p><h3>Attention required</h3>@foreach($connection->errors as $error)<div class="event"><div><strong>{{ $error->category->value }} · {{ $error->code }}</strong><br><span>{{ $error->message }}</span></div><span>{{ $error->occurred_at->diffForHumans() }}</span></div>@endforeach</article>@endif
@endsection
