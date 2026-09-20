@extends('layouts.admin')
@section('title', 'Quick Monetize')
@section('heading', 'Quick Monetize')
@section('content')
@php
    $hasBlockingReason = $blockingReasons !== [];
    $groupedSites = $sites->groupBy(fn ($site) => $site->publisher?->display_name ?? 'Unassigned');
    // Preserve the original preset keys (responsive_display, sticky_bottom, ...).
    // Without preserveKeys=true, Collection::groupBy() reindexes each group to 0..N,
    // causing the UI to submit numeric values that fail backend validation.
    $presetGroups = collect($quickPresets)->groupBy(fn ($preset) => $preset['group'] ?? 'Formats', true);
    $oldMode = old('placement_mode', old('placement_id') ? 'existing' : 'new');
    $oldInputType = old('tag_input_type', str_starts_with(trim((string) old('tag', '')), '/') ? 'GAM_AD_UNIT_PATH' : 'PROVIDER_TAG');
@endphp

<section class="hero workspace-section">
    <div>
        <p class="eyebrow">Provider-agnostic Direct Demand</p>
        <h2>Paste the tag. Horus handles the wiring.</h2>
        <p>Horus can create the placement and wire Direct Demand in one transaction. The tag may come from Google Ad Manager / AdX or another reviewed ad provider; the inventory surface is not tied to a demand network.</p>
        <div class="status-row">
            <span class="pill">MASTER {{ collect($blockingReasons)->contains('Direct Demand master is paused.') ? 'PAUSED' : 'ON' }}</span>
            <span class="pill">{{ $network?->name ?? 'CONNECTOR MISSING' }}</span>
            <span class="pill">{{ $sites->count() }} ACTIVE WEBSITE(S)</span>
        </div>
        <div class="status-row" style="margin-top:1rem">
            <a class="hm-button-secondary button-link" href="{{ route('admin.demand.index') }}">← Direct Demand</a>
            <a class="section-anchor" href="{{ route('admin.demand.accounts.create') }}">Advanced setup</a>
        </div>
    </div>
</section>

@if($hasBlockingReason)
    <article class="workspace-section">
        <p class="eyebrow">Activation blocked</p>
        <h2>Quick Monetize is paused safely</h2>
        @foreach($blockingReasons as $reason)
            <p class="error">{{ $reason }}</p>
        @endforeach
        <p class="muted">Horus will not silently override platform or connector kill switches. Resolve the control above, then return here.</p>
    </article>
@endif

@if(session('quick_account_id'))
    <article class="workspace-section">
        <p class="eyebrow">Configuration saved · delivery pending</p>
        <h2>Production configuration queued</h2>
        <p class="muted">The placement, provider isolation policy, publisher demand account and Direct Demand mappings were created or updated atomically.</p>
        <p class="muted">Saved does not mean live. The placement becomes available to visitors after the queued configuration reaches the CDN. Check delivery status before testing an ad.</p>
        <div class="status-row">
            <a class="hm-button-secondary button-link" href="{{ route('admin.demand.accounts.show', session('quick_account_id')) }}">Open generated demand account →</a>
            <a class="section-anchor" href="{{ route('admin.sites.inventory.index', ['site' => $selectedSiteId]) }}">Open Inventory →</a>
            @can('operations.view')<a class="section-anchor" href="{{ route('admin.operations.index') }}">Check CDN delivery →</a>@endcan
        </div>
    </article>
@endif

@if($codeSite = $sites->firstWhere('id', $selectedSiteId))
    <x-responsive-bundle-codes :site="$codeSite" />
@endif

