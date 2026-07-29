@extends('layouts.admin')
@section('title', 'Advertiser dashboard')
@section('heading', 'Advertiser overview')
@section('navigation')
<a class="active" href="{{ route('dashboard') }}">Overview</a>@if(auth()->user()->hasPermission('campaigns.view'))<a href="{{ route('advertiser.campaigns.index') }}">Campaigns</a>@endif @if(auth()->user()->hasPermission('reporting.advertiser.view'))<a href="{{ route('advertiser.reporting.index') }}">Reports</a>@endif @if(auth()->user()->hasPermission('billing.advertiser.view'))<a href="{{ route('advertiser.campaigns.index') }}#invoices">Billing</a>@endif
@endsection
@section('content')
<section class="hero"><div><p class="eyebrow">Advertiser workspace</p><h2>{{ auth()->user()->organization->name }}</h2><p>Manage direct campaigns and review unified delivery, spend, remaining budget and invoices.</p></div><a class="hm-button-primary" href="{{ route('advertiser.campaigns.create') }}">Create campaign</a></section>
<section class="metric-grid"><article><p class="eyebrow">Active campaigns</p><strong class="metric">{{ $activeCampaigns }}</strong></article><article><p class="eyebrow">Impressions</p><strong class="metric">{{ number_format($reporting['impressions']) }}</strong></article><article><p class="eyebrow">Clicks</p><strong class="metric">{{ number_format($reporting['clicks']) }}</strong></article><article><p class="eyebrow">Spend</p><strong class="metric">{{ number_format($reporting['spend_minor']/100,2) }}</strong></article><article><p class="eyebrow">Remaining budget</p><strong class="metric">{{ number_format($reporting['remaining_budget_minor']/100,2) }}</strong></article></section>
<article><h2>Recent campaigns</h2>@forelse($campaigns as $campaign)<div class="event"><div><strong><a href="{{ route('advertiser.campaigns.show',$campaign) }}">{{ $campaign->name }}</a></strong><br><span>{{ $campaign->objective }}</span></div><span class="pill">{{ $campaign->status->value }}</span></div>@empty<p class="muted">Create your first campaign.</p>@endforelse</article>
@endsection
