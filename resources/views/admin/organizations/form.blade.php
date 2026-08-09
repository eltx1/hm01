@extends('layouts.admin')
@section('title', $organization->exists ? 'Edit organization' : 'New organization')
@section('heading', $organization->exists ? 'Edit organization' : 'New organization')
@section('content')
<article><form method="POST" action="{{ $organization->exists ? route('admin.organizations.update', $organization) : route('admin.organizations.store') }}" class="form-grid">@csrf @if($organization->exists)@method('PUT')@endif
<label>Name<input class="hm-input" name="name" value="{{ old('name', $organization->name) }}" required></label>
<label>Slug<input class="hm-input" name="slug" value="{{ old('slug', $organization->slug) }}" required></label>
<label>Type<select class="hm-input" name="type">@foreach(\App\Enums\OrganizationType::cases() as $type)<option @selected(old('type', $organization->type?->value)===$type->value)>{{ $type->value }}</option>@endforeach</select></label>
<label>Status<select class="hm-input" name="status">@foreach(\App\Enums\AccountStatus::cases() as $status)<option @selected(old('status', $organization->status?->value)===$status->value)>{{ $status->value }}</option>@endforeach</select></label>
<label>Support email<input class="hm-input" type="email" name="support_email" value="{{ old('support_email', $organization->support_email) }}"></label>
<label class="full">Internal notes<textarea class="hm-input" name="internal_notes" rows="6">{{ old('internal_notes', $organization->internal_notes) }}</textarea></label>
@foreach($errors->all() as $error)<p class="error full">{{ $error }}</p>@endforeach<button class="hm-button-primary">Save organization</button></form></article>
@if($organization->exists)<p><a href="{{ route('admin.organizations.branding.edit', $organization) }}">Manage white-label branding</a></p>@endif
@if($organization->exists && $organization->type !== \App\Enums\OrganizationType::HorusMedia)<form method="POST" action="{{ route('admin.organizations.destroy', $organization) }}">@csrf @method('DELETE')<button class="text-button error">Delete organization</button></form>@endif
@endsection
