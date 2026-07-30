@extends('layouts.admin')
@section('title', 'Administrator dashboard')
@section('heading', 'Horus Media overview')
@section('navigation')
<a class="active" href="{{ route('dashboard') }}">Overview</a>@if(auth()->user()->hasPermission('operations.view'))<a href="{{ route('admin.operations.index') }}">Operations</a>@endif @if(auth()->user()->hasPermission('reporting.admin.view'))<a href="{{ route('admin.reporting.index') }}">Reporting</a>@endif @if(auth()->user()->hasPermission('organizations.manage'))<a href="{{ route('admin.organizations.index') }}">Organizations</a>@endif @if(auth()->user()->hasPermission('publishers.view'))<a href="{{ route('admin.publishers.index') }}">Publishers</a>@endif @if(auth()->user()->hasPermission('sites.view'))<a href="{{ route('admin.sites.index') }}">Websites</a>@endif @if(auth()->user()->hasPermission('advertisers.view'))<a href="{{ route('admin.advertisers.index') }}">Advertisers</a>@endif @if(auth()->user()->hasPermission('campaigns.review'))<a href="{{ route('admin.campaigns.index') }}">Campaigns</a>@endif @if(auth()->user()->hasPermission('demand.view'))<a href="{{ route('admin.demand.index') }}">Native demand</a>@endif @if(auth()->user()->hasPermission('roles.view'))<a href="{{ route('admin.roles.index') }}">Access control</a>@endif
@endsection
@section('content')
<section class="metric-grid">
@foreach ([['Total publishers',$totalPublishers],['Total websites',$totalWebsites],['Total advertisers',$totalAdvertisers],['Active campaigns',$activeCampaigns],['Managed impressions',number_format($reporting['managed_impressions'])],['Gross revenue',number_format($reporting['gross_revenue_minor']/100,2)],['Horus margin',number_format($reporting['horus_margin_minor']/100,2)],['Publisher payable',number_format($reporting['outstanding_publisher_payments_minor']/100,2)]] as [$label,$value])
<article><p class="eyebrow">{{ $label }}</p><strong class="metric">{{ $value }}</strong></article>
@endforeach
</section>
<section class="split-grid"><article><h2>Unified reporting</h2><p class="muted">Horus GAM and approved optional sources are normalized into one aggregated reporting ledger.</p><p><a class="hm-button-primary button-link" href="{{ route('admin.reporting.index') }}">Open reporting and finance</a></p></article><article><h2>Recent audit events</h2>@forelse($auditEvents as $event)<div class="event"><strong>{{ $event->event }}</strong><span>{{ $event->created_at }}</span></div>@empty<p class="muted">No audit events yet.</p>@endforelse</article></section>
@endsection
