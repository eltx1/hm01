@extends(session('auth_surface') === 'admin' ? 'layouts.staff-auth' : 'layouts.guest')
@section('title', 'Recovery codes')
@section('content')
<h1>Save your recovery codes</h1>
<div class="notice error" role="alert"><strong>Store these codes safely.</strong> They are shown once and each code can be used only once.</div>
<div id="recovery-codes" class="recovery-grid" aria-label="Two-factor recovery codes">@foreach($codes as $code)<code>{{ $code }}</code>@endforeach</div>
<div class="wizard-actions">
    <button class="hm-button-secondary" type="button" data-copy-target="recovery-codes" data-copy-label="Copy all codes">Copy all codes</button>
    <a class="hm-button-primary" href="{{ route('dashboard') }}">I saved the codes</a>
</div>
@endsection
