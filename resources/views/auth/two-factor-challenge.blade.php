@extends(session('two_factor_context') === 'admin' ? 'layouts.staff-auth' : 'layouts.guest')
@section('title', 'Two-factor challenge')
@section('content')
<h1>{{ session('two_factor_context') === 'admin' ? 'Verify staff access' : 'Authentication code' }}</h1>
<p class="muted">Enter a six-digit authenticator code or one unused recovery code.</p>
<form method="POST" action="{{ route('two-factor.verify') }}" class="form-stack">@csrf
<label>Code<input class="hm-input" name="code" autocomplete="one-time-code" required autofocus></label>
@error('code')<p class="error" role="alert">{{ $message }}</p>@enderror
<button class="hm-button-primary">Continue securely</button>
</form>
@endsection
