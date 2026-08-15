@extends(session('auth_surface') === 'admin' ? 'layouts.staff-auth' : 'layouts.guest')
@section('title', 'Secure administrator account')
@section('content')
<h1>Enable two-factor authentication</h1>
<p class="muted" id="two-factor-setup-help">Horus Media staff accounts require a second factor before operational access. Add this secret to a TOTP authenticator, then enter or paste the current six-digit code.</p>
<div class="secret-box"><code id="two-factor-secret">{{ $secret }}</code> <button class="text-button" type="button" data-copy-target="two-factor-secret" data-copy-label="Copy secret">Copy secret</button></div>
<details><summary>Authenticator provisioning URI</summary><code class="breakable" id="two-factor-uri">{{ $provisioningUri }}</code><button class="text-button" type="button" data-copy-target="two-factor-uri" data-copy-label="Copy provisioning URI">Copy provisioning URI</button></details>
<form method="POST" action="{{ route('two-factor.confirm') }}" class="form-stack">@csrf
<label for="two-factor-setup-code"><span class="field-label">Authentication code <span class="required-marker" aria-hidden="true">Required</span></span></label>
<input id="two-factor-setup-code" class="hm-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus aria-describedby="two-factor-setup-help{{ $errors->has('code') ? ' two-factor-setup-error' : '' }}" @error('code') aria-invalid="true" @enderror>
@error('code')<p class="field-error" id="two-factor-setup-error" role="alert">{{ $message }}</p>@enderror
<button class="hm-button-primary" type="submit" data-submitting-label="Enabling…">Confirm and enable</button>
</form>
@endsection
