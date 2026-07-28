<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ImpersonationController extends Controller
{
    public function start(Request $request, User $user, AuditRecorder $audit): RedirectResponse
    {
        abort_unless($request->user()->isHorusAdministrator() && $user->isActive(), 403);
        abort_if($request->session()->has('impersonator_id'), 409, 'Already impersonating.');
        $impersonator = $request->user();
        $audit->record('admin.impersonation.started', $user->organization_id, $impersonator, $user);
        $request->session()->put('impersonator_id', $impersonator->id);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function stop(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $id = $request->session()->pull('impersonator_id');
        abort_unless($id, 409);
        $target = $request->user();
        $impersonator = User::findOrFail($id);
        Auth::login($impersonator);
        $request->session()->regenerate();
        $audit->record('admin.impersonation.stopped', $target->organization_id, $impersonator, $target);

        return redirect()->route('dashboard');
    }
}