<article class="workspace-section">
    <div class="workspace-heading">
        <div>
            <p class="eyebrow">Website · surface · tag</p>
            <h2>Activate an ad</h2>
        </div>
    </div>

    @error('quick')<p class="error">{{ $message }}</p>@enderror

    @if($sites->isEmpty())
        <div class="domain-card">
            <h3>No active publisher websites are ready</h3>
            <p class="muted">Add and activate a publisher website first. A placement is no longer required in advance; Quick Monetize can create it for you.</p>
            <a class="hm-button-secondary button-link" href="{{ route('admin.sites.index') }}">Open Websites</a>
        </div>
    @else
        <form class="form-grid" method="POST" action="{{ route('admin.demand.quick.store') }}" id="quick-monetize-form">
            @csrf
            <input type="hidden" name="placement_mode" id="quick-placement-mode" value="{{ $oldMode }}">

            <label>Website
                <select class="hm-input" name="site_id" id="quick-site" required @disabled($hasBlockingReason)>
                    <option value="">Select website</option>
                    @foreach($groupedSites as $publisherName => $publisherSites)
                        <optgroup label="{{ $publisherName }}">
                            @foreach($publisherSites as $site)
                                <option value="{{ $site->id }}" @selected(old('site_id', $selectedSiteId) === $site->id)>
                                    {{ $site->display_name }} · {{ $site->primary_domain }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <span class="muted">Publisher ownership and commercial defaults are inferred from the website.</span>
                @error('site_id')<span class="error">{{ $message }}</span>@enderror
            </label>

            <label id="quick-preset-wrap" style="{{ $oldMode === 'existing' ? 'display:none;' : '' }}">Ad format / surface
                <select class="hm-input" name="placement_preset" id="quick-preset" @disabled($hasBlockingReason || $oldMode === 'existing')>
                    @foreach($presetGroups as $groupName => $groupPresets)
                        <optgroup label="{{ $groupName }}">
                            @foreach($groupPresets as $key => $preset)
                                <option value="{{ $key }}" data-placement-type="{{ $preset['type'] }}" @selected(old('placement_preset', 'responsive_display') === $key)>
                                    {{ $preset['label'] }}{{ !empty($preset['badge']) ? ' · '.$preset['badge'] : '' }}
                                </option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
                <span class="muted" id="quick-preset-help">Responsive Display creates four centered manual placements from one provider tag or GAM ad unit path. Copy each placement's DIV into its exact position on the publisher website.</span>
                @error('placement_preset')<span class="error">{{ $message }}</span>@enderror
            </label>

            <label class="full" style="display:flex;gap:.65rem;align-items:center">
                <input type="checkbox" id="quick-use-existing" value="1" @checked($oldMode === 'existing') @disabled($hasBlockingReason)>
                <span>Use an existing placement instead <span class="muted">(Advanced — selecting a responsive bundle member updates all four)</span></span>
            </label>

            <label class="full" id="quick-existing-wrap" style="{{ $oldMode === 'existing' ? '' : 'display:none;' }}">Existing placement
                <select class="hm-input" name="placement_id" id="quick-placement" @disabled($hasBlockingReason || $oldMode !== 'existing')>
                    <option value="">Select placement</option>
                    @foreach($sites as $site)
                        @foreach($site->placements as $placement)
                            @php
                                $sizes = $placement->sizes
                                    ->where('is_active', true)
                                    ->map(fn ($size) => $size->width && $size->height ? $size->width.'×'.$size->height : $size->size_type)
                                    ->filter()
                                    ->unique()
                                    ->implode(', ');
                            @endphp
                            <option value="{{ $placement->id }}"
                                    data-site-id="{{ $site->id }}"
                                    data-placement-type="{{ $placement->type->value }}"
                                    data-preset="{{ data_get($placement->metadata, 'placement_preset', '') }}"
                                    @selected(old('placement_id') === $placement->id)>
                                {{ $placement->name }} · {{ $placement->code }}{{ $sizes ? ' · '.$sizes : '' }}
                            </option>
                        @endforeach
                    @endforeach
                </select>
                <span class="muted" id="quick-placement-help">Only active placements for the selected website are shown.</span>
                @error('placement_id')<span class="error">{{ $message }}</span>@enderror
            </label>

            <label class="full" id="quick-input-type-wrap">Ad input
                <select class="hm-input" name="tag_input_type" id="quick-input-type" @disabled($hasBlockingReason)>
                    <option value="PROVIDER_TAG" @selected($oldInputType !== 'GAM_AD_UNIT_PATH')>Full provider tag · GPT or another provider</option>
                    <option value="GAM_AD_UNIT_PATH" @selected($oldInputType === 'GAM_AD_UNIT_PATH')>GAM ad unit path · /Network_Code/Adunit_Code</option>
                </select>
                <span class="muted">Use a GAM path to let Horus configure the selected format's sizes. Full tags keep their declared creative sizes and supported provider settings.</span>
                @error('tag_input_type')<span class="error">{{ $message }}</span>@enderror
            </label>

            <label class="full"><span id="quick-tag-label">VAST URL or provider-issued ad tag</span>
                <textarea class="hm-input" rows="12" name="tag" id="quick-tag" required @disabled($hasBlockingReason) placeholder="Paste a complete GPT or supported provider tag.">{{ old('tag') }}</textarea>
                <span class="muted" id="quick-tag-help">Complete provider code keeps its provider-managed lifecycle. A plain VAST/VMAP URL runs in the Horus video player. Choose Rewarded Video for an explicit opt-in flow with a compatible rewarded VAST provider; a normal AdX video tag is not a GAM rewarded unit.</span>
                <span class="muted" id="quick-size-help"></span>
                @error('tag')<span class="error">{{ $message }}</span>@enderror
            </label>

            <div class="full domain-card">
                <p class="eyebrow">Automatic behind the button</p>
                <p class="muted">Placement + responsive policy · safe auto-mount · Demand account · Publisher scope · MANUAL_TAG · Website mapping · Widget · CSP allowlist · renderer-conflict validation · production config. A failed review rolls the entire operation back.</p>
            </div>

            <div class="full status-row">
                <button class="hm-button-primary" id="quick-submit" @disabled($hasBlockingReason)>Activate Ad</button>
                <a class="section-anchor" href="{{ route('admin.demand.accounts.create') }}">Provider-managed / custom surface? Advanced setup →</a>
            </div>
        </form>
    @endif
</article>

<script>
(() => {
    const site = document.getElementById('quick-site');
    const preset = document.getElementById('quick-preset');
    const presetWrap = document.getElementById('quick-preset-wrap');
    const useExisting = document.getElementById('quick-use-existing');
    const mode = document.getElementById('quick-placement-mode');
    const existingWrap = document.getElementById('quick-existing-wrap');
    const placement = document.getElementById('quick-placement');
    const tag = document.getElementById('quick-tag');
    const inputType = document.getElementById('quick-input-type');
    const inputTypeWrap = document.getElementById('quick-input-type-wrap');
    const tagLabel = document.getElementById('quick-tag-label');
    const tagHelp = document.getElementById('quick-tag-help');
    const sizeHelp = document.getElementById('quick-size-help');
    const submit = document.getElementById('quick-submit');
    const help = document.getElementById('quick-placement-help');
    if (!site || !preset || !useExisting || !mode || !existingWrap || !placement || !submit) return;

    const blocked = {{ $hasBlockingReason ? 'true' : 'false' }};
    const allOptions = [...placement.querySelectorAll('option[data-site-id]')];

    const refreshInput = () => {
        if (!inputType || !tag) return;
        const option = useExisting.checked ? placement.selectedOptions[0] : preset.selectedOptions[0];
        const type = option?.dataset.placementType || '';
        const key = useExisting.checked ? option?.dataset.preset : preset.value;
        const video = type === 'VIDEO';
        const pathSupported = ['DISPLAY', 'STICKY', 'REWARDED'].includes(type);
        inputType.querySelector('[value="GAM_AD_UNIT_PATH"]').disabled = !pathSupported;
        inputTypeWrap.style.display = video ? 'none' : '';
        inputType.disabled = blocked || video;
        if (!video && !pathSupported) inputType.value = 'PROVIDER_TAG';
        const path = !video && inputType.value === 'GAM_AD_UNIT_PATH';
        tag.rows = path ? 2 : 12;
        tagLabel.textContent = path ? 'Google Ad Manager ad unit path' : (video ? 'VAST URL or provider-issued video tag' : 'Full provider tag');
        tag.placeholder = path ? '/1234567/ad_unit' : (video ? 'HTTPS VAST/VMAP URL or a complete supported video provider tag.' : 'Paste a complete GPT or supported provider tag.');
        tagHelp.textContent = path
            ? (type === 'REWARDED'
                ? 'Paste /NetworkCode/AdUnitCode. Horus uses official GPT Rewarded: the visitor opts in and content access follows Google’s reward grant event. No display sizes are applied.'
                : 'Paste only /Network_Code/Adunit_Code. Horus creates a separate GPT slot for each placement and uses that placement’s active sizes and responsive settings.')
            : (video
                ? 'A plain VAST/VMAP URL runs in the Horus video player. Complete provider code keeps its provider-managed lifecycle.'
                : 'Paste the complete supported GPT or third-party tag. Its declared sizes are checked against this placement. Choose GAM ad unit path above if you only have the unit path.');
        sizeHelp.textContent = key === 'responsive_display'
            ? 'Responsive supports 13 sizes: 970×250, 970×90, 728×90, 468×60, 336×280, 320×100, 320×50, 300×600, 300×250, 300×100, 300×50, 250×250 and 200×200. Each request uses only sizes that fit its device and container; 300×600 is tablet/desktop only.'
            : (type === 'STICKY' && path ? 'The selected placement keeps its own top, bottom or side position, supported sizes, close control and responsive settings.' : '');
    };

    const refreshPlacements = () => {
        const siteId = site.value;
        let firstVisible = null;
        let selectedIsVisible = false;
        allOptions.forEach(option => {
            const visible = siteId !== '' && option.dataset.siteId === siteId;
            option.hidden = !visible;
            option.disabled = !visible;
            if (visible && !firstVisible) firstVisible = option;
            if (visible && option.selected) selectedIsVisible = true;
        });
        if (!selectedIsVisible) placement.value = firstVisible ? firstVisible.value : '';
        if (help) {
            help.textContent = firstVisible
                ? 'Only active placements for the selected website are shown.'
                : (siteId ? 'No existing placement on this website. Turn off Advanced mode and let Horus create one.' : 'Select a website first.');
        }
    };

    const refreshMode = () => {
        const existing = useExisting.checked;
        mode.value = existing ? 'existing' : 'new';

        // Use inline display state instead of the hidden attribute because the
        // admin form CSS can define label display rules that override UA hidden styling.
        existingWrap.style.display = existing ? '' : 'none';
        presetWrap.style.display = existing ? 'none' : '';

        placement.required = existing;
        preset.required = !existing;
        placement.disabled = blocked || !existing;
        preset.disabled = blocked || existing;

        refreshPlacements();
        refreshInput();
        submit.disabled = blocked || !site.value || (existing ? !placement.value : !preset.value);
    };

    site.addEventListener('change', refreshMode);
    placement.addEventListener('change', refreshMode);
    preset.addEventListener('change', refreshMode);
    useExisting.addEventListener('change', refreshMode);
    if (inputType) inputType.addEventListener('change', refreshInput);
    if (tag) tag.addEventListener('input', () => {
        const value = tag.value.trim();
        if ((!inputType || inputType.value === 'PROVIDER_TAG') && !useExisting.checked && /^https:\/\/\S+$/i.test(value) && preset.value === 'responsive_display') {
            preset.value = 'video_floating';
            refreshMode();
        }
    });
    refreshMode();
})();
</script>
@endsection
