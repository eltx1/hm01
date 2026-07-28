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
        } elseif (! $user->isActive()) {
            $reason = 'account_inactive';
        }

        if ($reason) {
            if ($user && ! $user->trashed()) {
                $user->forceFill(['failed_login_count' => $user->failed_login_count + 1, 'last_failed_login_at' => now()])->save();
            }
            $this->recordLogin($request, $user, false, $reason, $email);

            return back()->withErrors(['email' => 'The provided credentials or account status are invalid.'])->onlyInput('email');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip(), 'failed_login_count' => 0])->save();
        $this->recordLogin($request, $user, true, null, $email);
        $audit->record('auth.login.succeeded', $user->organization_id, $user);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();
        if ($user) {
            $audit->record('auth.logout', $user->organization_id, $user);
        }
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
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
