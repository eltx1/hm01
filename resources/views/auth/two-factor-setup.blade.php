@extends('layouts.guest')
@section('title', 'Secure administrator account')
@section('content')
<h1>Enable two-factor authentication</h1>
<p class="muted">Administrator accounts require a second factor. Add this secret to any TOTP authenticator, then enter the current six-digit code.</p>
<div class="secret-box"><code>{{ $secret }}</code></div>
<details><summary>Authenticator provisioning URI</summary><code class="breakable">{{ $provisioningUri }}</code></details>
<form method="POST" action="{{ route('two-factor.confirm') }}" class="form-stack">@csrf
<label>Authentication code<input class="hm-input" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required autofocus></label>
@error('code')<p class="error">{{ $message }}</p>@enderror
<button class="hm-button-primary">Confirm and enable</button>
</form>
@endsection
