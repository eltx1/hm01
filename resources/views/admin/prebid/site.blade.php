@extends('layouts.admin')
@section('title', 'Prebid · '.$site->display_name)
@section('heading', 'Prebid · '.$site->display_name)
@section('navigation')
<a href="{{ route('dashboard') }}">Dashboard</a>
<a href="{{ route('admin.gam.connections.index') }}">Google Ad Manager</a>
<a class="active" href="{{ route('admin.prebid.index') }}">Prebid</a>
<a href="{{ route('admin.sites.inventory.index', $site) }}">Inventory</a>
@endsection
@section('content')
<section class="hero"><div><p class="eyebrow">{{ $site->primary_domain }}</p><h2>Browser auction configuration</h2><p>The permanent Horus loader remains unchanged. Publish the site's static configuration after changing bidders or auction settings.</p></div><span class="pill">{{ $site->serving_mode->value }}</span></section>

<section class="detail-grid" style="margin-top:1rem">
<article>
    <p class="eyebrow">Auction controls</p><h2>Prebid settings</h2>
    <form class="form-grid" method="POST" action="{{ route('admin.prebid.sites.settings.update', $site) }}">@csrf @method('PUT')
        <label>Compiled build<select class="hm-input" name="prebid_build_id">@foreach($builds as $build)<option value="{{ $build->id }}" @selected($setting->prebid_build_id === $build->id)>{{ $build->name }} · {{ $build->version }}</option>@endforeach</select></label>
        <label>Auction timeout (ms)<input class="hm-input" type="number" min="300" max="5000" name="auction_timeout_ms" value="{{ $setting->auction_timeout_ms }}" required></label>
        <label>Price granularity<select class="hm-input" name="price_granularity">@foreach(\App\Enums\PrebidPriceGranularity::cases() as $value)<option value="{{ $value->value }}" @selected($setting->price_granularity === $value)>{{ $value->value }}</option>@endforeach</select></label>
        <label>Currency<input class="hm-input" name="currency" maxlength="3" value="{{ $setting->currency }}" required></label>
        <label>Bidder sequence<select class="hm-input" name="bidder_sequence">@foreach(\App\Enums\PrebidBidderSequence::cases() as $value)<option value="{{ $value->value }}" @selected($setting->bidder_sequence === $value)>{{ $value->value }}</option>@endforeach</select></label>
        <label>Refresh interval (seconds)<input class="hm-input" type="number" min="30" max="3600" name="refresh_interval_seconds" value="{{ $setting->refresh_interval_seconds }}"></label>
        <label class="full">Consent configuration JSON<textarea class="hm-input" rows="5" name="consent_config_json">{{ json_encode($setting->consent_config ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
        <label class="full">User-sync configuration JSON<textarea class="hm-input" rows="5" name="user_sync_config_json">{{ json_encode($setting->user_sync_config ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
        <label class="full">Advanced public Prebid configuration JSON<textarea class="hm-input" rows="5" name="advanced_config_json">{{ json_encode($setting->advanced_config ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
        <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" @checked($setting->is_enabled)> Enable Prebid</label>
        <label><input type="hidden" name="lazy_loading_enabled" value="0"><input type="checkbox" name="lazy_loading_enabled" value="1" @checked($setting->lazy_loading_enabled)> Coordinate with lazy loading</label>
        <label><input type="hidden" name="refresh_enabled" value="0"><input type="checkbox" name="refresh_enabled" value="1" @checked($setting->refresh_enabled)> Run a new auction before refresh</label>
        <label><input type="hidden" name="timeout_reporting_enabled" value="0"><input type="checkbox" name="timeout_reporting_enabled" value="1" @checked($setting->timeout_reporting_enabled)> Browser-only timeout diagnostics</label>
        <label><input type="hidden" name="gam_fallback_enabled" value="0"><input type="checkbox" name="gam_fallback_enabled" value="1" @checked($setting->gam_fallback_enabled)> Fall back to GAM</label>
        <label><input type="hidden" name="send_all_bids" value="0"><input type="checkbox" name="send_all_bids" value="1" @checked($setting->send_all_bids)> Send all bids targeting</label>
        <label><input type="hidden" name="debug_enabled" value="0"><input type="checkbox" name="debug_enabled" value="1" @checked($setting->debug_enabled)> Debug diagnostics</label>
        <div class="full"><button class="hm-button-primary">Save auction settings</button></div>
    </form>
</article>

<article>
    <p class="eyebrow">Deployment</p><h2>Static configuration</h2>
    <dl><dt>Permanent loader</dt><dd><code>{{ $site->installationCode() }}</code></dd><dt>Build</dt><dd>{{ $setting->build?->version ?? 'Not selected' }}</dd><dt>Configuration version</dt><dd>{{ $setting->configuration_version }}</dd><dt>Selected GAM</dt><dd>{{ app(\App\Services\Gam\GamConnectionResolver::class)->resolve($site)?->name ?? 'Unresolved' }}</dd></dl>
    <form class="form-stack" method="POST" action="{{ route('admin.sites.config.publish', $site) }}">@csrf<select class="hm-input" name="environment"><option value="PREVIEW">PREVIEW</option><option value="TEST">TEST</option><option value="PRODUCTION">PRODUCTION</option></select><button class="hm-button-primary">Publish static configuration</button></form>
    <p class="muted">The resulting JSON and compiled JavaScript are served from the CDN. Auctions and ad requests never pass through Laravel.</p>
</article>
</section>

<article style="margin-top:1rem">
    <p class="eyebrow">Website bidder mapping</p><h2>Assign an account</h2>
    <form class="form-grid" method="POST" action="{{ route('admin.prebid.sites.accounts.assign', $site) }}">@csrf
        <label>Bidder account<select class="hm-input" name="bidder_account_id" required>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }} · {{ $account->bidder->display_name }} · {{ $account->organization->name }}</option>@endforeach</select></label>
        <label>Website publisher/site ID<input class="hm-input" name="publisher_id" placeholder="Public site-level ID"></label>
        <label>Sequence<input class="hm-input" type="number" min="1" max="1000" name="sequence" value="100" required></label>
        <label class="full">Site-level public parameters JSON<textarea class="hm-input" rows="4" name="public_parameters_json" placeholder='{"siteId":"123"}'></textarea></label>
        <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" checked> Enabled</label>
        <div class="full"><button class="hm-button-secondary">Assign bidder to website</button></div>
    </form>
</article>

@foreach($site->bidderSiteMappings->sortBy('sequence') as $mapping)
<article class="domain-card" style="margin-top:1rem">
    <div class="section-heading"><div><p class="eyebrow">{{ $mapping->account->bidder->code }}</p><h2>{{ $mapping->account->name }}</h2><p class="muted">Website mapping sequence {{ $mapping->sequence }} · {{ $mapping->is_enabled ? 'Enabled' : 'Disabled' }}</p></div><span class="pill">{{ $mapping->account->bidder->adapter->module_name }}</span></div>
    <p>Public parameters: <code>{{ json_encode($mapping->public_parameters ?? [], JSON_UNESCAPED_SLASHES) }}</code></p>
    <form class="form-grid" method="POST" action="{{ route('admin.prebid.sites.placements.assign', [$site, $mapping]) }}">@csrf
        <label>Placement<select class="hm-input" name="placement_id" required>@foreach($site->placements as $placement)<option value="{{ $placement->id }}">{{ $placement->name }} · {{ $placement->code }}</option>@endforeach</select></label>
        <label>Bidder placement ID<input class="hm-input" name="placement_id_value" placeholder="zoneId, adSlot, unit, placementId..."></label>
        <label>Sequence<input class="hm-input" type="number" min="1" max="1000" name="sequence" value="100" required></label>
        <label class="full">Placement-level public parameters JSON<textarea class="hm-input" rows="3" name="public_parameters_json" placeholder='{}'></textarea></label>
        <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" checked> Enabled</label>
        <div class="full"><button class="hm-button-secondary">Assign to placement</button></div>
    </form>
    @forelse($mapping->placementMappings as $placementMapping)<div class="event"><div><strong>{{ $placementMapping->placement->name }}</strong><br><span>{{ $placementMapping->placement_id_value ?: 'Parameters only' }}</span></div><span>{{ $placementMapping->is_enabled ? 'Enabled' : 'Disabled' }}</span></div>@empty<p class="muted">No placement overrides. This website mapping applies to every eligible placement.</p>@endforelse
</article>
@endforeach
@endsection
