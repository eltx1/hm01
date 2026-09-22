@props(['site'])
@php
    $targetCount = \App\Services\Inventory\PlacementPresetBuilder::RESPONSIVE_BUNDLE_SIZE;
    $units = $site->placements
        ->filter(fn ($placement) => data_get($placement->metadata, 'responsive_bundle') === 'v1')
        ->sortBy(fn ($placement) => (int) data_get($placement->metadata, 'responsive_bundle_index'));
@endphp
@if($units->isNotEmpty())
<section class="workspace-section" aria-label="Responsive display installation codes">
    <div class="workspace-heading">
        <div><p class="eyebrow">{{ $site->primary_domain }} · Manual placement</p><h2>Responsive Display · {{ $units->count() }} placement codes</h2></div>
        @if(auth()->user()?->isHorusAdministrator() && auth()->user()?->hasPermission('demand.manage') && $units->count() < $targetCount)
            <form method="POST" action="{{ route('admin.sites.demand.quick-responsive.expand', $site) }}" onsubmit="return confirm('Add the missing Responsive Display placements while preserving the existing placement IDs and provider tag?');">
                @csrf
                <button class="hm-button-primary" type="submit">Expand to {{ $targetCount }} placements</button>
            </form>
        @endif
    </div>
    <p>One provider tag powers these independent placements. Keep the permanent Horus Loader installed once, then place each code at its chosen position in your page or template.</p>
    <p class="muted">Use each code once per page. You can install any or all {{ $units->count() }}. Ads appear only where their code is installed, centered within the available space. Availability depends on published configuration, visitor eligibility and provider demand.</p>
    @if(auth()->user()?->isHorusAdministrator() && auth()->user()?->hasPermission('demand.manage') && $units->count() < $targetCount)
        <p class="muted">This is a legacy {{ $units->count() }}-placement bundle. Expanding adds only the missing placements; the existing codes remain unchanged.</p>
    @endif
    <div class="detail-grid">
        @foreach($units as $unit)
            <article>
                <h3>{{ $unit->name }}</h3>
                <x-status-badge :status="$unit->status" />
                <pre class="installation-code" id="responsive-code-{{ $unit->id }}">{{ $unit->installationCode() }}</pre>
                <button class="hm-button-secondary" type="button" data-copy-target="responsive-code-{{ $unit->id }}">Copy placement {{ data_get($unit->metadata, 'responsive_bundle_index') }}</button>
            </article>
        @endforeach
    </div>
</section>
@endif
