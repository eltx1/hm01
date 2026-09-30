@props(['site'])
@php
    $units = $site->placements
        ->filter(fn ($placement) => data_get($placement->metadata, 'placement_preset') === 'video_floating')
        ->filter(fn ($placement) => ! $placement->trashed())
        ->sortBy('created_at');
@endphp
@if($units->isNotEmpty())
<section id="video-placement-codes" class="workspace-section" aria-label="Accompanying video installation codes">
    <div class="workspace-heading">
        <div>
            <p class="eyebrow">{{ $site->primary_domain }} · Manual video placement</p>
            <h2>Inline → Floating Video · placement code</h2>
        </div>
    </div>
    <p>Keep the permanent Horus Loader installed once, then place this DIV inside the page body where the accompanying video should start — for example near the beginning of the article or just below the header.</p>
    <p class="muted">The player starts inline at this exact position. After it has been visible and the visitor scrolls it off the page, Horus may move the same player to the lower-right floating position. Quick Monetize accepts a VAST/VMAP URL or a GAM ad unit path and generates the full VAST URL; the platform content video is controlled globally by Horus Media.</p>
    <div class="detail-grid">
        @foreach($units as $unit)
            <article>
                <h3>{{ $unit->name }}</h3>
                <x-status-badge :status="$unit->status" />
                <pre class="installation-code" id="video-code-{{ $unit->id }}">{{ $unit->installationCode() }}</pre>
                <button class="hm-button-secondary" type="button" data-copy-target="video-code-{{ $unit->id }}">Copy video DIV</button>
            </article>
        @endforeach
    </div>
</section>
@endif
