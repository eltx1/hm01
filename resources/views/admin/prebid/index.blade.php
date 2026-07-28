@extends('layouts.admin')
@section('title', 'Prebid')
@section('heading', 'Prebid browser bidding')
@section('navigation')
<a href="{{ route('dashboard') }}">Dashboard</a>
<a href="{{ route('admin.gam.connections.index') }}">Google Ad Manager</a>
<a class="active" href="{{ route('admin.prebid.index') }}">Prebid</a>
<a href="{{ route('admin.inventory.index') }}">Inventory</a>
<a href="{{ route('admin.sites.index') }}">Websites</a>
@endsection
@section('content')
<section class="hero">
    <div><p class="eyebrow">Browser-side demand orchestration</p><h2>Centralized Prebid without publisher ad-ops work.</h2><p>Horus Media builds the browser bundle outside production, publishes public configuration through the CDN, and creates the required GAM objects in the selected network.</p></div>
</section>

<div class="metric-grid" style="margin-top:1rem">
    <article><span class="muted">Adapters</span><strong class="metric">{{ $adapters->where('is_enabled', true)->count() }}</strong></article>
    <article><span class="muted">Bidder accounts</span><strong class="metric">{{ $accounts->count() }}</strong></article>
    <article><span class="muted">Compiled builds</span><strong class="metric">{{ $builds->count() }}</strong></article>
    <article><span class="muted">GAM networks</span><strong class="metric">{{ $connections->count() }}</strong></article>
</div>

<section class="detail-grid" style="margin-top:1rem">
<article>
    <p class="eyebrow">Bidder registry</p><h2>Approved public adapters</h2>
    @foreach($adapters as $adapter)
        <div class="event"><div><strong>{{ $adapter->display_name }}</strong><br><span>{{ $adapter->bidder_code }} · {{ $adapter->module_name }}</span></div><span>{{ $adapter->is_enabled ? 'Enabled' : 'Disabled' }}</span></div>
    @endforeach
</article>
<article>
    <p class="eyebrow">Custom builds</p><h2>Pinned release assets</h2>
    @foreach($builds as $build)
        <div class="event"><div><strong>{{ $build->name }}</strong><br><span>Prebid {{ $build->prebid_version }} · {{ count($build->modules ?? []) }} modules</span></div><span>{{ $build->status->value }}</span></div>
    @endforeach
    <p class="muted">Production receives compiled JavaScript only. Node.js and the Prebid source tree are not required on Hostinger.</p>
</article>
</section>

<article style="margin-top:1rem">
    <div class="section-heading"><div><p class="eyebrow">Public bidder accounts</p><h2>Account registry</h2></div></div>
    <form class="form-grid" method="POST" action="{{ route('admin.prebid.accounts.store') }}">@csrf
        <label>Owner organization<select class="hm-input" name="organization_id" required>@foreach(\App\Models\Organization::withoutGlobalScopes()->orderBy('name')->get() as $organization)<option value="{{ $organization->id }}">{{ $organization->name }}</option>@endforeach</select></label>
        <label>Bidder<select class="hm-input" name="prebid_bidder_id" required>@foreach(\App\Models\PrebidBidder::withoutGlobalScopes()->with('adapter')->where('is_enabled', true)->orderBy('display_name')->get() as $bidder)<option value="{{ $bidder->id }}">{{ $bidder->display_name }}</option>@endforeach</select></label>
        <label>Account name<input class="hm-input" name="name" required></label>
        <label>Publisher ID<input class="hm-input" name="publisher_id" placeholder="Public bidder publisher/account ID"></label>
        <label>Account code<input class="hm-input" name="account_code"></label>
        <label class="full">Bidder-specific public parameters JSON<textarea class="hm-input" name="public_parameters_json" rows="4" placeholder='{"member":"1234"}'></textarea></label>
        <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" checked> Enabled</label>
        <div class="full"><button class="hm-button-primary">Add bidder account</button></div>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Account</th><th>Bidder</th><th>Owner</th><th>Sites</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($accounts as $account)<tr><td>{{ $account->name }}</td><td>{{ $account->bidder->display_name }}</td><td>{{ $account->organization->name }}</td><td>{{ $account->site_mappings_count }}</td><td>{{ $account->is_enabled ? 'Enabled' : 'Disabled' }}</td><td><form method="POST" action="{{ route('admin.prebid.accounts.toggle', $account) }}">@csrf<input type="hidden" name="is_enabled" value="{{ $account->is_enabled ? 0 : 1 }}"><button class="hm-button-secondary">{{ $account->is_enabled ? 'Disable' : 'Enable' }}</button></form></td></tr>@empty<tr><td colspan="6" class="muted">No bidder accounts configured.</td></tr>@endforelse
    </tbody></table></div>
