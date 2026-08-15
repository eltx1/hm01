@extends(session('two_factor_context') === 'admin' ? 'layouts.staff-auth' : 'layouts.guest')
@section('title', 'Two-factor challenge')
@section('content')
<h1>{{ session('two_factor_context') === 'admin' ? 'Verify staff access' : 'Authentication code' }}</h1>
<p class="muted" id="two-factor-help">Enter a six-digit authenticator code or paste one unused recovery code. Copy and paste are supported.</p>
<form method="POST" action="{{ route('two-factor.verify') }}" class="form-stack">@csrf
<label for="two-factor-code"><span class="field-label">Authentication or recovery code <span class="required-marker" aria-hidden="true">Required</span></span></label>
<input id="two-factor-code" class="hm-input" name="code" inputmode="numeric" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" required autofocus aria-describedby="two-factor-help{{ $errors->has('code') ? ' two-factor-error' : '' }}" @error('code') aria-invalid="true" @enderror>
@error('code')<p class="field-error" id="two-factor-error" role="alert">{{ $message }}</p>@enderror
<button class="hm-button-primary" type="submit" data-submitting-label="Verifying…">Continue securely</button>
</form>
@endsection
