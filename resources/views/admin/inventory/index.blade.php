@extends('layouts.admin')
@section('title', $site->display_name.' Inventory')
@section('heading', 'Inventory · '.$site->display_name)
@section('navigation')
<a href="{{ route('dashboard') }}">Dashboard</a>
<a href="{{ route('admin.sites.index') }}">Websites</a>
<a class="active" href="{{ route('admin.sites.inventory.index', $site) }}">Inventory</a>
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
    <p class="muted">This script remains unchanged when the serving mode, GAM connection, placements, or configuration version changes.</p>
    <code class="installation-code">{{ $site->installationCode() }}</code>
</article>
<article>
    <p class="eyebrow">Static configuration</p><h3>Publish controls</h3>
    <form class="inline-form" method="POST" action="{{ route('admin.sites.config.publish', $site) }}">@csrf<select class="hm-input" name="environment"><option>PREVIEW</option><option>TEST</option><option selected>PRODUCTION</option></select><button class="hm-button-primary">Publish</button></form>
    <form class="inline-form" method="GET" target="_blank" action="{{ route('admin.sites.config.preview', $site) }}"><select class="hm-input" name="environment"><option>PREVIEW</option><option>TEST</option><option>PRODUCTION</option></select><button class="hm-button-secondary">Preview JSON</button></form>
    <div class="status-row"><span class="pill">Preview v{{ $site->siteConfig?->preview_version ?? 0 }}</span><span class="pill">Test v{{ $site->siteConfig?->test_version ?? 0 }}</span><span class="pill">Production v{{ $site->siteConfig?->production_version ?? 0 }}</span></div>
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
<article>
    <p class="eyebrow">Emergency delivery control</p><h3>Immediate static pause</h3>
    <p class="muted">Publishing a paused production file prevents the loader from loading GPT or requesting ads. No Laravel request is made by the browser.</p>
    <form class="form-stack danger-zone" method="POST" action="{{ route('admin.sites.config.pause', $site) }}">@csrf<input class="hm-input" name="reason" placeholder="Required operational reason" required><button class="hm-button-danger">Pause site now</button></form>
    <form class="form-stack" method="POST" action="{{ route('admin.sites.config.resume', $site) }}">@csrf<input class="hm-input" name="reason" placeholder="Required resume reason" required><button class="hm-button-primary">Resume and publish</button></form>
</article>
</section>

<article>
<div class="section-heading"><div><p class="eyebrow">Local inventory</p><h2>Ad units</h2></div></div>
<form class="form-grid" method="POST" action="{{ route('admin.sites.inventory.ad-units.store', $site) }}">@csrf
    <label>Name<input class="hm-input" name="name" placeholder="Article Top" required></label>
    <label>Code<input class="hm-input" name="code" placeholder="article_top" required></label>
    <label class="full">Fixed sizes<input class="hm-input" name="sizes_text" placeholder="300x250,336x280,728x90" required></label>
    <label class="full">Description<textarea class="hm-input" name="description" rows="2"></textarea></label>
    <label><input type="hidden" name="is_enabled" value="0"><input type="checkbox" name="is_enabled" value="1" checked> Enabled</label>
    <div><button class="hm-button-primary">Create ad unit</button></div>
</form>
<div class="table-wrap"><table><thead><tr><th>Ad unit</th><th>Sizes</th><th>Sync</th><th>Remote ID</th><th>Actions</th></tr></thead><tbody>
@forelse($site->adUnits as $adUnit)
<tr><td><strong>{{ $adUnit->name }}</strong><br><code>{{ $adUnit->code }}</code></td><td>{{ $adUnit->sizes->map(fn($s) => $s->size_type === 'FLUID' ? 'fluid' : $s->width.'x'.$s->height)->implode(', ') }}</td><td><span class="pill">{{ $adUnit->sync_status }}</span></td><td>{{ $remoteMappings->get($adUnit->id)?->remote_object_id ?? 'Not mapped' }}</td><td><div class="status-row"><form method="POST" action="{{ route('admin.sites.inventory.ad-units.sync', [$site, $adUnit]) }}">@csrf<input type="hidden" name="dry_run" value="1"><button class="hm-button-secondary">Dry-run</button></form><form method="POST" action="{{ route('admin.sites.inventory.ad-units.resync', [$site, $adUnit]) }}">@csrf<input type="hidden" name="dry_run" value="0"><button class="hm-button-primary">Synchronize</button></form></div></td></tr>
@empty<tr><td colspan="5" class="muted">No local ad units yet.</td></tr>@endforelse
</tbody></table></div>
</article>

