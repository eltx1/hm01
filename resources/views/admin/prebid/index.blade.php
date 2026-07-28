@extends('layouts.admin')
@section('title', 'Prebid · '.$site->display_name)
@section('heading', 'Prebid · '.$site->display_name)
@section('navigation')
<a href="{{ route('dashboard') }}">Dashboard</a>
<a href="{{ route('admin.sites.index') }}">Websites</a>
<a href="{{ route('admin.sites.inventory.index', $site) }}">Inventory</a>
<a class="active" href="{{ route('admin.sites.prebid.index', $site) }}">Prebid</a>
<a href="{{ route('admin.gam.connections.index') }}">Google Ad Manager</a>
@endsection
@section('content')
<section class="hero">
    <div>
        <p class="eyebrow">Browser-side header bidding</p>
        <h2>{{ $site->primary_domain }}</h2>
        <p>Permanent publisher code; centralized Horus configuration and GAM automation.</p>
    </div>
    <div class="status-row">
        <span class="pill">{{ $site->serving_mode->value }}</span>
        <span class="pill">{{ $site->prebid_enabled ? 'Prebid enabled' : 'Prebid disabled' }}</span>
        <span class="pill">{{ $connection?->network_code ?: 'No GAM network' }}</span>
    </div>
</section>

<article>
    <div class="section-heading"><div><p class="eyebrow">Network scope</p><h2>Selected GAM setup</h2></div></div>
    <form method="GET" class="inline-form">
        <select class="hm-input" name="gam_connection_id">
            @foreach($connections as $item)
                <option value="{{ $item->id }}" @selected($connection?->id === $item->id)>{{ $item->name }} · {{ $item->type->value }} · {{ $item->network_code }}</option>
            @endforeach
        </select>
        <button class="hm-button-secondary">Open network configuration</button>
    </form>
    <p class="muted">
        Resolved website network: <strong>{{ $resolvedConnection?->name ?? 'Unavailable' }}</strong>.
        HORUS_GAM uses the central Horus setup; partner and publisher GAM connections use only their explicitly selected mappings.
    </p>
</article>

@if(!$connection)
<article class="danger-zone"><h2>No active GAM connection</h2><p>Configure or assign a GAM connection before enabling Prebid.</p></article>
@else
@include('admin.prebid._settings')
@include('admin.prebid._bidders')
@include('admin.prebid._gam_setup')
@endif
@endsection
