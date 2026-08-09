@extends('layouts.admin')
@section('title', $advertiser->display_name)
@section('heading', 'Advertiser account')
@section('content')
<article><h2>{{ $advertiser->display_name }}</h2><p>{{ $advertiser->legal_name }}</p><span class="status">{{ $advertiser->status->value }}</span>
@if(auth()->user()->isHorusAdministrator() && auth()->user()->hasPermission('internal_notes.view'))<h3>Internal notes</h3><p>{{ $advertiser->internal_notes ?: 'None' }}</p>@endif</article>
@endsection
