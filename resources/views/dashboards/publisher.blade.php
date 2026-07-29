@extends('layouts.admin')
@section('title', 'Publisher dashboard')
@section('heading', 'Publisher overview')
@section('navigation')
<a class="active" href="{{ route('dashboard') }}">Overview</a>@if(auth()->user()->hasPermission('onboarding.manage'))<a href="{{ route('publisher.onboarding.show', auth()->user()->organization->publisher?->onboarding_step ?? 1) }}">Onboarding</a>@endif @if(auth()->user()->hasPermission('sites.view'))<a href="{{ route('publisher.sites.index') }}">Websites</a>@endif @if(auth()->user()->hasPermission('contracts.view'))<a href="{{ route('publisher.contracts.index') }}">Contracts</a>@endif @if(auth()->user()->hasPermission('reporting.publisher.view'))<a href="{{ route('publisher.reporting.index') }}">Revenue reports</a>@endif
@endsection
@section('content')
<section class="hero"><div><p class="eyebrow">Publisher workspace</p><h2>{{ auth()->user()->organization->name }}</h2><p>Manage websites and review finalized aggregated earnings, statements and payment balances.</p><a class="hm-button-primary button-link" href="{{ route('publisher.reporting.index') }}">Open revenue reports</a></div></section>
<section class="metric-grid"><article><p class="eyebrow">Impressions</p><strong class="metric">{{ number_format($reporting['impressions']) }}</strong></article><article><p class="eyebrow">Publisher revenue</p><strong class="metric">{{ number_format($reporting['revenue_minor']/100,2) }}</strong></article><article><p class="eyebrow">Payment balance</p><strong class="metric">{{ number_format($reporting['payment_balance_minor']/100,2) }}</strong></article></section>
@endsection
