@extends('layouts.guest')
@section('title', 'Reset password')
@section('content')
<h1>Reset password</h1>
<p class="muted">Enter your account email. For privacy, the response is the same whether or not the address is registered.</p>
<form method="POST" action="{{ route('password.email') }}" class="form-stack">
    @csrf
    <label for="recovery-email"><span class="field-label">Email <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="recovery-email" class="hm-input" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email" inputmode="email" @error('email') aria-invalid="true" aria-describedby="recovery-email-error" @enderror>
    @error('email') <p class="field-error" id="recovery-email-error" role="alert">{{ $message }}</p> @enderror
    <button class="hm-button-primary" type="submit" data-submitting-label="Sending…">Send reset link</button>
    <a class="text-link" href="{{ route('login') }}">Back to sign in</a>
</form>
@endsection
