@extends('layouts.guest')
@section('title', 'Sign in')
@section('content')
<h1>Welcome back</h1>
<form method="POST" action="{{ route('login.store') }}" class="form-stack">
    @csrf
    <label>Email<input class="hm-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email"></label>
    <label>Password<input class="hm-input" type="password" name="password" required autocomplete="current-password"></label>
    @error('email') <p class="error">{{ $message }}</p> @enderror
    <label class="check"><input type="checkbox" name="remember"> Keep me signed in</label>
    <button class="hm-button-primary" type="submit">Sign in</button>
    <a class="text-link" href="{{ route('password.request') }}">Forgot password?</a>
</form>
@endsection
