@extends('layouts.staff-auth')
@section('title', 'Staff sign in')
@section('content')
<h1>Staff Control Plane</h1>
<p class="muted">Sign in with your canonical Horus Media staff account. Access remains subject to account eligibility, Horus organization membership, RBAC, and required two-factor authentication.</p>
<form method="POST" action="{{ route('admin.login.store') }}" class="form-stack">
    @csrf
    <label for="staff-email"><span class="field-label">Email <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="staff-email" class="hm-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" inputmode="email" @error('email') aria-invalid="true" aria-describedby="staff-email-error" @enderror>
    @error('email') <p class="field-error" id="staff-email-error" role="alert">{{ $message }}</p> @enderror
    <label for="staff-password"><span class="field-label">Password <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="staff-password" class="hm-input" type="password" name="password" required autocomplete="current-password">
    <label class="check"><input type="checkbox" name="remember" value="1"> <span>Keep me signed in according to staff session policy</span></label>
    <button class="hm-button-primary" type="submit" data-submitting-label="Verifying…">Continue securely</button>
    <a class="text-link" href="{{ route('password.request') }}">Forgot password?</a>
    <a class="text-link" href="{{ route('login') }}">Publisher / Advertiser portal</a>
</form>
@endsection
