<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\AccountSessionService;
use App\Services\Identity\SessionInvalidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SecurityController extends Controller
{
    private const SAFE_EVENTS = [
        'account.password.changed' => 'Password changed',
        'account.profile.updated' => 'Profile updated',
        'account.session.revoked' => 'Session revoked',
        'account.sessions.revoked_other' => 'Other sessions signed out',
        'auth.password.reset' => 'Password reset',
        'auth.two_factor.enabled' => 'Two-factor authentication enabled',
        'auth.two_factor.disabled' => 'Two-factor authentication disabled',
        'auth.two_factor.recovery_regenerated' => 'Recovery codes regenerated',
        'auth.two_factor.challenge_passed' => 'Two-factor authentication passed',
        'auth.two_factor.recovery_used' => 'Recovery code used',
        'auth.login.succeeded' => 'Sign-in completed',
        'admin_auth.succeeded' => 'Staff sign-in completed',
        'auth.logout' => 'Signed out',
    ];

    public function show(Request $request, AccountSessionService $sessions): View
    {
        $user = $request->user();
        $activeSessions = $sessions->sessionsFor(
            $user,
            $request->session()->getId(),
            $request->userAgent(),
        );
        $events = AuditLog::query()
            ->where('actor_type', $user->getMorphClass())
            ->where('actor_id', $user->getKey())
            ->whereIn('event', array_keys(self::SAFE_EVENTS))
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (AuditLog $event): array => [
                'label' => self::SAFE_EVENTS[$event->event] ?? 'Security event',
                'occurred_at' => $event->created_at,
            ]);

        return view('account.security', [
            'user' => $user,
            'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
            'staffTwoFactorRequired' => $user->isHorusAdministrator(),
            'activeSessions' => $activeSessions,
            'otherSessionCount' => count(array_filter($activeSessions, fn (array $session): bool => ! $session['current'])),
            'sessionManagementAvailable' => $sessions->usesDatabaseSessions(),
            'securityEvents' => $events,
        ]);
    }

    public function updatePassword(
        Request $request,
        SessionInvalidator $sessionInvalidator,
        AuditRecorder $audit,
    ): RedirectResponse {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(14)->mixedCase()->numbers()->symbols()],
        ]);
        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => 'The current password is incorrect.',
            ]);
        }

        $currentSessionId = $request->session()->getId();
        $user->forceFill([
            'password' => Hash::make($data['password']),
            'remember_token' => Str::random(60),
            'password_changed_at' => now(),
        ])->save();

        $sessionInvalidator->invalidate($user, $currentSessionId);
        $request->session()->regenerate();
        $request->session()->regenerateToken();
        $audit->record('account.password.changed', $user->organization_id, $user, $user, request: $request);

        return redirect()->route('account.security')->with('status', 'Password changed. Other sessions have been signed out.');
    }
}
