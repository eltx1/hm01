@extends('layouts.admin')
@section('title', 'Publisher dashboard')
@section('heading', 'Publisher overview')
@section('navigation')
<span class="active">Overview</span>@foreach(['Websites','Ad placements','Revenue reports','Payments','Support'] as $item)<span>{{ $item }}</span>@endforeach<a href="{{ route('account.branding.edit') }}">Account settings</a>
@endsection
@section('content')
<section class="hero"><div><p class="eyebrow">Publisher workspace</p><h2>{{ auth()->user()->organization->name }}</h2><p>Reporting and inventory modules will appear here in their implementation phases.</p></div></section>
@endsection
