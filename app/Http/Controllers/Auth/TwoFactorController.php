<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function setup(Request $request, TwoFactorService $twoFactor, AuditRecorder $audit): View
    {
        $this->ensureEnabled();
        $user = $request->user();
        abort_unless($user->isHorusAdministrator(), 403);
        if (! $user->two_factor_secret) {
            $user->forceFill(['two_factor_secret' => $twoFactor->generateSecret()])->save();
            $audit->record('auth.two_factor.setup.started', $user->organization_id, $user);
        }

        return view('auth.two-factor-setup', [
            'secret' => $user->two_factor_secret,
            'provisioningUri' => $twoFactor->provisioningUri($user, $user->two_factor_secret),
        ]);
    }

    public function confirm(Request $request, TwoFactorService $twoFactor, AuditRecorder $audit): RedirectResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        abort_unless($user->isHorusAdministrator() && $user->two_factor_secret, 403);
        if (! $twoFactor->verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        $codes = $twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $request->session()->put('two_factor_passed_at', now()->timestamp);
        $audit->record('auth.two_factor.enabled', $user->organization_id, $user);

        return redirect()->route('two-factor.recovery-codes')->with('recovery_codes', $codes);
    }

    public function challenge(): View
    {
        $this->ensureEnabled();
        abort_unless(session()->has('two_factor_user_id'), 403);

        return view('auth.two-factor-challenge');
    }

    public function verifyChallenge(Request $request, TwoFactorService $twoFactor, AuditRecorder $audit): RedirectResponse
    {
        $this->ensureEnabled();
        $data = $request->validate(['code' => ['required', 'string']]);
        $context = $request->session()->get('two_factor_context');
        $user = User::with('organization')->find($request->session()->get('two_factor_user_id'));

        if (! $user
            || ! $user->two_factor_secret
            || ! $user->two_factor_confirmed_at
            || ! $user->canAuthenticate()
            || ($context === 'admin' && (! $user->isActive() || ! $user->isHorusAdministrator()))) {
            $request->session()->forget(['two_factor_user_id', 'two_factor_remember', 'two_factor_context']);
            if ($user) {
                $audit->record(
                    $context === 'admin' && ! $user->isHorusAdministrator()
                        ? 'admin_auth.non_horus_denied'
                        : 'admin_auth.failed',
                    $user->organization_id,
                    $user,
                    metadata: ['reason' => 'two_factor_identity_ineligible'],
                    request: $request,
                );
            }

            throw ValidationException::withMessages(['code' => 'The authentication or recovery code is invalid.']);
        }

        $valid = $twoFactor->verify($user->two_factor_secret, $data['code']);
        $usedRecovery = false;
        if (! $valid) {
            $usedRecovery = $twoFactor->consumeRecoveryCode($user, $data['code']);
            $valid = $usedRecovery;
        }
        if (! $valid) {
            $user->forceFill([
                'failed_login_count' => $user->failed_login_count + 1,
                'last_failed_login_at' => now(),
            ])->save();
            LoginEvent::create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'email' => $user->email,
                'successful' => false,
                'failure_reason' => 'two_factor_invalid',
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
            ]);
            if ($context === 'admin') {
                $audit->record(
                    'admin_auth.failed',
                    $user->organization_id,
                    $user,
                    metadata: ['reason' => 'two_factor_invalid'],
                    request: $request,
                );
            }
            throw ValidationException::withMessages(['code' => 'The authentication or recovery code is invalid.']);
        }

        $remember = (bool) $request->session()->pull('two_factor_remember', false);
        Auth::login($user, $remember);
        $request->session()->forget(['two_factor_user_id', 'two_factor_context']);
        $request->session()->regenerate();
        $request->session()->put('two_factor_passed_at', now()->timestamp);
        if ($context === 'admin') {
            $request->session()->put('auth_surface', 'admin');
        }
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'failed_login_count' => 0,
            'locked_until' => null,
            'lock_reason' => null,
        ])->save();
        LoginEvent::create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'email' => $user->email,
            'successful' => true,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
        ]);
        $audit->record($usedRecovery ? 'auth.two_factor.recovery_used' : 'auth.two_factor.challenge_passed', $user->organization_id, $user);
        if ($context === 'admin') {
            $audit->record(
                'admin_auth.succeeded',
                $user->organization_id,
                $user,
                metadata: ['factor' => $usedRecovery ? 'recovery_code' : 'totp'],
                request: $request,
            );

            return redirect()->to($this->safeAdminIntendedDestination($request));
        }

        return redirect()->intended(route('dashboard'));
    }

    public function recoveryCodes(): View
    {
        $this->ensureEnabled();
        abort_unless(session()->has('recovery_codes'), 404);

        return view('auth.two-factor-recovery-codes', ['codes' => session('recovery_codes')]);
    }

    public function regenerate(Request $request, TwoFactorService $twoFactor, AuditRecorder $audit): RedirectResponse
    {
        $this->ensureEnabled();
        $data = $request->validate([
            'password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);
        $user = $request->user();
        if (! $user->two_factor_secret
            || ! $user->two_factor_confirmed_at
            || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['code' => 'Password or authentication code is invalid.']);
        }

        $factorValid = $twoFactor->verify($user->two_factor_secret, $data['code']);
        if (! $factorValid) {
            $factorValid = $twoFactor->consumeRecoveryCode($user, $data['code']);
        }
        if (! $factorValid) {
            throw ValidationException::withMessages(['code' => 'Password or authentication code is invalid.']);
        }

        $codes = $twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes)])->save();
        $audit->record('auth.two_factor.recovery_regenerated', $user->organization_id, $user);

        return redirect()->route('two-factor.recovery-codes')->with('recovery_codes', $codes);
    }

    public function disable(Request $request, TwoFactorService $twoFactor, AuditRecorder $audit): RedirectResponse
    {
        $this->ensureEnabled();
        $user = $request->user();
        if ($user->isHorusAdministrator()) {
            throw ValidationException::withMessages([
                'two_factor' => 'Two-factor authentication is required for Horus Media staff accounts and cannot be disabled.',
            ]);
        }

        $data = $request->validate(['password' => ['required'], 'code' => ['required', 'string']]);
        if (! $user->two_factor_secret
            || ! $user->two_factor_confirmed_at
            || ! Hash::check($data['password'], $user->password)
            || ! $twoFactor->verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'Password or authentication code is invalid.']);
        }
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        $request->session()->forget('two_factor_passed_at');
        $audit->record('auth.two_factor.disabled', $user->organization_id, $user);

        return redirect()->route('account.security')->with('status', 'Two-factor authentication disabled.');
    }

    private function ensureEnabled(): void
    {
        abort_unless(config('security.authentication.administrator_2fa_required', true), 404);
    }

    private function safeAdminIntendedDestination(Request $request): string
    {
        $fallback = route('dashboard', absolute: false);
        $intended = $request->session()->pull('url.intended');
        if (! is_string($intended) || $intended === '') {
            return $fallback;
        }

        $parts = parse_url($intended);
        if ($parts === false) {
            return $fallback;
        }

        if (isset($parts['host']) && strcasecmp((string) $parts['host'], $request->getHost()) !== 0) {
            return $fallback;
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path !== '/' && $path !== '/admin' && ! str_starts_with($path, '/admin/')) {
            return $fallback;
        }

        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return $path.$query;
    }
}