<article>
<div class="section-heading"><div><p class="eyebrow">Browser slots</p><h2>Create placement</h2></div></div>
<form class="form-grid" method="POST" action="{{ route('admin.sites.inventory.placements.store', $site) }}">@csrf
    <label>Name<input class="hm-input" name="name" placeholder="Article top slot" required></label>
    <label>Code<input class="hm-input" name="code" placeholder="article_top" required></label>
    <label>Ad unit<select class="hm-input" name="ad_unit_id"><option value="">No GAM ad unit</option>@foreach($site->adUnits as $adUnit)<option value="{{ $adUnit->id }}">{{ $adUnit->name }} · {{ $adUnit->code }}</option>@endforeach</select></label>
    <label>Type<select class="hm-input" name="type">@foreach(\App\Enums\PlacementType::cases() as $type)<option>{{ $type->value }}</option>@endforeach</select></label>
    <label>Status<select class="hm-input" name="status">@foreach(\App\Enums\PlacementStatus::cases() as $status)<option>{{ $status->value }}</option>@endforeach</select></label>
    <label>Fixed sizes<input class="hm-input" name="sizes_text" value="300x250" required></label>
    <label class="full">Responsive mappings<textarea class="hm-input" rows="3" name="responsive_text" placeholder="768x0|DESKTOP|728x90,970x250&#10;0x0|MOBILE|300x250,320x100"></textarea></label>
    <label class="full">Placement targeting<textarea class="hm-input" rows="3" name="targeting_text" placeholder="position=article_top&#10;section=news,business"></textarea></label>
    <label>Lazy fetch margin %<input class="hm-input" type="number" name="lazy_fetch_margin_percent" value="500" required></label>
    <label>Lazy render margin %<input class="hm-input" type="number" name="lazy_render_margin_percent" value="200" required></label>
    <label>Mobile scaling<input class="hm-input" type="number" step=".1" name="lazy_mobile_scaling" value="2" required></label>
    <label>Refresh seconds<input class="hm-input" type="number" min="30" name="refresh_interval_seconds"></label>
    <label>Refresh limit<input class="hm-input" type="number" min="1" name="refresh_limit"></label>
    <label>Sort order<input class="hm-input" type="number" name="sort_order" value="0"></label>
    <label><input type="hidden" name="lazy_load_enabled" value="0"><input type="checkbox" name="lazy_load_enabled" value="1" checked> Lazy loading</label>
    <label><input type="hidden" name="refresh_enabled" value="0"><input type="checkbox" name="refresh_enabled" value="1"> Refresh</label>
    <label><input type="hidden" name="collapse_empty_div" value="0"><input type="checkbox" name="collapse_empty_div" value="1" checked> Collapse empty div</label>
    <label><input type="hidden" name="safeframe_enabled" value="0"><input type="checkbox" name="safeframe_enabled" value="1"> Force SafeFrame</label>
    <div class="full"><button class="hm-button-primary">Create placement</button></div>
</form>
</article>

