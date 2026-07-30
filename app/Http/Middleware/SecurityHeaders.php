<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        if (config('security.headers.csp', true)) {
            $directives = [
                "default-src 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "frame-ancestors 'none'",
                "form-action 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "media-src 'self' https:",
                "worker-src 'self' blob:",
                'upgrade-insecure-requests',
            ];
            if ($uri = config('security.headers.csp_report_uri')) {
                $directives[] = 'report-uri '.$uri;
            }
            $header = config('security.headers.csp_report_only', false)
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';
            $response->headers->set($header, implode('; ', $directives));
        }

        if ($request->isSecure() && config('security.headers.hsts', true)) {
            $value = 'max-age='.(int) config('security.headers.hsts_max_age', 31536000);
            if (config('security.headers.hsts_include_subdomains', true)) $value .= '; includeSubDomains';
            if (config('security.headers.hsts_preload', false)) $value .= '; preload';
            $response->headers->set('Strict-Transport-Security', $value);
        }

        return $response;
    }
}
