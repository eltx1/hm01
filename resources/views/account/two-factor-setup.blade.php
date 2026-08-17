@extends(auth()->user()->isActive() ? 'layouts.admin' : 'layouts.applicant')
@section('title', 'Two-factor setup')
@section('heading', 'Two-factor authentication')
@section('content')
@include('account._tabs')

<section class="workspace-section" aria-labelledby="two-factor-setup-heading">
    <div class="workspace-heading"><div><p class="eyebrow">Authenticator setup</p><h2 id="two-factor-setup-heading">Connect your authenticator</h2><p class="muted" id="two-factor-setup-help">Add the secret to a TOTP authenticator, then enter or paste the current six-digit code. Do not share this secret.</p></div></div>
    <article>
        <div class="secret-box"><code id="account-two-factor-secret">{{ $secret }}</code> <button class="text-button" type="button" data-copy-target="account-two-factor-secret" data-copy-label="Copy secret">Copy secret</button></div>
        <details><summary>Authenticator provisioning URI</summary><code class="breakable" id="account-two-factor-uri">{{ $provisioningUri }}</code><button class="text-button" type="button" data-copy-target="account-two-factor-uri" data-copy-label="Copy provisioning URI">Copy provisioning URI</button></details>
        <form method="POST" action="{{ route('account.security.two-factor.confirm') }}" class="form-stack">
            @csrf
            <label for="account-two-factor-code"><span class="field-label">Authentication code</span><input id="account-two-factor-code" class="hm-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus aria-describedby="two-factor-setup-help{{ $errors->has('code') ? ' account-two-factor-code-error' : '' }}" @error('code') aria-invalid="true" @enderror></label>
            @error('code')<p class="field-error" id="account-two-factor-code-error" role="alert">{{ $message }}</p>@enderror
            <div class="wizard-actions"><a class="hm-button-secondary" href="{{ route('account.security') }}">Cancel</a><button class="hm-button-primary" type="submit">Confirm and enable</button></div>
        </form>
    </article>
</section>
@endsection
