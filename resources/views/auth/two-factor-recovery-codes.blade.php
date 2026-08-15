@extends(session('auth_surface') === 'admin' ? 'layouts.staff-auth' : 'layouts.guest')
@section('title', 'Recovery codes')
@section('content')
<h1>Save your recovery codes</h1>
<p class="error">These codes are shown once. Store them offline. Each code can be used only once.</p>
<div class="recovery-grid">@foreach($codes as $code)<code>{{ $code }}</code>@endforeach</div>
<a class="hm-button-primary button-link" href="{{ route('dashboard') }}">I saved the codes</a>
@endsection
