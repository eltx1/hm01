<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecureResponseHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', (string) config('security.headers.referrer_policy'));
        $response->headers->set('Permissions-Policy', (string) config('security.headers.permissions_policy'));
        $response->headers->set('Content-Security-Policy', (string) config('security.headers.content_security_policy'));
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
        if ($request->isSecure() && app()->environment('production')) {
            $value = 'max-age='.(int) config('security.headers.hsts_max_age');
            if (config('security.headers.hsts_include_subdomains')) $value .= '; includeSubDomains';
            if (config('security.headers.hsts_preload')) $value .= '; preload';
            $response->headers->set('Strict-Transport-Security', $value);
        }
        return $response;
    }
}
