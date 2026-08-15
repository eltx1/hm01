@extends('layouts.guest')
@section('title', 'Accept invitation')
@section('content')
<h1>Activate your account</h1>
<p class="muted">Complete your profile and choose a secure password. Password managers and paste are supported.</p>
<form method="POST" action="{{ route('invitations.accept', $token) }}" class="form-stack">
    @csrf
    <label for="invite-name"><span class="field-label">Full name <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="invite-name" class="hm-input" name="name" value="{{ old('name') }}" required autocomplete="name" @error('name') aria-invalid="true" aria-describedby="invite-name-error" @enderror>
    @error('name')<p class="field-error" id="invite-name-error" role="alert">{{ $message }}</p>@enderror
    <label for="invite-password"><span class="field-label">Password <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="invite-password" class="hm-input" type="password" name="password" required autocomplete="new-password" aria-describedby="invite-password-help" @error('password') aria-invalid="true" @enderror>
    <p class="field-help" id="invite-password-help">Use at least 14 characters with mixed case, a number, and a symbol.</p>
    @error('password')<p class="field-error" role="alert">{{ $message }}</p>@enderror
    <label for="invite-password-confirmation"><span class="field-label">Confirm password <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="invite-password-confirmation" class="hm-input" type="password" name="password_confirmation" required autocomplete="new-password">
    <button class="hm-button-primary" type="submit" data-submitting-label="Activating…">Activate account</button>
</form>
@endsection
