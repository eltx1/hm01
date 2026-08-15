@extends('layouts.guest')
@section('title', 'Portal sign in')
@section('content')
<h1>Publisher / Advertiser portal</h1>
<p class="muted">Sign in to your Horus Media customer workspace.</p>
<form method="POST" action="{{ route('login.store') }}" class="form-stack">
    @csrf
    <label>Email<input class="hm-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"></label>
    <label>Password<input class="hm-input" type="password" name="password" required autocomplete="current-password"></label>
    @error('email') <p class="error" role="alert">{{ $message }}</p> @enderror
    <label class="check"><input type="checkbox" name="remember"> Keep me signed in</label>
    <button class="hm-button-primary" type="submit">Sign in</button>
    <a class="text-link" href="{{ route('password.request') }}">Forgot password?</a>
    @if(config('publisher-applications.public_registration_enabled'))<a class="text-link" href="{{ route('publisher-registration.create') }}">Apply as a Publisher</a>@endif
</form>
@endsection
