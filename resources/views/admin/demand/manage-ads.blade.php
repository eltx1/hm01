@extends('layouts.admin')
@section('title', 'Website ads')
@section('heading', 'Website ads')
@section('content')
<section class="hero workspace-section">
    <div>
        <p class="eyebrow">Quick Monetize · Manage</p>
        <h2>{{ $site ? $site->display_name : 'Your website ads, in one place' }}</h2>
        <p>{{ $site ? $site->primary_domain : 'Choose a website to manage its Quick Monetize ads.' }}</p>
        <div class="status-row">
            <a class="hm-button-primary button-link" href="{{ route('admin.demand.quick.create', $site ? ['site' => $site->id] : []) }}">+ Add an ad</a>
            @if($site && auth()->user()->hasPermission('sites.view'))<a class="hm-button-secondary button-link" href="{{ route('admin.sites.show', $site) }}">Website overview</a>@endif
        </div>
    </div>
</section>

<section class="workspace-section">
    <form method="GET" action="{{ route('admin.demand.quick.manage') }}" class="quick-ads-picker">
        <label>Website
            <select class="hm-input" name="site" required>
                <option value="">Choose a website</option>
                @foreach($sites->groupBy(fn ($item) => $item->publisher?->display_name ?? 'Websites') as $publisher => $items)
                    <optgroup label="{{ $publisher }}">
                        @foreach($items as $item)<option value="{{ $item->id }}" @selected($site?->id === $item->id)>{{ $item->display_name }} · {{ $item->primary_domain }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select>
        </label>
        <button class="hm-button-secondary">Show ads</button>
    </form>
</section>

@if($site)
    @php
        $current = $placements->where('status', '!=', \App\Enums\PlacementStatus::Disabled);
        $removed = $placements->where('status', \App\Enums\PlacementStatus::Disabled);
        $deliveryStatus = $productionVersion?->deliveryItem?->status?->value ?? 'NOT_PUBLISHED';
    @endphp
    <section class="workspace-section quick-ads-summary">
        <div class="status-row" aria-label="Ad counts">
            <span class="pill">{{ $current->where('status', \App\Enums\PlacementStatus::Active)->count() }} enabled</span>
            <span class="pill">{{ $current->where('status', \App\Enums\PlacementStatus::Paused)->count() }} paused</span>
            <span class="pill">{{ $removed->count() }} removed</span>
        </div>
        <p>Pause or remove the exact placement you want to stop. Each action applies to this website only.</p>
        <p class="muted">Changes take effect after CDN delivery and the visitor's next page load. The installed website code stays the same.</p>
        <div class="status-row"><span>Latest website update</span><x-status-badge :status="$deliveryStatus" />
            @if(auth()->user()->hasPermission('operations.view'))<a class="section-anchor" href="{{ route('admin.operations.index') }}">Delivery details</a>@endif
        </div>
    </section>
    @error('action')<p class="error" role="alert">{{ $message }}</p>@enderror
    <div class="quick-ads-grid" aria-label="Current website ads">
        @forelse($current as $placement)
            @include('admin.demand.partials.quick-ad-card')
        @empty
            <article class="workspace-section"><h3>No current Quick Monetize ads</h3><p class="muted">Add an ad or restore one from Removed ads below.</p></article>
        @endforelse
    </div>
    @if($removed->isNotEmpty())
        <details class="workspace-section quick-ads-removed">
            <summary>Removed ads ({{ $removed->count() }})</summary>
            <p class="muted">Restore an ad to the paused list, then resume it when ready. Its settings and reporting history are preserved.</p>
            <div class="quick-ads-grid">
                @foreach($removed as $placement)@include('admin.demand.partials.quick-ad-card')@endforeach
            </div>
        </details>
    @endif
@endif
@endsection
