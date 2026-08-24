@extends('layouts.guest')
@section('title', 'Create a Publisher account')
@section('content')
<div class="application-intro">
    <p class="eyebrow">Publisher partnerships</p>
    <h1>Create a Publisher account</h1>
    <p class="muted">Your account and default 70% commercial terms become active immediately. Websites are reviewed separately.</p>
</div>
<div class="notice"><strong>One-step setup:</strong> Create the account, then add a website from the dashboard. Only websites require Horus review.</div>
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
    <p class="field-help" id="publisher-password-help">Use at least 10 characters. Password managers and paste are supported.</p>
    @error('password')<p class="field-error" id="publisher-password-error" role="alert">{{ $message }}</p>@enderror

    <label for="publisher-password-confirmation"><span class="field-label">Confirm password <span class="required-marker" aria-hidden="true">Required</span></span></label>
    <input id="publisher-password-confirmation" class="hm-input" type="password" name="password_confirmation" required autocomplete="new-password">

    <div class="express-agreements">
        <p class="eyebrow">Commercial agreement</p>
        @forelse($legalDocuments as $type => $document)
            <label class="agreement-row compact-agreement">
                <input type="checkbox" name="legal[{{ $type }}]" value="1" @checked(old('legal.'.$type)) @required($document['required'])>
                <span>I accept the <a class="text-link" href="{{ $document['url'] }}" target="_blank" rel="noopener noreferrer">{{ $document['label'] }}</a> ({{ $document['version'] }}) @if($document['required'])<em>Required</em>@endif</span>
            </label>
            @error('legal.'.$type)<p class="field-error" role="alert">{{ $message }}</p>@enderror
        @empty
            <div class="notice error">Publisher Terms are not configured. Horus Media must configure them before registration can continue.</div>
        @endforelse
        <label class="agreement-row compact-agreement marketing-consent"><input type="hidden" name="marketing_opt_in" value="0"><input type="checkbox" name="marketing_opt_in" value="1" @checked(old('marketing_opt_in'))><span>Send me optional product updates</span></label>
    </div>

    <div class="sr-only" aria-hidden="true"><label>Company website confirmation<input name="_company_website" tabindex="-1" autocomplete="off" value=""></label></div>
    @if(config('publisher-applications.turnstile.enabled'))
        <div class="turnstile-panel" aria-label="Security verification" aria-describedby="turnstile-help">
            <div class="cf-turnstile" data-sitekey="{{ config('publisher-applications.turnstile.site_key') }}" data-action="{{ config('publisher-applications.turnstile.action') }}" data-theme="dark" data-size="flexible"></div>
            <p class="field-help" id="turnstile-help">Complete the Cloudflare verification to submit online. @if(config('publisher-applications.support_url'))If the verification cannot be presented or completed with your assistive technology, <a class="text-link" href="{{ config('publisher-applications.support_url') }}">contact Horus Media support for an assisted application path</a>.@endif</p>
            <noscript><p class="notice error">JavaScript is required for online security verification. @if(config('publisher-applications.support_url'))Please <a class="text-link" href="{{ config('publisher-applications.support_url') }}">contact Horus Media support</a> for an assisted application path.@endif</p></noscript>
        </div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
    <button class="hm-button-primary" type="submit" data-submitting-label="Creating account…" @disabled($legalDocuments === [])>Create active account</button>
    <a class="text-link" href="{{ route('login') }}">Already have an account? Sign in</a>
</form>
@endsection
