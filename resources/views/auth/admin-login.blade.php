@extends('layouts.staff-auth')
@section('title', 'Staff sign in')
@section('content')
<h1>Staff Control Plane</h1>
<p class="muted">Sign in with your canonical Horus Media staff account. Access is still subject to account status, Horus organization eligibility, RBAC, and required two-factor authentication.</p>
<form method="POST" action="{{ route('admin.login.store') }}" class="form-stack">
    @csrf
    <label>Email<input class="hm-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"></label>
    <label>Password<input class="hm-input" type="password" name="password" required autocomplete="current-password"></label>
    @error('email') <p class="error" role="alert">{{ $message }}</p> @enderror
    <label class="check"><input type="checkbox" name="remember"> Keep me signed in according to staff session policy</label>
    <button class="hm-button-primary" type="submit">Continue securely</button>
    <a class="text-link" href="{{ route('password.request') }}">Forgot password?</a>
    <a class="text-link" href="{{ route('login') }}">Publisher / Advertiser portal</a>
</form>
@endsection
