@extends('layouts.guest')
@section('title', 'Apply as a Publisher')
@section('content')
<div class="application-intro">
    <p class="eyebrow">Publisher partnerships</p>
    <h1>Apply as a Publisher</h1>
    <p class="muted">Create your Publisher account in under two minutes. Add and verify websites after approval.</p>
</div>
<ol class="publisher-application-steps express-registration-steps" aria-label="Publisher application steps">
    <li class="active" aria-current="step"><span>1</span><strong>Account</strong></li>
    <li><span>2</span><strong>Company &amp; submit</strong></li>
</ol>
<div class="notice"><strong>Simple by design:</strong> Publisher approval is separate from website review. You can add multiple websites from your dashboard after approval.</div>
<form method="POST" action="{{ route('publisher-registration.store') }}" class="form-stack publisher-application-form">
    @csrf
    <label for="publisher-name"><span class="field-label">Full name <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="publisher-name" class="hm-input" name="name" value="{{ old('name') }}" required autocomplete="name" maxlength="255" @error('name') aria-invalid="true" aria-describedby="publisher-name-error" @enderror>
    @error('name')<p class="field-error" id="publisher-name-error" role="alert">{{ $message }}</p>@enderror

    <label for="publisher-email"><span class="field-label">Business email <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="publisher-email" class="hm-input" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" inputmode="email" maxlength="255" @error('email') aria-invalid="true" aria-describedby="publisher-email-error" @enderror>
    @error('email')<p class="field-error" id="publisher-email-error" role="alert">{{ $message }}</p>@enderror

    <label for="publisher-company"><span class="field-label">Publisher or company name <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="publisher-company" class="hm-input" name="publisher_name" value="{{ old('publisher_name') }}" required autocomplete="organization" maxlength="255" @error('publisher_name') aria-invalid="true" aria-describedby="publisher-company-error" @enderror>
    @error('publisher_name')<p class="field-error" id="publisher-company-error" role="alert">{{ $message }}</p>@enderror

    <label for="publisher-password"><span class="field-label">Password <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="publisher-password" class="hm-input" type="password" name="password" required autocomplete="new-password" aria-describedby="publisher-password-help{{ $errors->has('password') ? ' publisher-password-error' : '' }}" @error('password') aria-invalid="true" @enderror>
    <p class="field-help" id="publisher-password-help">Use at least 14 characters with upper/lowercase letters, a number, and a symbol. Password managers and paste are supported.</p>
    @error('password')<p class="field-error" id="publisher-password-error" role="alert">{{ $message }}</p>@enderror

    <label for="publisher-password-confirmation"><span class="field-label">Confirm password <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="publisher-password-confirmation" class="hm-input" type="password" name="password_confirmation" required autocomplete="new-password">

    <div class="sr-only" aria-hidden="true"><label>Company website confirmation<input name="_company_website" tabindex="-1" autocomplete="off" value=""></label></div>
    @if(config('publisher-applications.turnstile.enabled'))
        <div class="turnstile-panel" aria-label="Security verification" aria-describedby="turnstile-help">
            <div class="cf-turnstile" data-sitekey="{{ config('publisher-applications.turnstile.site_key') }}" data-action="{{ config('publisher-applications.turnstile.action') }}" data-theme="dark" data-size="flexible"></div>
            <p class="field-help" id="turnstile-help">Complete the Cloudflare verification to submit online. @if(config('publisher-applications.support_url'))If the verification cannot be presented or completed with your assistive technology, <a class="text-link" href="{{ config('publisher-applications.support_url') }}">contact Horus Media support for an assisted application path</a>.@endif</p>
            <noscript><p class="notice error">JavaScript is required for online security verification. @if(config('publisher-applications.support_url'))Please <a class="text-link" href="{{ config('publisher-applications.support_url') }}">contact Horus Media support</a> for an assisted application path.@endif</p></noscript>
        </div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
    <button class="hm-button-primary" type="submit" data-submitting-label="Creating application…">Create application</button>
    <a class="text-link" href="{{ route('login') }}">Already started? Sign in</a>
</form>
@endsection
