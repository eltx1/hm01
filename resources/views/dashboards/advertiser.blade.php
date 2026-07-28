@extends('layouts.admin')
@section('title', 'Advertiser dashboard')
@section('heading', 'Advertiser overview')
@section('navigation')
@foreach(['Campaigns','Creatives','Billing','Reports','Account settings'] as $item)<span class="{{ $loop->first ? 'active' : '' }}">{{ $item }}</span>@endforeach
@endsection
@section('content')
<section class="hero"><div><p class="eyebrow">Advertiser workspace</p><h2>{{ auth()->user()->organization->name }}</h2><p>Campaign, creative, billing, and report modules are placeholders in this phase.</p></div></section>
@endsection