</article>

<article style="margin-top:1rem">
    <p class="eyebrow">Website auction settings</p><h2>Configure and publish</h2>
    <div class="table-wrap"><table><thead><tr><th>Website</th><th>Serving mode</th><th>Prebid</th><th>GAM network</th><th></th></tr></thead><tbody>
    @foreach($sites as $site)<tr><td>{{ $site->display_name }}<br><span class="muted">{{ $site->primary_domain }}</span></td><td>{{ $site->serving_mode->value }}</td><td>{{ $site->prebid_enabled ? 'Enabled' : 'Disabled' }}</td><td>{{ app(\App\Services\Gam\GamConnectionResolver::class)->resolve($site)?->network_code ?? 'Unresolved' }}</td><td><a class="text-link" href="{{ route('admin.prebid.sites.show', $site) }}">Configure</a></td></tr>@endforeach
    </tbody></table></div>
</article>

<article style="margin-top:1rem">
    <p class="eyebrow">Google Ad Manager automation</p><h2>Connection-specific setup</h2>
    @foreach($connections as $connection)
        @php($template = $templates->get($connection->id))
        <div class="domain-card">
            <div class="section-heading"><div><strong>{{ $connection->name }}</strong><p class="muted">{{ $connection->type->value }} · {{ $connection->network_code }}</p></div><span class="pill">{{ $template?->mode }}</span></div>
            <form class="form-grid" method="POST" action="{{ route('admin.prebid.templates.update', $connection) }}">@csrf @method('PUT')
                <label>GAM trafficker ID<input class="hm-input" name="trafficker_id" value="{{ data_get($template?->settings, 'trafficker_id') }}" required></label>
                <label>Mode<select class="hm-input" name="mode"><option value="TOP_PRICE" @selected($template?->mode === 'TOP_PRICE')>TOP_PRICE</option><option value="SEND_ALL_BIDS" @selected($template?->mode === 'SEND_ALL_BIDS')>SEND_ALL_BIDS</option></select></label>
                <label>Currency<input class="hm-input" name="currency" value="{{ $template?->currency ?? 'USD' }}" maxlength="3" required></label>
                <label>Line item priority<input class="hm-input" type="number" min="1" max="16" name="line_item_priority" value="{{ $template?->line_item_priority ?? 12 }}" required></label>
                <label>Template version<input class="hm-input" type="number" min="1" name="version" value="{{ $template?->version ?? 1 }}" required></label>
                <div class="full"><button class="hm-button-secondary">Save template</button></div>
            </form>
            <form class="inline-form" method="POST" action="{{ route('admin.prebid.connections.preview', $connection) }}">@csrf
                <select class="hm-input" name="site_id"><option value="">All websites routed to this network</option>@foreach($sites as $site)@if(app(\App\Services\Gam\GamConnectionResolver::class)->resolve($site)?->id === $connection->id)<option value="{{ $site->id }}">{{ $site->display_name }}</option>@endif @endforeach</select>
                <button class="hm-button-primary">Create dry-run preview</button>
            </form>
        </div>
    @endforeach
</article>

<article style="margin-top:1rem"><p class="eyebrow">Setup history</p><h2>Resumable runs</h2><div class="table-wrap"><table><thead><tr><th>Network</th><th>Status</th><th>Progress</th><th>Objects</th><th>Created</th><th></th></tr></thead><tbody>@forelse($runs as $run)<tr><td>{{ $run->connection->name }}</td><td>{{ $run->status->value }}</td><td>{{ $run->cursor }}/{{ data_get($run->plan, 'estimates.pendingObjects', 0) }}</td><td>{{ data_get($run->plan, 'estimates.totalObjects', 0) }}</td><td>{{ $run->created_at->diffForHumans() }}</td><td><a class="text-link" href="{{ route('admin.prebid.setup-runs.show', $run) }}">Open</a></td></tr>@empty<tr><td colspan="6" class="muted">No setup runs.</td></tr>@endforelse</tbody></table></div></article>
@endsection
