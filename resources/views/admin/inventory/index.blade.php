@extends('layouts.admin')
@section('title', $site->display_name.' Inventory')
@section('heading', 'Inventory · '.$site->display_name)
@section('content')
<section class="hero">
    <div><p class="eyebrow">Static browser delivery</p><h2>{{ $site->primary_domain }}</h2><p>{{ $site->serving_mode->value }} · {{ $site->gamConnection?->network_code ?: 'automatic primary connection' }}</p></div>
    <div class="status-row"><span class="pill">{{ $site->siteConfig?->status ?? 'ACTIVE' }}</span><span class="pill">{{ $site->placements->count() }} placements</span></div>
</section>

<section class="detail-grid" style="margin-top:1rem">
<article>
    <p class="eyebrow">Permanent installation</p><h3>Horus Loader</h3>
    <p class="muted">This script remains unchanged when the serving mode, GAM connection, placements, native demand, or configuration version changes.</p>
    <code class="installation-code">{{ $site->installationCode() }}</code>
</article>
<article>
    <p class="eyebrow">Static configuration</p><h3>Delivery controls</h3>
    <p class="muted">Runtime changes publish Production automatically for active websites. Changes to inactive websites are saved and published together on first activation. Operations confirms the separate Cloudflare Pages deployment.</p>
    <form class="inline-form" method="POST" action="{{ route('admin.sites.config.publish', $site) }}">@csrf<select class="hm-input" name="environment"><option>PREVIEW</option><option>TEST</option><option selected>PRODUCTION</option></select><button class="hm-button-primary">Force republish</button></form>
    <form class="inline-form" method="GET" target="_blank" action="{{ route('admin.sites.config.preview', $site) }}"><select class="hm-input" name="environment"><option>PREVIEW</option><option>TEST</option><option>PRODUCTION</option></select><button class="hm-button-secondary">Preview JSON</button></form>
    <div class="status-row"><span class="pill">Preview v{{ $site->siteConfig?->preview_version ?? 0 }}</span><span class="pill">Test v{{ $site->siteConfig?->test_version ?? 0 }}</span><span class="pill">Production v{{ $site->siteConfig?->production_version ?? 0 }}</span></div>
    @if($site->configVersions->first())<p class="muted">Latest request: {{ str_replace('_', ' ', $site->configVersions->first()->status->value) }} · {{ $site->configVersions->first()->environment->value }} v{{ $site->configVersions->first()->version }}</p>@endif
</article>
</section>

<section class="detail-grid">
<article>
    <p class="eyebrow">Supply-chain identity</p><h3>Seller declaration</h3>
    <form class="form-stack" method="POST" action="{{ route('admin.sites.inventory.sellers.store', $site) }}">@csrf
        <label>Seller ID<input class="hm-input" name="seller_id" required></label>
        <label>Seller type<select class="hm-input" name="seller_type"><option>PUBLISHER</option><option>INTERMEDIARY</option><option>BOTH</option></select></label>
        <label>Public name<input class="hm-input" name="name" required></label>
        <label>Seller business domain<input class="hm-input" name="domain" value="{{ $site->publisher?->business_domain ?: $site->primary_domain }}" required></label>
        <label><input type="checkbox" name="is_confidential" value="1"> Confidential seller</label>
        <button class="hm-button-secondary">Save and publish supply chain</button>
    </form>
    @foreach($sellerDeclarations as $seller)<p><strong>{{ $seller->seller_id }}</strong> · {{ $seller->seller_type }} · {{ $seller->domain }} · {{ $seller->review_status->value }}</p>@endforeach
</article>
</section>

<section class="detail-grid">
<article>
    <p class="eyebrow">Ad unit catalog</p><h3>Create local GAM inventory</h3>
    <form class="form-stack" method="POST" action="{{ route('admin.sites.inventory.ad-units.store', $site) }}">@csrf
        <label>Name<input class="hm-input" name="name" required></label><label>Code<input class="hm-input" name="code" placeholder="article_top" required></label>
        <label>Sizes<input class="hm-input" name="sizes_text" placeholder="300x250,336x280,fluid" required></label><label>Description<textarea class="hm-input" name="description"></textarea></label>
        <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" checked> Enabled</label><button class="hm-button-secondary">Create ad unit</button>
    </form>
    @foreach($site->adUnits as $unit)<p><strong>{{ $unit->code }}</strong> · {{ $unit->sizes->map(fn($s) => $s->size_type === 'FLUID' ? 'fluid' : $s->width.'x'.$s->height)->join(', ') }} · {{ $unit->sync_status }}</p>@endforeach
