@extends('layouts.admin')
@section('title', 'White-label branding')
@section('heading', 'White-label branding')
@section('navigation')<a href="{{ route('dashboard') }}">Overview</a><span class="active">Branding</span>@endsection
@section('content')
<article><form method="POST" enctype="multipart/form-data" action="{{ auth()->user()->isHorusAdministrator() ? route('admin.organizations.branding.update', $organization) : route('account.branding.update') }}" class="form-grid">@csrf @method('PUT')
<label>Dashboard title<input class="hm-input" name="dashboard_title" value="{{ old('dashboard_title', $organization->dashboard_title) }}"></label>
<label>Primary color<input class="hm-input" type="color" name="primary_color" value="{{ old('primary_color', $organization->primary_color ?: '#12499d') }}"></label>
<label>Support email<input class="hm-input" type="email" name="support_email" value="{{ old('support_email', $organization->support_email) }}"></label>
<label>Logo (PNG, JPEG or WebP; 2 MB max)<input class="hm-input" type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
@if($organization->logo_path)<div><img class="brand-preview" src="{{ Storage::disk('public')->url($organization->logo_path) }}" alt="Current organization logo"><label><input type="checkbox" name="remove_logo" value="1"> Remove current logo</label></div>@endif
@foreach($errors->all() as $error)<p class="error full">{{ $error }}</p>@endforeach<button class="hm-button-primary">Save branding</button></form></article>
@endsection
