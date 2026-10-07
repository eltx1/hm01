<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\PublisherImpersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function start(Request $request, User $user, AuditRecorder $audit, PublisherImpersonation $access): RedirectResponse
    {
        abort_if($request->session()->has('impersonator_id'), 409, 'Already impersonating.');
        $impersonator = $request->user();
        abort_unless($access->canStart($impersonator) && $impersonator->id !== $user->id && $access->canBeTarget($user), 403);
        $passedAt = $request->session()->get('two_factor_passed_at');
        $audit->record('admin.impersonation.started', $user->organization_id, $impersonator, $user);

        // Do not carry tenant data, password confirmations, recovery codes, or MFA
        // challenges across identities. Keep only the original staff MFA time.
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::login($user);
        $request->session()->put([
            'impersonator_id' => $impersonator->id,
            'impersonator_two_factor_passed_at' => $passedAt,
            'auth_surface' => 'admin',
        ]);

        return redirect()->route('dashboard');
    }

    public function stop(Request $request, AuditRecorder $audit, PublisherImpersonation $access): RedirectResponse
    {
        $id = $request->session()->get('impersonator_id');
        abort_unless($id, 409);
        $target = $request->user();
        $impersonator = User::find($id);
        if (! $access->canStart($impersonator)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors(['email' => 'Your administrator session is no longer available. Please sign in again.']);
        }
        $passedAt = $request->session()->get('impersonator_two_factor_passed_at');
        $audit->record('admin.impersonation.stopped', $target->organization_id, $impersonator, $target);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Auth::login($impersonator);
        $request->session()->put(['two_factor_passed_at' => $passedAt, 'auth_surface' => 'admin']);

        return redirect()->route('dashboard');
    }
}
