@extends('layouts.guest')
@section('title', 'Portal sign in')
@section('content')
<h1>Publisher / Advertiser portal</h1>
<p class="muted">Sign in to your Horus Media customer workspace.</p>
<form method="POST" action="{{ route('login.store') }}" class="form-stack">
    @csrf
    <label for="login-email"><span class="field-label">Email <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="login-email" class="hm-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" inputmode="email" @error('email') aria-invalid="true" aria-describedby="login-email-error" @enderror>
    @error('email') <p class="field-error" id="login-email-error" role="alert">{{ $message }}</p> @enderror
    <label for="login-password"><span class="field-label">Password <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="login-password" class="hm-input" type="password" name="password" required autocomplete="current-password">
    <label class="check"><input type="checkbox" name="remember" value="1"> <span>Keep me signed in</span></label>
    <button class="hm-button-primary" type="submit" data-submitting-label="Signing in…">Sign in</button>
    <a class="text-link" href="{{ route('password.request') }}">Forgot password?</a>
    @if(config('publisher-applications.public_registration_enabled'))<a class="text-link" href="{{ route('publisher-registration.create') }}">Apply as a Publisher</a>@endif
</form>
@endsection
