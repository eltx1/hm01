<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\Identity\PublisherImpersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class ValidateImpersonationSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->has('impersonator_id')) return $next($request);

        $access = app(PublisherImpersonation::class);
        $original = User::find($request->session()->get('impersonator_id'));
        $target = $request->user();
        if (! $access->canStart($original) || ! $target) {
            app(AuditRecorder::class)->record('admin.impersonation.revoked', $target?->organization_id, $original, $target);
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('admin.login')->withErrors(['email' => 'Your administrator session is no longer available. Please sign in again.']);
        }

        // Returning remains possible if the publisher was suspended, locked,
        // or otherwise lost eligibility during the temporary session.
        if (! $request->routeIs('admin.impersonate.stop', 'logout') && ! $access->canBeTarget($target)) {
            return response()->view('errors.403', [], 403);
        }

        return $next($request);
    }
}