</article>
<article>
    <p class="eyebrow">Format-aware placement</p><h3>Create placement</h3>
    <form class="form-stack" method="POST" action="{{ route('admin.sites.inventory.placements.store', $site) }}">@csrf
        <label>Name<input class="hm-input" name="name" required></label><label>Code<input class="hm-input" name="code" required></label>
        <label>Ad unit<select class="hm-input" name="ad_unit_id"><option value="">Native only</option>@foreach($site->adUnits as $unit)<option value="{{ $unit->id }}">{{ $unit->code }}</option>@endforeach</select></label>
        <label>Format<select class="hm-input" name="ad_format_id"><option value="">Custom</option>@foreach($adFormats as $format)<option value="{{ $format->id }}">{{ $format->display_name }}</option>@endforeach</select></label>
        <label>Type<select class="hm-input" name="type">@foreach(\App\Enums\PlacementType::cases() as $type)<option value="{{ $type->value }}">{{ $type->value }}</option>@endforeach</select></label>
        <input type="hidden" name="status" value="ACTIVE"><label>Sizes<input class="hm-input" name="sizes_text" placeholder="Optional when the format supplies defaults"></label>
        <label>Responsive rules<textarea class="hm-input" name="responsive_text" placeholder="1024x0-1920x1200|DESKTOP|728x90&#10;0x0-767x1200|MOBILE|300x250"></textarea></label>
        <label>Format settings JSON<textarea class="hm-input" name="format_settings_json">{}</textarea></label><label>Targeting<textarea class="hm-input" name="targeting_text" placeholder="position=article_top"></textarea></label>
        <input type="hidden" name="lazy_fetch_margin_percent" value="500"><input type="hidden" name="lazy_render_margin_percent" value="200"><input type="hidden" name="lazy_mobile_scaling" value="2">
        <label><input type="checkbox" name="lazy_load_enabled" value="1" checked> Lazy load</label><label><input type="checkbox" name="collapse_empty_div" value="1" checked> Collapse empty</label>
        <button class="hm-button-primary">Create and publish</button>
    </form>
</article>
</section>

<section class="detail-grid">
<article>
    <p class="eyebrow">Delivery settings</p><h3>Loader and GPT</h3>
    <form class="form-stack" method="POST" action="{{ route('admin.sites.config.update', $site) }}">@csrf @method('PUT')
        <label>Loader release<select class="hm-input" name="loader_release_id"><option value="">Active default</option>@foreach($loaderReleases as $release)<option value="{{ $release->id }}" @selected($site->siteConfig?->loader_release_id === $release->id)>{{ $release->version }}{{ $release->is_active ? ' · active' : '' }}</option>@endforeach</select></label>
        <label>GPT tag version<select class="hm-input" name="tag_version_id"><option value="">Active default</option>@foreach($tagVersions as $tag)<option value="{{ $tag->id }}" @selected($site->siteConfig?->tag_version_id === $tag->id)>{{ $tag->version }}{{ $tag->is_active ? ' · active' : '' }}</option>@endforeach</select></label>
        <label>Cache TTL seconds<input class="hm-input" type="number" min="0" max="86400" name="cache_ttl_seconds" value="{{ $site->siteConfig?->cache_ttl_seconds ?? 60 }}" required></label>
        <label><input type="hidden" name="debug_enabled" value="0"><input type="checkbox" name="debug_enabled" value="1" @checked($site->siteConfig?->debug_enabled)> Debug diagnostics</label>
        <label><input type="hidden" name="house_ad_testing" value="0"><input type="checkbox" name="house_ad_testing" value="1" @checked($site->siteConfig?->house_ad_testing)> House-ad testing mode</label>
        <label><input type="hidden" name="single_request_mode" value="0"><input type="checkbox" name="single_request_mode" value="1" @checked($site->siteConfig?->single_request_mode ?? true)> GPT single-request mode</label>
        @php($clickGuard = $site->siteConfig?->click_guard_settings ?? [])
        <div>
            <p class="eyebrow">Click protection</p>
            <p class="muted">Temporarily stops new advertising requests in this browser after repeated detected interactions with Horus-managed advertising iframes. Detection is heuristic and browser-dependent.</p>
            <label><input type="hidden" name="click_guard_enabled" value="0"><input type="checkbox" name="click_guard_enabled" value="1" @checked((bool) old('click_guard_enabled', $clickGuard['enabled'] ?? false))> Enable Click Protection</label>
            <label>Maximum detected ad clicks<input class="hm-input" type="number" min="1" max="50" name="click_guard_max_clicks" value="{{ old('click_guard_max_clicks', $clickGuard['maxClicks'] ?? 3) }}" required></label>
            <label>Within<input class="hm-input" type="number" min="1" max="168" name="click_guard_window_hours" value="{{ old('click_guard_window_hours', $clickGuard['windowHours'] ?? 6) }}" required><span class="muted">Hours</span></label>
            <label>Block ads for<input class="hm-input" type="number" min="1" max="720" name="click_guard_block_hours" value="{{ old('click_guard_block_hours', $clickGuard['blockHours'] ?? 12) }}" required><span class="muted">Hours</span></label>
        </div>
        <label>Privacy/CMP JSON<textarea class="hm-input" rows="7" name="privacy_settings_json">{{ json_encode($site->siteConfig?->privacy_settings ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
        <label>GPT 2026 JSON<textarea class="hm-input" rows="6" name="gpt_settings_json">{{ json_encode($site->siteConfig?->gpt_settings ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
        <label>Supply chain JSON<textarea class="hm-input" rows="6" name="supply_chain_settings_json">{{ json_encode($site->siteConfig?->supply_chain_settings ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
        <label>Observability JSON<textarea class="hm-input" rows="5" name="observability_settings_json">{{ json_encode($site->siteConfig?->observability_settings ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</textarea></label>
        <button class="hm-button-secondary">Save settings</button>
    </form>
</article>
</section>
@endsection
