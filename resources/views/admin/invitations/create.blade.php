@extends('layouts.admin')
@section('title', 'Invite user')
@section('heading', 'Invite user')
@section('navigation')<a href="{{ route('dashboard') }}">Overview</a><span class="active">Users</span>@endsection
@section('content')
<article><form method="POST" action="{{ route('admin.invitations.store') }}" class="form-stack">@csrf
<label>Organization ID<input class="hm-input" name="organization_id" required></label>
<label>Email<input class="hm-input" type="email" name="email" required></label>
<label>Role<select class="hm-input" name="role_id"><option value="">No role</option>@foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->display_name }}</option>@endforeach</select></label>
<button class="hm-button-primary">Send invitation</button></form></article>
@endsection
