@extends('layouts.admin')
@section('title','Direct campaigns')
@section('heading','Direct campaigns')
@section('navigation')<a href="{{ route('dashboard') }}">Overview</a><a class="active" href="{{ route('admin.campaigns.index') }}">Campaigns</a><a href="{{ route('admin.advertisers.index') }}">Advertisers</a><a href="{{ route('admin.sites.index') }}">Websites</a><a href="{{ route('admin.gam.connections.index') }}">GAM</a>@endsection
@section('content')
<section class="hero"><div><p class="eyebrow">Horus Media source of truth</p><h2>Campaign operations</h2><p>Review advertiser campaigns and deploy isolated instances to the GAM network selected by each website.</p></div><span class="pill">{{ $pendingAdvertisers }} advertiser reviews</span></section>
<article>@forelse($campaigns as $campaign)<div class="domain-card"><div><strong><a href="{{ route('admin.campaigns.show',$campaign) }}">{{ $campaign->name }}</a></strong><span>{{ $campaign->advertiser->display_name }}</span><div class="status-row"><span class="pill">{{ $campaign->status->value }}</span><span class="pill">{{ $campaign->pricing_model->value }}</span><span class="pill">{{ $campaign->sites_count }} sites</span><span class="pill">{{ $campaign->networkInstances->count() }} networks</span></div></div><div>{{ $campaign->currency }} {{ number_format($campaign->total_budget_minor/100,2) }}</div></div>@empty<p class="muted">No campaigns.</p>@endforelse{{ $campaigns->links() }}</article>
@endsection
