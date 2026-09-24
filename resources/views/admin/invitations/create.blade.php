@extends('layouts.admin')
@section('title', $organization ? 'Invite team member' : 'Invite user')
@section('heading', $organization ? 'Invite team member' : 'Invite user')
@section('content')
<article>
    <form method="POST" action="{{ route('admin.invitations.store') }}" class="form-stack">
        @csrf
        @if($organization)
            <div class="compact-row">
                <div><strong>{{ $organization->name }}</strong><p>Your team workspace</p></div>
                <span class="pill">{{ str($organization->type->value)->headline() }}</span>
            </div>
            <input type="hidden" name="organization_id" value="{{ $organization->id }}">
        @else
            <label>Organization ID<input class="hm-input" name="organization_id" value="{{ old('organization_id') }}" required></label>
        @endif
        <label>Email<input class="hm-input" type="email" name="email" value="{{ old('email') }}" required></label>
        <label>Role
            <select class="hm-input" name="role_id">
                <option value="">No role</option>
                @foreach($roles as $role)
                    <option value="{{ $role->id }}" @selected(old('role_id') === $role->id)>{{ $role->display_name }}</option>
                @endforeach
            </select>
        </label>
        <button class="hm-button-primary">{{ $organization ? 'Send team invitation' : 'Send invitation' }}</button>
    </form>
</article>
@endsection
