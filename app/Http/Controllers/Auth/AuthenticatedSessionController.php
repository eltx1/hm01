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

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $email = str($credentials['email'])->lower()->trim()->value();
        $user = User::withTrashed()->with('organization')->where('email', $email)->first();
        $reason = null;

        if (! $user || $user->trashed() || ! Hash::check($credentials['password'], $user->password)) {
            $reason = 'invalid_credentials';
        } elseif ($user->isLocked()) {
            $reason = 'account_locked';
        } elseif (! $user->canAuthenticate()) {
            $reason = 'account_inactive';
        }

        if ($reason) {
            if ($user && ! $user->trashed()) {
                $attempts = ((int) $user->failed_login_count) + 1;
                $values = ['failed_login_count' => $attempts, 'last_failed_login_at' => now()];
                if ($attempts >= (int) config('security.authentication.max_failed_attempts', 8)) {
                    $values['locked_until'] = now()->addMinutes((int) config('security.authentication.lock_minutes', 30));
                    $values['lock_reason'] = 'too_many_failed_logins';
                }
                $user->forceFill($values)->save();
            }
            $this->recordLogin($request, $user, false, $reason, $email);

            return back()->withErrors(['email' => 'The provided credentials or account status are invalid.'])->onlyInput('email');
        }

        $request->session()->forget(['two_factor_user_id', 'two_factor_remember', 'two_factor_context', 'auth_surface']);

        if ($user->two_factor_confirmed_at) {
            $request->session()->regenerate();
            $request->session()->put([
                'two_factor_user_id' => $user->id,
                'two_factor_remember' => $request->boolean('remember'),
                'two_factor_context' => 'public',
            ]);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $request->session()->put('auth_surface', 'public');
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip(), 'failed_login_count' => 0, 'locked_until' => null, 'lock_reason' => null])->save();
        $this->recordLogin($request, $user, true, null, $email);
        $audit->record('auth.login.succeeded', $user->organization_id, $user);

        if (! $user->isActive()) {
            return redirect()->to($this->destination($user));
        }

        return redirect()->intended($this->destination($user));
    }

    public function destroy(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();
        $surface = $request->session()->get('auth_surface');
        if ($user) {
            $audit->record('auth.logout', $user->organization_id, $user, metadata: ['surface' => $surface ?: 'public']);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route($surface === 'admin' ? 'admin.login' : 'login');
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

    private function destination(User $user): string
    {
        if (! $user->isActive()) {
            return $user->hasVerifiedEmail()
                ? route('publisher-application.show')
                : route('verification.notice');
        }

        $application = $user->publisherApplication()->with('publisher')->first();
        if ($application?->approved_at && ! $application->publisher?->onboarding_submitted_at) {
            return route('publisher.onboarding.show', 1);
        }

        return route('dashboard');
    }
}
