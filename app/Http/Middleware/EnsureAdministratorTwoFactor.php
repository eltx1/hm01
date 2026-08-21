<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdministratorTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.authentication.administrator_2fa_required', true)) {
            return $next($request);
        }

        $user = $request->user();
        if (! $user?->isHorusAdministrator()) {
            return $next($request);
        }

        if (! $user->two_factor_confirmed_at) {
            return redirect()->route('two-factor.setup');
        }

        $passedAt = (int) $request->session()->get('two_factor_passed_at', 0);
        if ($passedAt < now()->subHours(12)->timestamp) {
            $request->session()->forget('two_factor_passed_at');
            $request->session()->put('two_factor_user_id', $user->id);
            Auth::logout();

            return redirect()->route('two-factor.challenge');
        }

        return $next($request);
    }
}
