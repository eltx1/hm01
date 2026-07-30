<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\SessionInvalidator;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink($request->only('email'));

        return back()->with('status', 'If that account exists, a reset link has been sent.');
    }

    public function reset(Request $request, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request, SessionInvalidator $sessions, AuditRecorder $audit): RedirectResponse
    {
        $data = $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(14)->mixedCase()->numbers()->symbols()]]);
        $status = Password::reset($data, function (User $user, string $password) use ($sessions, $audit): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'password_changed_at' => now(), 'failed_login_count' => 0, 'locked_until' => null, 'lock_reason' => null])->save();
            $sessions->invalidate($user);
            $audit->record('auth.password.reset', $user->organization_id, $user);
            event(new PasswordReset($user));
        });

        return $status === Password::PasswordReset
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }
}