<article><p class="eyebrow">Placement installation</p><h2>Codes and delivery status</h2>
@forelse($site->placements as $placement)
@php($fixedText = $placement->sizes->filter(fn($s) => $s->min_viewport_width === 0)->map(fn($s) => $s->size_type === 'FLUID' ? 'fluid' : $s->width.'x'.$s->height)->implode(','))
<div class="domain-card"><div><strong>{{ $placement->name }}</strong> <span class="pill">{{ $placement->type->value }}</span> <span class="pill">{{ $placement->status->value }}</span></div><code class="installation-code">{{ $placement->installationCode() }}</code><p class="muted">{{ $placement->adUnit?->code ?? 'No ad unit' }} · {{ $fixedText }}</p>
<form class="inline-form" method="POST" action="{{ route('admin.sites.inventory.placements.update', [$site, $placement]) }}">@csrf @method('PUT')
<input type="hidden" name="name" value="{{ $placement->name }}"><input type="hidden" name="code" value="{{ $placement->code }}"><input type="hidden" name="type" value="{{ $placement->type->value }}"><input type="hidden" name="ad_unit_id" value="{{ $placement->ad_unit_id }}"><input type="hidden" name="sizes_text" value="{{ $fixedText ?: 'fluid' }}"><input type="hidden" name="lazy_fetch_margin_percent" value="{{ $placement->lazy_fetch_margin_percent }}"><input type="hidden" name="lazy_render_margin_percent" value="{{ $placement->lazy_render_margin_percent }}"><input type="hidden" name="lazy_mobile_scaling" value="{{ $placement->lazy_mobile_scaling }}"><input type="hidden" name="sort_order" value="{{ $placement->sort_order }}"><input type="hidden" name="collapse_empty_div" value="{{ $placement->collapse_empty_div ? 1 : 0 }}"><input type="hidden" name="lazy_load_enabled" value="{{ $placement->lazy_load_enabled ? 1 : 0 }}"><input type="hidden" name="refresh_enabled" value="{{ $placement->refresh_enabled ? 1 : 0 }}"><input type="hidden" name="safeframe_enabled" value="{{ $placement->safeframe_enabled ? 1 : 0 }}"><select class="hm-input" name="status">@foreach(\App\Enums\PlacementStatus::cases() as $status)<option value="{{ $status->value }}" @selected($placement->status === $status)>{{ $status->value }}</option>@endforeach</select><button class="hm-button-secondary">Update status</button></form>
</div>
@empty<p class="muted">No placements yet.</p>@endforelse
</article>

<section class="detail-grid">
<article><p class="eyebrow">Bulk creation</p><h3>Create many placements</h3><p class="muted">One line: code|name|type|ad_unit_code|sizes</p><form class="form-stack" method="POST" action="{{ route('admin.sites.inventory.placements.bulk', $site) }}">@csrf<textarea class="hm-input" rows="6" name="bulk_text" placeholder="article_top|Article Top|DISPLAY|article_top|300x250,336x280" required></textarea><button class="hm-button-secondary">Bulk create</button></form></article>
<article><p class="eyebrow">Page targeting</p><h3>Global GPT targeting</h3><form class="form-stack" method="POST" action="{{ route('admin.sites.inventory.page-targeting', $site) }}">@csrf<textarea class="hm-input" rows="6" name="targeting_text" placeholder="site=publisher_one&#10;language=en">@foreach($site->targeting->whereNull('placement_id') as $item){{ $item->targeting_key }}={{ implode(',', $item->targeting_values) }}&#10;@endforeach</textarea><button class="hm-button-secondary">Save targeting</button></form></article>
</section>

<section class="detail-grid">
<article><p class="eyebrow">Layout duplication</p><h3>Copy this layout</h3><form class="form-stack" method="POST" action="{{ route('admin.sites.inventory.duplicate-layout', $site) }}">@csrf<select class="hm-input" name="target_site_id" required>@foreach($otherSites as $other)<option value="{{ $other->id }}">{{ $other->display_name }} · {{ $other->primary_domain }}</option>@endforeach</select><button class="hm-button-secondary">Duplicate layout</button></form></article>
<article><p class="eyebrow">Configuration history</p><h3>Rollback</h3>@forelse($site->configVersions->sortByDesc('created_at') as $version)<div class="event"><div><strong>{{ $version->environment->value }} v{{ $version->version }}</strong><br><span>{{ substr($version->checksum, 0, 12) }} · {{ $version->status->value }}</span></div><form method="POST" action="{{ route('admin.sites.config.rollback', [$site, $version]) }}">@csrf<button class="hm-button-secondary">Rollback</button></form></div>@empty<p class="muted">No published versions yet.</p>@endforelse</article>
</section>
@endsection
