@extends(auth()->user()->isActive() ? 'layouts.admin' : 'layouts.applicant')
@section('title', 'Security')
@section('heading', 'Account Security')
@section('content')
@include('account._tabs')

<div class="summary-grid security-summary" aria-label="Security summary">
    <div><span>Password last changed</span><strong>{{ $user->password_changed_at?->diffForHumans() ?? 'No recorded change date' }}</strong></div>
    <div><span>Current session</span><strong>Active</strong></div>
    <div><span>Other active sessions</span><strong>{{ $otherSessionCount }}</strong></div>
</div>

<section class="workspace-section" id="password" aria-labelledby="password-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Password</p><h2 id="password-heading">Change password</h2><p class="muted">Changing your password keeps this browser signed in and invalidates other active sessions and remembered authentication.</p></div>
    </div>
    <article>
        <form method="POST" action="{{ route('account.security.password.update') }}" class="form-stack">
            @csrf
            @method('PUT')
            <label for="current-password"><span class="field-label">Current password</span><input id="current-password" class="hm-input" type="password" name="current_password" autocomplete="current-password" required></label>
            <label for="new-password"><span class="field-label">New password</span><input id="new-password" class="hm-input" type="password" name="password" autocomplete="new-password" minlength="14" required aria-describedby="password-policy"></label>
            <p id="password-policy" class="muted">Use at least 14 characters with upper- and lowercase letters, a number, and a symbol.</p>
            <label for="new-password-confirmation"><span class="field-label">Confirm new password</span><input id="new-password-confirmation" class="hm-input" type="password" name="password_confirmation" autocomplete="new-password" minlength="14" required></label>
            <button class="hm-button-primary" type="submit">Change password</button>
        </form>
    </article>
</section>

@if(config('security.authentication.administrator_2fa_required'))
<section class="workspace-section" id="two-factor" aria-labelledby="two-factor-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Two-factor authentication</p><h2 id="two-factor-heading">Authenticator protection</h2><p class="muted">Horus uses the existing TOTP authenticator and single-use recovery-code system.</p></div>
        <span class="status-badge {{ $twoFactorEnabled ? 'status-badge-success' : 'status-badge-warning' }}">{{ $twoFactorEnabled ? 'Enabled' : 'Disabled' }}</span>
    </div>

    @if(! $twoFactorEnabled)
        <article>
            <h3>Enable two-factor authentication</h3>
            <p>Add a standards-based TOTP authenticator. Your setup secret is shown only during the controlled setup flow.</p>
            <form method="POST" action="{{ route('account.security.two-factor.begin') }}">@csrf<button class="hm-button-primary" type="submit">Start two-factor setup</button></form>
        </article>
    @else
        <div class="split-grid">
            <article>
                <h3>Regenerate recovery codes</h3>
                <p>Existing recovery codes will stop working. Confirm with your password and a current authenticator or recovery code.</p>
                <form method="POST" action="{{ route('account.security.two-factor.recovery-codes.regenerate') }}" class="form-stack">
                    @csrf
                    <label for="recovery-current-password"><span class="field-label">Current password</span><input id="recovery-current-password" class="hm-input" type="password" name="current_password" autocomplete="current-password" required></label>
                    <label for="recovery-factor-code"><span class="field-label">Authenticator or recovery code</span><input id="recovery-factor-code" class="hm-input" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required></label>
                    <button class="hm-button-secondary" type="submit">Generate new recovery codes</button>
                </form>
            </article>
            <article class="danger-zone">
                <h3>Disable two-factor authentication</h3>
                @if($staffTwoFactorRequired)
                    <p>Two-factor authentication is required for Horus Media staff accounts and cannot be disabled.</p>
                @else
                    <p>Disabling two-factor authentication signs out other sessions. Confirm with your password and a current authenticator or recovery code.</p>
                    <form method="POST" action="{{ route('account.security.two-factor.disable') }}" class="form-stack">
                        @csrf
                        @method('DELETE')
                        <label for="disable-current-password"><span class="field-label">Current password</span><input id="disable-current-password" class="hm-input" type="password" name="current_password" autocomplete="current-password" required></label>
                        <label for="disable-factor-code"><span class="field-label">Authenticator or recovery code</span><input id="disable-factor-code" class="hm-input" name="code" type="text" inputmode="numeric" autocomplete="one-time-code" required></label>
                        <button class="hm-button-danger" type="submit">Disable two-factor authentication</button>
                    </form>
                @endif
            </article>
        </div>
    @endif
</section>
@endif

<section class="workspace-section" id="sessions" aria-labelledby="sessions-heading">
    <div class="workspace-heading">
        <div><p class="eyebrow">Sessions</p><h2 id="sessions-heading">Active sessions</h2><p class="muted">Only safe device and activity metadata is shown. Session payloads and tokens are never displayed.</p></div>
        @if($sessionManagementAvailable && $otherSessionCount > 0)
            <form method="POST" action="{{ route('account.security.sessions.revoke-others') }}">@csrf @method('DELETE')<button class="hm-button-secondary" type="submit">Sign out all other sessions</button></form>
        @endif
    </div>

    @if(! $sessionManagementAvailable)
        <div class="notice" role="status">Session revocation is available when the configured database-session store is active.</div>
    @endif

    <div class="compact-list">
        @foreach($activeSessions as $session)
            <article class="compact-row">
                <div>
                    <strong>{{ $session['device'] }}</strong>
                    <p>{{ $session['current'] ? 'Current session' : 'Other session' }} · Active {{ $session['last_active_at']->diffForHumans() }}</p>
                </div>
                @if($session['current'])
                    <span class="status-badge status-badge-success">This device</span>
                @elseif($sessionManagementAvailable && $session['reference'])
                    <form method="POST" action="{{ route('account.security.sessions.revoke', $session['reference']) }}">@csrf @method('DELETE')<button class="hm-button-danger" type="submit">Sign out</button></form>
                @endif
            </article>
        @endforeach
    </div>
</section>

<section class="workspace-section" id="security-events" aria-labelledby="security-events-heading">
    <div class="workspace-heading"><div><p class="eyebrow">Recent activity</p><h2 id="security-events-heading">Security events</h2><p class="muted">Only events retained by the existing audit system are shown. This is not an invented login-history feed.</p></div></div>
    <article>
        <div class="compact-list">
            @forelse($securityEvents as $event)
                <div class="compact-row"><strong>{{ $event['label'] }}</strong><span class="muted">{{ $event['occurred_at']->diffForHumans() }}</span></div>
            @empty
                <p class="muted">No retained account-security events yet.</p>
            @endforelse
        </div>
    </article>
</section>
@endsection
