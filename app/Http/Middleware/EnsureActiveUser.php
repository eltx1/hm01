<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isActive()) {
            return $next($request);
        }

        // A public Publisher applicant has a valid authenticated identity but no
        // operational Control Plane eligibility. Preserve their application-only
        // session and fail closed instead of turning the active middleware into an
        // account-approval bypass.
        if ($request->user()?->isPublisherApplicant()) {
            abort(403, 'This account is limited to the Publisher application portal.');
        }

        if ($request->user()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account is not active.']);
        }

        return redirect()->route('login');
    }
}
