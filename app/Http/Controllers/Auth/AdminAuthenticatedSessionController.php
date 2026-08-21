<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

final class AdminAuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.admin-login');
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);
        $email = str($credentials['email'])->lower()->trim()->value();
        $user = User::withTrashed()->with('organization')->where('email', $email)->first();
        $reason = null;

        if (! $user || $user->trashed() || ! Hash::check($credentials['password'], $user->password)) {
            $reason = 'invalid_credentials';
        } elseif ($user->isLocked()) {
            $reason = 'account_locked';
        } elseif (! $user->isActive()) {
            $reason = 'account_inactive';
        } elseif (! $user->isHorusAdministrator()) {
            $reason = 'non_horus_identity';
        }

        if ($reason !== null) {
            if ($reason === 'invalid_credentials' && $user && ! $user->trashed()) {
                $this->incrementFailedLogin($user);
            }

            $this->recordLogin($request, $user, false, 'admin_'.$reason, $email);
            $audit->record(
                $reason === 'non_horus_identity' ? 'admin_auth.non_horus_denied' : 'admin_auth.failed',
                $user?->organization_id,
                $user,
                metadata: ['reason' => $reason],
                request: $request,
            );

            return back()
                ->withErrors(['email' => 'The provided credentials or account status are invalid.'])
                ->onlyInput('email');
        }

        $intended = $this->safeIntendedDestination($request);
        $request->session()->put('url.intended', $intended);
        $twoFactorRequired = (bool) config('security.authentication.administrator_2fa_required', true);

        if ($twoFactorRequired && $user->two_factor_confirmed_at) {
            $request->session()->regenerate();
            $request->session()->put([
                'two_factor_user_id' => $user->id,
                'two_factor_remember' => $request->boolean('remember'),
                'two_factor_context' => 'admin',
            ]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->put('auth_surface', 'admin');
        $this->markSuccessfulLogin($request, $user);
        $this->recordLogin($request, $user, true, null, $email);
        $audit->record(
            'admin_auth.succeeded',
            $user->organization_id,
            $user,
            metadata: ['two_factor' => $twoFactorRequired ? 'enrollment_required' : 'disabled'],
            request: $request,
        );

        if ($twoFactorRequired) {
            return redirect()->route('two-factor.setup');
        }

        $request->session()->forget('url.intended');

        return redirect()->to($intended);
    }

    private function safeIntendedDestination(Request $request): string
    {
        $fallback = route('dashboard', absolute: false);
        $intended = $request->session()->get('url.intended');
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

    private function incrementFailedLogin(User $user): void
    {
        $attempts = ((int) $user->failed_login_count) + 1;
        $values = [
            'failed_login_count' => $attempts,
            'last_failed_login_at' => now(),
        ];

        if ($attempts >= (int) config('security.authentication.max_failed_attempts', 8)) {
            $values['locked_until'] = now()->addMinutes((int) config('security.authentication.lock_minutes', 30));
            $values['lock_reason'] = 'too_many_failed_logins';
        }

        $user->forceFill($values)->save();
    }

    private function markSuccessfulLogin(Request $request, User $user): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'failed_login_count' => 0,
            'locked_until' => null,
            'lock_reason' => null,
        ])->save();
    }

    private function recordLogin(Request $request, ?User $user, bool $success, ?string $reason, string $email): void
    {
        LoginEvent::create([
            'organization_id' => $user?->organization_id,
            'user_id' => $user?->id,
            'email' => $email,
            'successful' => $success,
            'failure_reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
        ]);
    }
}
