@extends('layouts.admin')
@section('title', 'Publisher dashboard')
@section('heading', 'Publisher overview')
@section('navigation')
@foreach(['Overview','Websites','Ad placements','Revenue reports','Payments','Support','Account settings'] as $item)<span class="{{ $loop->first ? 'active' : '' }}">{{ $item }}</span>@endforeach
@endsection
@section('content')
<section class="hero"><div><p class="eyebrow">Publisher workspace</p><h2>{{ auth()->user()->organization->name }}</h2><p>Reporting and inventory modules will appear here in their implementation phases.</p></div></section>
@endsection
