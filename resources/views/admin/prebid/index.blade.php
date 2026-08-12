@extends('layouts.admin')
@section('title', $site->display_name.' Prebid')
@section('heading', 'Prebid · '.$site->display_name)
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Browser-side header bidding</p>
        <h2>{{ $site->primary_domain }}</h2>
        <p>Configured {{ $engineState->prebidConfiguredMode->value }} · resolved {{ $engineState->prebidDeliveryMode->value }} · {{ $engineState->prebidReason }}.</p>
        @if($engineState->prebidConfiguredMode->value === 'GAM_BRIDGE' && $engineState->prebidReason === 'GAM_BRIDGE_CONNECTION_REQUIRED')<p class="error"><strong>ACTION REQUIRED:</strong> explicit GAM_BRIDGE is unavailable. Horus will not silently switch this website to standalone.</p>@endif
    </div>
    <div class="status-row">
        <span class="pill">{{ $settings->enabled && $site->prebid_enabled ? 'ENABLED' : 'DISABLED' }}</span>
        <span class="pill">{{ $siteMappings->count() }} bidder mappings</span>
    </div>
</section>

<section class="detail-grid" style="margin-top:1rem">
<article>
    <p class="eyebrow">Selected GAM network</p>
    <h3>Browser auction settings</h3>
    <form class="form-stack" method="POST" action="{{ route('admin.sites.prebid.settings', $site) }}">@csrf @method('PUT')
        <label>Delivery mode
            <select class="hm-input" name="prebid_configured_mode" required>
                @foreach(['AUTO','GAM_BRIDGE','STANDALONE'] as $mode)<option value="{{ $mode }}" @selected($engineState->prebidConfiguredMode->value === $mode)>{{ $mode }}</option>@endforeach
            </select>
        </label>
        <p class="muted">AUTO prefers an eligible GAM bridge; if GAM is unavailable or operationally disabled and standalone is configured, it resolves to STANDALONE. Explicit GAM_BRIDGE never silently falls back.</p>
        <label>Prebid build
            <select class="hm-input" name="prebid_build_id">
                @foreach($builds as $build)<option value="{{ $build->id }}" @selected($settings->prebid_build_id === $build->id)>{{ $build->name }} · {{ $build->version }}</option>@endforeach
            </select>
        </label>
        <label>Auction timeout ms<input class="hm-input" type="number" min="100" max="5000" name="auction_timeout_ms" value="{{ $settings->auction_timeout_ms }}" required></label>
        <label>Price granularity
            <select class="hm-input" name="price_granularity">@foreach(['low','medium','high','dense','auto','custom'] as $value)<option @selected($settings->price_granularity === $value)>{{ $value }}</option>@endforeach</select>
        </label>
        <label>Currency<input class="hm-input" name="currency" value="{{ $settings->currency }}" maxlength="3" required></label>
        <label>Bidder sequence
            <select class="hm-input" name="bidder_sequence"><option value="fixed" @selected($settings->bidder_sequence === 'fixed')>fixed</option><option value="random" @selected($settings->bidder_sequence === 'random')>random</option></select>
        </label>
        <label>Consent behavior JSON<textarea class="hm-input" rows="5" name="consent_json">{{ json_encode($settings->consent_behavior ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
        <label>Minimum refresh seconds<input class="hm-input" type="number" min="30" max="3600" name="refresh_minimum_seconds" value="{{ data_get($settings->refresh_behavior, 'minimumIntervalSeconds', 30) }}" required></label>
        <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($settings->enabled && $site->prebid_enabled)> Enable Prebid for this website</label>
        <label><input type="hidden" name="lazy_loading" value="0"><input type="checkbox" name="lazy_loading" value="1" @checked(data_get($settings->lazy_loading, 'enabled', true))> Enable lazy loading</label>
        <label><input type="hidden" name="refresh_enabled" value="0"><input type="checkbox" name="refresh_enabled" value="1" @checked(data_get($settings->refresh_behavior, 'enabled', true))> Enable refresh auctions</label>
        <label><input type="hidden" name="bidder_timeout_reporting" value="0"><input type="checkbox" name="bidder_timeout_reporting" value="1" @checked($settings->bidder_timeout_reporting)> Local bidder-timeout diagnostics</label>
        <label><input type="hidden" name="gam_fallback" value="0"><input type="checkbox" name="gam_fallback" value="1" @checked($settings->gam_fallback)> GAM fallback behavior (bridge mode only)</label>
        <button class="hm-button-primary">Save and publish</button>
    </form>
</article>

<article>
    <p class="eyebrow">Bidder registry</p>
    <h3>Add bidder account</h3>
    <form class="form-stack" method="POST" action="{{ route('admin.prebid.accounts.store') }}">@csrf
        <label>Bidder<select class="hm-input" name="prebid_bidder_id">@foreach($bidders as $bidder)<option value="{{ $bidder->id }}">{{ $bidder->display_name }} · {{ $bidder->code }}</option>@endforeach</select></label>
        <label>Account name<input class="hm-input" name="name" placeholder="MENA account" required></label>
        <label>Publisher ID<input class="hm-input" name="publisher_id" placeholder="Public publisher/account ID"></label>
        <label>Bidder-specific public parameters JSON<textarea class="hm-input" rows="5" name="public_parameters_json" placeholder='{"siteId":"123"}'></textarea></label>
        <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
        <button class="hm-button-secondary">Save bidder account</button>
    </form>
    <div class="status-row" style="margin-top:1rem">
        @foreach($bidders as $bidder)<a class="pill" href="{{ $bidder->adapter->documentation_url }}" target="_blank" rel="noopener">{{ $bidder->code }}</a>@endforeach
    </div>
</article>
</section>

<article>
    <div class="section-heading"><div><p class="eyebrow">Website assignment</p><h2>Assign accounts centrally</h2></div></div>
    @forelse($accounts as $account)
    <div class="domain-card">
        <div><strong>{{ $account->name }}</strong> <span class="pill">{{ $account->bidder->code }}</span> <span class="pill">{{ $account->enabled ? 'enabled' : 'disabled' }}</span></div>
        <p class="muted">Publisher ID: {{ $account->publisher_id ?: 'configured in public parameters' }}</p>
        <form class="form-grid" method="POST" action="{{ route('admin.sites.prebid.accounts.assign', [$site, $account]) }}">@csrf
            <label>Sequence<input class="hm-input" type="number" min="0" max="1000" name="sequence" value="0"></label>
            <label class="full">Website public parameters JSON<textarea class="hm-input" rows="2" name="public_parameters_json">{}</textarea></label>
            <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" checked> Enabled</label>
            <div><button class="hm-button-secondary">Assign to website</button></div>
        </form>
    </div>
    @empty<p class="muted">Create a bidder account first.</p>@endforelse
</article>

<article>
    <div class="section-heading"><div><p class="eyebrow">Placement mapping</p><h2>Publisher and placement IDs</h2></div></div>
    @forelse($siteMappings as $mapping)
    <div class="domain-card">
        <div>
            <strong>{{ $mapping->account->name }}</strong>
            <span class="pill">{{ $mapping->account->bidder->code }}</span>
            <span class="pill">{{ $mapping->enabled ? 'enabled' : 'disabled' }}</span>
        </div>
        <form class="inline-form" method="POST" action="{{ route('admin.sites.prebid.mappings.toggle', [$site, $mapping]) }}">@csrf @method('PATCH')
            <input type="hidden" name="enabled" value="{{ $mapping->enabled ? 0 : 1 }}">
            <button class="hm-button-secondary">{{ $mapping->enabled ? 'Disable bidder' : 'Enable bidder' }}</button>
        </form>
        @foreach($site->placements as $placement)
            @php($placementMapping = $mapping->placementMappings->firstWhere('placement_id', $placement->id))
            <form class="form-grid" method="POST" action="{{ route('admin.sites.prebid.placements.assign', [$site, $mapping, $placement]) }}">@csrf
                <label>Placement<strong>{{ $placement->name }}</strong><input type="hidden" name="sequence" value="{{ $placementMapping?->sequence ?? 0 }}"></label>
                <label>Placement ID<input class="hm-input" name="placement_id_value" value="{{ $placementMapping?->placement_id_value }}" placeholder="Public bidder placement ID"></label>
                <label class="full">Public parameters JSON<textarea class="hm-input" rows="2" name="public_parameters_json">{{ json_encode($placementMapping?->public_parameters ?? [], JSON_UNESCAPED_SLASHES) }}</textarea></label>
                <label><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($placementMapping?->enabled ?? true)> Enabled</label>
                <div><button class="hm-button-secondary">Save placement mapping</button></div>
            </form>
        @endforeach
    </div>
    @empty<p class="muted">No bidder is assigned to this website.</p>@endforelse
</article>

@if($connection && $engineState->prebidDeliveryMode->value === 'GAM_BRIDGE')
<section class="detail-grid">
<article>
    <p class="eyebrow">Centralized GAM automation</p>
    <h3>Dry-run and object estimate</h3>
    @foreach($setupPreview['issues'] ?? [] as $issue)<p class="error">{{ $issue }}</p>@endforeach
    <div class="status-row">
        <span class="pill">{{ $setupPreview['estimatedObjects'] }} total</span>
        <span class="pill">{{ $setupPreview['existingObjects'] }} existing</span>
        <span class="pill">{{ $setupPreview['pendingObjects'] }} pending</span>
    </div>
    <p class="muted">The plan creates or locates one advertiser, targeting keys and values, one order, price-bucket line items, one universal creative, and associations in the selected GAM network.</p>
    <form class="inline-form" method="POST" action="{{ route('admin.gam.prebid.setup', $connection) }}">@csrf<input type="hidden" name="dry_run" value="1"><button class="hm-button-secondary">Create dry-run preview</button></form>
    <form class="form-stack danger-zone" method="POST" action="{{ route('admin.gam.prebid.setup', $connection) }}">@csrf
        <input type="hidden" name="dry_run" value="0">
        <label><input type="checkbox" name="confirm_external_writes" value="1" required> I confirm creation of up to {{ $setupPreview['pendingObjects'] }} external GAM objects</label>
        <button class="hm-button-primary">Run centralized GAM setup</button>
    </form>
</article>
<article>
    <p class="eyebrow">Setup history</p>
    <h3>Resume safely</h3>
    @forelse($setupRuns as $run)
        <div class="event">
            <div><strong>{{ $run->status }}</strong><br><span>{{ $run->completed_objects }}/{{ $run->estimated_objects }} · {{ $run->created_at }}</span></div>
            @if($run->status === 'FAILED' && ! $run->dry_run)
            <form method="POST" action="{{ route('admin.gam.prebid.resume', $run) }}">@csrf<input type="hidden" name="confirm_external_writes" value="1"><button class="hm-button-secondary">Resume</button></form>
            @endif
        </div>
    @empty<p class="muted">No setup runs yet.</p>@endforelse
</article>
</section>
@else
<article><p class="eyebrow">Standalone delivery</p><h3>No GAM automation required</h3><p class="muted">This resolved mode uses the pinned Horus Prebid build and direct isolated banner rendering. GAM setup objects are not created or modified.</p></article>
@endif
@endsection
