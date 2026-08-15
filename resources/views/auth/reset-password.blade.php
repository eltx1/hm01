@extends('layouts.guest')
@section('title', 'Choose password')
@section('content')
<h1>Choose a new password</h1>
<p class="muted">Use at least 14 characters with uppercase and lowercase letters, a number, and a symbol. Password managers and paste are supported.</p>
<form method="POST" action="{{ route('password.update') }}" class="form-stack">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <label for="reset-email"><span class="field-label">Email <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="reset-email" class="hm-input" type="email" name="email" value="{{ old('email', $email) }}" required autocomplete="email" inputmode="email" @error('email') aria-invalid="true" aria-describedby="reset-email-error" @enderror>
    @error('email')<p class="field-error" id="reset-email-error" role="alert">{{ $message }}</p>@enderror
    <label for="reset-password"><span class="field-label">New password <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="reset-password" class="hm-input" type="password" name="password" required autocomplete="new-password" aria-describedby="password-requirements" @error('password') aria-invalid="true" aria-describedby="password-requirements reset-password-error" @enderror>
    <p class="field-help" id="password-requirements">At least 14 characters, mixed case, a number, and a symbol.</p>
    @error('password')<p class="field-error" id="reset-password-error" role="alert">{{ $message }}</p>@enderror
    <label for="reset-password-confirmation"><span class="field-label">Confirm new password <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="reset-password-confirmation" class="hm-input" type="password" name="password_confirmation" required autocomplete="new-password">
    <button class="hm-button-primary" type="submit" data-submitting-label="Updating…">Reset password</button>
</form>
@endsection
