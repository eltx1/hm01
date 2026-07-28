@extends('layouts.admin')
@section('title', $publisher->display_name)
@section('heading', 'Publisher account')
@section('navigation')<a href="{{ route('dashboard') }}">Overview</a><span class="active">Publisher</span>@endsection
@section('content')
<article><h2>{{ $publisher->display_name }}</h2><p>{{ $publisher->legal_name }}</p><span class="status">{{ $publisher->status->value }}</span>
@if(auth()->user()->isHorusAdministrator() && auth()->user()->hasPermission('internal_notes.view'))<h3>Internal notes</h3><p>{{ $publisher->internal_notes ?: 'None' }}</p>@endif</article>
@endsection
