@extends('layouts.admin')
@section('title', 'Publisher dashboard')
@section('heading', 'Publisher overview')
@section('navigation')
<span class="active">Overview</span>@if(auth()->user()->hasPermission('onboarding.manage'))<a href="{{ route('publisher.onboarding.show', auth()->user()->organization->publisher?->onboarding_step ?? 1) }}">Onboarding</a>@endif @if(auth()->user()->hasPermission('sites.view'))<a href="{{ route('publisher.sites.index') }}">Websites</a>@endif<span>Ad placements</span>@if(auth()->user()->hasPermission('contracts.view'))<a href="{{ route('publisher.contracts.index') }}">Contracts</a>@endif<span>Revenue reports</span><span>Payments</span><span>Support</span>@if(auth()->user()->hasPermission('branding.manage'))<a href="{{ route('account.branding.edit') }}">Account settings</a>@endif
@endsection
@section('content')
<section class="hero"><div><p class="eyebrow">Publisher workspace</p><h2>{{ auth()->user()->organization->name }}</h2><p>Complete onboarding, register websites, verify domains, and follow every review decision from this workspace.</p><a class="hm-button-primary button-link" href="{{ route('publisher.sites.index') }}">Manage websites</a></div></section>
@endsection
