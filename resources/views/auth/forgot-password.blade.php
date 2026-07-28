@extends('layouts.guest')
@section('title', 'Reset password')
@section('content')
<h1>Reset password</h1>
<p class="muted">Enter your email and we will send a secure reset link.</p>
<form method="POST" action="{{ route('password.email') }}" class="form-stack">
    @csrf
    <label>Email<input class="hm-input" type="email" name="email" required autofocus></label>
    @error('email') <p class="error">{{ $message }}</p> @enderror
    <button class="hm-button-primary" type="submit">Send reset link</button>
</form>
@endsection
