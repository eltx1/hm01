@php
    $preset = (string) data_get($placement->metadata, 'placement_preset', '');
    $label = $presets[$preset]['label'] ?? str($placement->type->value)->headline();
    $position = match ($preset) {
        'sticky_top' => 'Top of page', 'sticky_bottom' => 'Bottom of page',
        'side_rail_left' => 'Left side · desktop', 'side_rail_right' => 'Right side · desktop',
        'video_floating' => 'Floating video', 'rewarded' => 'Optional rewarded message',
        'in_article_display', 'video_outstream' => 'Inside the article',
        'responsive_display' => 'Embedded position '.data_get($placement->metadata, 'responsive_bundle_index', ''),
        default => data_get($placement->format_settings, 'position', 'On-page placement'),
    };
    $enabled = $placement->status === \App\Enums\PlacementStatus::Active;
    $isRemoved = $placement->status === \App\Enums\PlacementStatus::Disabled;
    $runtime = $runtimePlacements->get($placement->code);
    $unavailable = $enabled && (!data_get($runtime, 'enabled') || data_get($runtime, 'renderer') !== 'DIRECT_JS');
    $previewPosition = match ($preset) { 'sticky_top' => 'top', 'sticky_bottom' => 'bottom', 'side_rail_left' => 'left', 'side_rail_right' => 'right', default => 'middle' };
@endphp
<article class="workspace-section quick-ad-card" data-quick-ad="{{ $placement->id }}">
    <div class="quick-ad-heading">
        <div class="quick-ad-preview quick-ad-preview--{{ $previewPosition }}" aria-hidden="true"><i></i><i></i><i></i><span>AD</span></div>
        <div><p class="eyebrow">{{ $label }}</p><h3>{{ $position }}</h3><p class="muted">{{ $placement->name }}</p></div>
    </div>
    <div class="status-row"><x-status-badge :status="$isRemoved ? 'DISABLED' : ($enabled ? 'ENABLED' : 'PAUSED')" />
        @if($unavailable)<span class="pill">Needs attention</span>@endif
    </div>
    @if($unavailable)<p class="muted">Enabled here, but the website or ad provider is unavailable. Check advanced settings before expecting delivery.</p>@endif
    <form method="POST" action="{{ route('admin.demand.quick.placements.update', [$site, $placement]) }}" class="quick-ad-controls">
        @csrf @method('PATCH')
        @if($isRemoved)
            <button class="hm-button-secondary" name="action" value="restore" aria-label="Restore {{ $placement->name }}">Restore paused</button>
        @else
            <button class="{{ $enabled ? 'hm-button-secondary' : 'hm-button-primary' }}" name="action" value="{{ $enabled ? 'pause' : 'resume' }}" aria-label="{{ $enabled ? 'Pause' : 'Resume' }} {{ $placement->name }}">{{ $enabled ? 'Pause ad' : 'Resume ad' }}</button>
            <details class="quick-ad-remove">
                <summary>Remove</summary>
                <p>Remove this placement from {{ $site->display_name }}? You can restore it later.</p>
                <button class="hm-button-danger" name="action" value="remove" aria-label="Remove {{ $placement->name }}">Remove from this website</button>
            </details>
        @endif
    </form>
    @if(auth()->user()->hasPermission('inventory.view'))
        <a class="section-anchor" href="{{ route('admin.sites.inventory.index', $site) }}">Advanced placement settings</a>
    @endif
</article>
