<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\SessionInvalidator;
use App\Services\Identity\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class TwoFactorController extends Controller
{
    public function begin(Request $request, TwoFactorService $twoFactor, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();
        if ($user->two_factor_confirmed_at) {
            return redirect()->route('account.security')->with('status', 'Two-factor authentication is already enabled.');
        }

        if (! $user->two_factor_secret) {
            $user->forceFill(['two_factor_secret' => $twoFactor->generateSecret()])->save();
            $audit->record('auth.two_factor.setup.started', $user->organization_id, $user, $user, request: $request);
        }

        return redirect()->route('account.security.two-factor.setup');
    }

    public function setup(Request $request, TwoFactorService $twoFactor): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->two_factor_confirmed_at || ! $user->two_factor_secret) {
            return redirect()->route('account.security');
        }

        return view('account.two-factor-setup', [
            'user' => $user,
            'secret' => $user->two_factor_secret,
            'provisioningUri' => $twoFactor->provisioningUri($user, $user->two_factor_secret),
        ]);
    }

    public function confirm(Request $request, TwoFactorService $twoFactor, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();

        if (! $user->two_factor_secret || $user->two_factor_confirmed_at) {
            throw ValidationException::withMessages(['code' => 'Two-factor setup is not pending.']);
        }

        if (! $twoFactor->verify($user->two_factor_secret, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'The authentication code is invalid.']);
        }

        $codes = $twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes),
            'two_factor_confirmed_at' => now(),
        ])->save();
        $request->session()->put('two_factor_passed_at', now()->timestamp);
        $audit->record('auth.two_factor.enabled', $user->organization_id, $user, $user, request: $request);

        return redirect()->route('account.security.two-factor.recovery-codes')->with('recovery_codes', $codes);
    }

    public function recoveryCodes(Request $request): View
    {
        abort_unless($request->session()->has('recovery_codes'), 404);

        return view('account.recovery-codes', [
            'codes' => $request->session()->get('recovery_codes'),
        ]);
    }

    public function regenerate(Request $request, TwoFactorService $twoFactor, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);
        $user = $request->user();
        $this->assertConfirmedFactor($user, $data['current_password'], $data['code'], $twoFactor);

        $codes = $twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes)])->save();
        $audit->record('auth.two_factor.recovery_regenerated', $user->organization_id, $user, $user, request: $request);

        return redirect()->route('account.security.two-factor.recovery-codes')->with('recovery_codes', $codes);
    }

    public function disable(
        Request $request,
        TwoFactorService $twoFactor,
        SessionInvalidator $sessionInvalidator,
        AuditRecorder $audit,
    ): RedirectResponse {
        $user = $request->user();
        if ($user->isHorusAdministrator()) {
            throw ValidationException::withMessages([
                'two_factor' => 'Two-factor authentication is required for Horus Media staff accounts and cannot be disabled.',
            ]);
        }

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);
        $this->assertConfirmedFactor($user, $data['current_password'], $data['code'], $twoFactor);

        $currentSessionId = $request->session()->getId();
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $sessionInvalidator->invalidate($user, $currentSessionId);
        $request->session()->forget('two_factor_passed_at');
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $audit->record('auth.two_factor.disabled', $user->organization_id, $user, $user, request: $request);

        return redirect()->route('account.security')->with('status', 'Two-factor authentication disabled. Other sessions have been signed out.');
    }

    private function assertConfirmedFactor(
        object $user,
        string $password,
        string $code,
        TwoFactorService $twoFactor,
    ): void {
        if (! $user->two_factor_secret || ! $user->two_factor_confirmed_at || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['code' => 'Password or authentication code is invalid.']);
        }

        $factorValid = $twoFactor->verify($user->two_factor_secret, $code);
        if (! $factorValid) {
            $factorValid = $twoFactor->consumeRecoveryCode($user, $code);
        }

        if (! $factorValid) {
            throw ValidationException::withMessages(['code' => 'Password or authentication code is invalid.']);
        }
    }
}
