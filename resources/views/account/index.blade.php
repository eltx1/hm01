@extends(auth()->user()->isActive() ? 'layouts.admin' : 'layouts.applicant')
@section('title', 'Account')
@section('heading', 'Account & Security')
@section('content')
@include('account._tabs')

<section class="hero" aria-labelledby="account-overview-title">
    <div>
        <p class="eyebrow">Personal account</p>
        <h2 id="account-overview-title">Your Horus Media account</h2>
        <p>Manage your personal profile, password, two-factor authentication, and active sessions. Organization access and permissions are managed separately.</p>
    </div>
</section>

<div class="cards account-summary-grid">
    <article>
        <p class="eyebrow">Profile</p>
        <h3>{{ $user->name }}</h3>
        <p>{{ $user->email }}</p>
        <a class="section-anchor" href="{{ route('account.profile.edit') }}">Manage profile</a>
    </article>
    <article>
        <p class="eyebrow">Two-factor authentication</p>
        <h3>{{ $twoFactorEnabled ? 'Enabled' : 'Disabled' }}</h3>
        <p>{{ $twoFactorEnabled ? 'Your account has a second authentication factor.' : 'Add a TOTP authenticator for stronger account protection.' }}</p>
        <a class="section-anchor" href="{{ route('account.security') }}#two-factor">Manage two-factor</a>
    </article>
    <article>
        <p class="eyebrow">Active sessions</p>
        <h3>{{ $sessionCount }}</h3>
        <p>Review this browser and other currently active sessions without exposing session tokens.</p>
        <a class="section-anchor" href="{{ route('account.security') }}#sessions">Review sessions</a>
    </article>
</div>
@endsection
