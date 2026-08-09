@extends('layouts.admin')
@section('title', 'Access control')
@section('heading', 'Roles and permissions')
@section('content')
<section class="cards">@foreach($roles as $role)<article><h2>{{ $role->display_name }}</h2><p class="muted">{{ $role->permissions->pluck('display_name')->join(', ') ?: 'No permissions' }}</p></article>@endforeach</section>
@endsection
