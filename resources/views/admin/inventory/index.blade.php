@extends('layouts.admin')
@section('title', $site->display_name.' Inventory')
@section('heading', 'Inventory · '.$site->display_name)
@section('navigation')
<a href="{{ route('dashboard') }}">Dashboard</a>
<a href="{{ route('admin.sites.index') }}">Websites</a>
<a class="active" href="{{ route('admin.sites.inventory.index', $site) }}">Inventory</a>
@if(auth()->user()->hasPermission('demand.view'))<a href="{{ route('admin.sites.demand.index', $site) }}">Native demand</a>@endif
<a href="{{ route('admin.gam.connections.index') }}">Google Ad Manager</a>
@endsection
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
    <p class="muted">Saving creates a pending outbox item. Operations confirms the separate Cloudflare Pages deployment.</p>
    <form class="inline-form" method="POST" action="{{ route('admin.sites.config.publish', $site) }}">@csrf<select class="hm-input" name="environment"><option>PREVIEW</option><option>TEST</option><option selected>PRODUCTION</option></select><button class="hm-button-primary">Queue delivery</button></form>
    <form class="inline-form" method="GET" target="_blank" action="{{ route('admin.sites.config.preview', $site) }}"><select class="hm-input" name="environment"><option>PREVIEW</option><option>TEST</option><option>PRODUCTION</option></select><button class="hm-button-secondary">Preview JSON</button></form>
    <div class="status-row"><span class="pill">Preview v{{ $site->siteConfig?->preview_version ?? 0 }}</span><span class="pill">Test v{{ $site->siteConfig?->test_version ?? 0 }}</span><span class="pill">Production v{{ $site->siteConfig?->production_version ?? 0 }}</span></div>
    @if($site->configVersions->first())<p class="muted">Latest request: {{ str_replace('_', ' ', $site->configVersions->first()->status->value) }} · {{ $site->configVersions->first()->environment->value }} v{{ $site->configVersions->first()->version }}</p>@endif
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
        <button class="hm-button-secondary">Save settings</button>
    </form>
</article>
</section>
@endsection
