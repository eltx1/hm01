@extends('layouts.admin')
@section('title', 'Quick Monetize')
@section('heading', 'Quick Monetize')
@section('content')
@php
    $hasBlockingReason = $blockingReasons !== [];
    $groupedSites = $sites->groupBy(fn ($site) => $site->publisher?->display_name ?? 'Unassigned');
@endphp

<section class="hero workspace-section">
    <div>
        <p class="eyebrow">One-step Direct Demand</p>
        <h2>Paste the tag. Horus handles the wiring.</h2>
        <p>Select the website and placement, paste the provider-issued ad tag, then activate. Publisher scope, demand account, site mapping, placement mapping, CSP origins, review and production publication are handled automatically.</p>
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
        <p class="eyebrow">Activation complete</p>
        <h2>Production configuration queued</h2>
        <p class="muted">The reusable publisher demand account and all mappings were created or updated automatically.</p>
        <a class="hm-button-secondary button-link" href="{{ route('admin.demand.accounts.show', session('quick_account_id')) }}">Open generated demand account →</a>
    </article>
@endif

<article class="workspace-section">
    <div class="workspace-heading">
        <div>
            <p class="eyebrow">Three inputs · one action</p>
            <h2>Activate an ad</h2>
        </div>
    </div>

    @error('quick')<p class="error">{{ $message }}</p>@enderror

    @if($sites->isEmpty())
        <div class="domain-card">
            <h3>No active publisher websites are ready</h3>
            <p class="muted">Add and activate a publisher website with at least one active placement first.</p>
            <a class="hm-button-secondary button-link" href="{{ route('admin.sites.index') }}">Open Websites</a>
        </div>
    @else
        <form class="form-grid" method="POST" action="{{ route('admin.demand.quick.store') }}" id="quick-monetize-form">
            @csrf

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
                <span class="muted">Publisher ownership is inferred from the website automatically.</span>
                @error('site_id')<span class="error">{{ $message }}</span>@enderror
            </label>

            <label>Placement
                <select class="hm-input" name="placement_id" id="quick-placement" required @disabled($hasBlockingReason)>
                    <option value="">Select placement</option>
                    @foreach($sites as $site)
                        @foreach($site->placements as $placement)
                            @php
                                $sizes = $placement->sizes
                                    ->where('is_active', true)
                                    ->map(fn ($size) => $size->width && $size->height ? $size->width.'×'.$size->height : $size->size_type)
                                    ->filter()
                                    ->implode(', ');
                            @endphp
                            <option value="{{ $placement->id }}"
                                    data-site-id="{{ $site->id }}"
                                    @selected(old('placement_id') === $placement->id)>
                                {{ $placement->name }} · {{ $placement->code }}{{ $sizes ? ' · '.$sizes : '' }}
                            </option>
                        @endforeach
                    @endforeach
                </select>
                <span class="muted" id="quick-placement-help">Only active placements for the selected website are shown.</span>
                @error('placement_id')<span class="error">{{ $message }}</span>@enderror
            </label>

            <label class="full">Provider-issued ad tag
                <textarea class="hm-input" rows="12" name="tag" id="quick-tag" required @disabled($hasBlockingReason) placeholder="Paste the complete Google Publisher Tag (GPT) or another reviewed third-party provider tag here. Nothing executes in Admin.">{{ old('tag') }}</textarea>
                <span class="muted">Horus extracts HTTPS script origins, rejects unsafe/private material, creates an isolated runtime recipe, validates the real delivery configuration, and only then queues production.</span>
                @error('tag')<span class="error">{{ $message }}</span>@enderror
            </label>

            <div class="full domain-card">
                <p class="eyebrow">Automatic behind the button</p>
                <p class="muted">Demand account · Publisher scope · MANUAL_TAG · Direct Demand enablement · Website mapping · Placement mapping · Widget · CSP allowlist · safety validation · production config.</p>
            </div>

            <div class="full status-row">
                <button class="hm-button-primary" id="quick-submit" @disabled($hasBlockingReason)>Activate Ad</button>
                <a class="section-anchor" href="{{ route('admin.demand.accounts.create') }}">Need custom controls? Advanced setup →</a>
            </div>
        </form>
    @endif
</article>

<script>
(() => {
    const site = document.getElementById('quick-site');
    const placement = document.getElementById('quick-placement');
    const submit = document.getElementById('quick-submit');
    const help = document.getElementById('quick-placement-help');
    if (!site || !placement || !submit) return;

    const allOptions = [...placement.querySelectorAll('option[data-site-id]')];
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

        if (!selectedIsVisible) {
            placement.value = firstVisible ? firstVisible.value : '';
        }

        const ready = siteId !== '' && firstVisible !== null;
        submit.disabled = !ready || {{ $hasBlockingReason ? 'true' : 'false' }};
        if (help) {
            help.textContent = ready
                ? 'Only active placements for the selected website are shown.'
                : (siteId ? 'This website has no active placement. Create/activate a placement in Inventory first.' : 'Select a website first.');
        }
    };

    site.addEventListener('change', refreshPlacements);
    refreshPlacements();
})();
</script>
@endsection
