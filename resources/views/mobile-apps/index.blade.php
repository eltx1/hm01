@extends('layouts.admin')
@section('title', 'Mobile apps')
@section('heading', 'Mobile apps')
@section('content')
<section class="hero"><div><p class="eyebrow">Monetization</p><h2>Grow your app revenue</h2><p>Add your app, verify app-ads.txt and access your ad units and earnings.</p></div>
@if(auth()->user()->hasPermission('sites.manage'))<a class="hm-button-primary button-link" href="{{ route($prefix.'.create') }}">Add mobile app</a>@endif</section>
<div class="publisher-site-grid">@forelse($apps as $app)
<article class="publisher-site-card"><div class="workspace-heading"><div><p class="eyebrow">{{ config('mobile-apps.stores.'.$app->store) }}</p><h2>{{ $app->name }}</h2></div><x-status-badge :status="$app->status" /></div><p>{{ $app->store_app_id }}</p><p class="muted">{{ $app->public_id }}</p><a class="hm-button-secondary button-link" href="{{ route($prefix.'.show', $app) }}">Open app →</a></article>
@empty<x-empty-state title="No mobile apps yet" description="Add your first app from a supported store to start monetizing." />@endforelse</div>
{{ $apps->links() }}
@endsection
