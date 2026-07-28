@extends('layouts.admin')
@section('title', 'Partner dashboard')
@section('heading', 'Partner account')
@section('navigation')<span class="active">Overview</span><span>Account settings</span>@endsection
@section('content')<section class="hero"><div><h2>{{ auth()->user()->organization->name }}</h2><p>Partner capabilities will be added in a later phase.</p></div></section>@endsection
