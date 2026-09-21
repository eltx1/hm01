@extends('layouts.admin')
@section('title', 'Connect Ad Manager reports')
@section('heading', 'Connect Ad Manager reports')
@section('content')
<section class="hero"><div><p class="eyebrow">{{ $site->display_name }}</p><h2>Connect your Google account</h2><p>Choose your Google account, then the ad unit for this website. You can connect several Google accounts and Ad Manager networks.</p></div><a class="hm-button-secondary button-link" href="{{ route('admin.sites.show', $site) }}#reporting">Back to website</a></section>

@if($accountEmail && count($networks))
<article class="workspace-section">
    <p class="eyebrow">Google account verified</p><h3>Choose your Ad Manager networks</h3><p>{{ $accountEmail }}</p>
    <form method="POST" action="{{ route('admin.sites.reporting.accounts.connect', $site) }}" class="form-stack">
        @csrf<input type="hidden" name="flow" value="{{ $flow }}">
        @foreach($networks as $network)
        <label class="compact-row"><span><input type="checkbox" name="networks[]" value="{{ $network['code'] }}" @checked(in_array($network['code'], old('networks', [])))> <strong>{{ $network['name'] }}</strong></span><span>{{ $network['code'] }} · {{ $network['currency'] }} · {{ $network['timezone'] }}</span></label>
        @endforeach
        <small>Select one or more. Each network stays available in the account picker for your websites.</small>
        <button class="hm-button-primary">Add selected networks and continue</button>
    </form>
</article>
@else
<article class="workspace-section">
    <h3>Connect with Google</h3>
    @if($googleReady)
        <p>Sign in to the Google account that can access your Ad Manager reports. We will find its networks automatically.</p>
        <form method="POST" action="{{ route('admin.sites.reporting.accounts.start', $site) }}">@csrf<button class="hm-button-primary">Connect a Google account</button></form>
    @else
        <p>Google sign-in needs a one-time setup for Horus Media. After setup, admins connect accounts through Google sign-in without entering server paths or refresh tokens.</p>
    @endif
    <p class="muted">Horus uses this connection for reports. Google's consent screen describes its shared Ad Manager permission as “view and manage”; this reporting connection cannot deploy or change ads in Horus.</p>
    <details @if(! $googleReady) open @endif>
        <summary>{{ $googleReady ? 'Google app settings' : 'Set up Google sign-in once' }}</summary>
        <ol>
            <li>Open <a class="text-link" href="https://console.cloud.google.com/auth/overview" target="_blank" rel="noopener noreferrer">Google Auth Platform</a>, choose your company project, and configure its audience and consent screen. If it is in Testing, add the Google accounts you will use as test users. Testing refresh tokens may expire after seven days; use the appropriate production configuration for ongoing reporting.</li>
            <li>Create a <strong>Web application</strong> client under <a class="text-link" href="https://console.cloud.google.com/auth/clients" target="_blank" rel="noopener noreferrer">Clients</a>. Add this exact <strong>Authorized redirect URI</strong>: <input class="hm-input" value="{{ $callbackUrl }}" readonly aria-label="Google authorized redirect URI"></li>
            <li>Download that client's JSON file and select it below. This setup is shared across all reporting accounts; it is not repeated for each website.</li>
        </ol>
        <form class="form-stack" method="POST" enctype="multipart/form-data" action="{{ route('admin.sites.reporting.accounts.setup', $site) }}">
            @csrf<label>Google Web application JSON<input class="hm-input" type="file" name="google_app" accept=".json,application/json" required></label>
            <button class="hm-button-primary">Save and continue with Google</button>
        </form>
    </details>
</article>

<article class="workspace-section">
    <details><summary>Connect using a service account instead</summary>
        <p>Already have a Google service-account JSON key? You can connect directly without setting up Google sign-in.</p>
        <ol>
            <li>In Ad Manager → Admin → Global settings, enable API access.</li>
            <li>Add the service-account email under Ad Manager users. Give it access to the required ad units and reports.</li>
            <li>Choose its original JSON key. We will verify access and find its networks.</li>
        </ol>
        <p><a class="text-link" href="https://developers.google.com/ad-manager/api/authentication#service-account" target="_blank" rel="noopener noreferrer">Google's service-account setup guide</a></p>
        <form class="form-stack" method="POST" enctype="multipart/form-data" action="{{ route('admin.sites.reporting.accounts.upload', $site) }}">
            @csrf<label>Service-account JSON key<input class="hm-input" type="file" name="service_account" accept=".json,application/json" required></label>
            <button class="hm-button-primary">Verify and connect</button>
        </form>
    </details>
</article>
@endif
@endsection
