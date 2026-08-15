@extends('layouts.guest')
@section('title', 'Apply as a Publisher')
@section('content')
<div class="application-intro">
    <p class="eyebrow">Publisher partnerships</p>
    <h1>Apply as a Publisher</h1>
    <p class="muted">Start your secure Horus Media application. After email verification, you can save each step and return at any time.</p>
</div>
<ol class="publisher-application-steps" aria-label="Publisher application steps">
    <li class="active"><span>1</span><strong>Account</strong></li>
    <li><span>2</span><strong>Website</strong></li>
    <li><span>3</span><strong>Quality</strong></li>
    <li><span>4</span><strong>Agreements</strong></li>
    <li><span>5</span><strong>Review</strong></li>
</ol>
<div class="notice"><strong>Important:</strong> an application is not Publisher approval, website approval, or production monetization.</div>
@if($errors->any())<div class="notice error" role="alert"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="POST" action="{{ route('publisher-registration.store') }}" class="form-stack publisher-application-form">
    @csrf
    <label>Full name<input class="hm-input" name="name" value="{{ old('name') }}" required autocomplete="name" maxlength="255"></label>
    <label>Business email<input class="hm-input" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" maxlength="255"></label>
    <label>Publisher or company name<input class="hm-input" name="publisher_name" value="{{ old('publisher_name') }}" required autocomplete="organization" maxlength="255"></label>
    <label>Primary website or domain<input class="hm-input" name="primary_domain" value="{{ old('primary_domain') }}" required inputmode="url" placeholder="example.com" maxlength="500"></label>
    <label>Password<input class="hm-input" type="password" name="password" required autocomplete="new-password"></label>
    <small class="muted">Use at least 14 characters with upper/lowercase letters, a number, and a symbol.</small>
    <label>Confirm password<input class="hm-input" type="password" name="password_confirmation" required autocomplete="new-password"></label>
    <div class="sr-only" aria-hidden="true"><label>Company website confirmation<input name="_company_website" tabindex="-1" autocomplete="off" value=""></label></div>
    @if(config('publisher-applications.turnstile.enabled'))
        <div class="turnstile-panel" aria-label="Security verification">
            <div class="cf-turnstile" data-sitekey="{{ config('publisher-applications.turnstile.site_key') }}" data-action="{{ config('publisher-applications.turnstile.action') }}" data-theme="dark" data-size="flexible"></div>
            <noscript><p class="error">JavaScript is required to complete security verification.</p></noscript>
        </div>
        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
    @endif
    <button class="hm-button-primary" type="submit">Create application</button>
    <a class="text-link" href="{{ route('login') }}">Already started? Sign in</a>
</form>
@endsection
