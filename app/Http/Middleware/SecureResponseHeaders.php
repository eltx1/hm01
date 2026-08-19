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
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($request));
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-site');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        if ($request->isSecure() && app()->environment('production')) {
            $value = 'max-age='.(int) config('security.headers.hsts_max_age');
            if (config('security.headers.hsts_include_subdomains')) $value .= '; includeSubDomains';
            if (config('security.headers.hsts_preload')) $value .= '; preload';
            $response->headers->set('Strict-Transport-Security', $value);
        }
        return $response;
    }

    private function contentSecurityPolicy(Request $request): string
    {
        $policy = trim((string) config('security.headers.content_security_policy'));

        if (config('publisher-applications.turnstile.enabled')) {
            $policy = $this->appendSource(
                $this->appendSource($policy, 'script-src', 'https://challenges.cloudflare.com'),
                'frame-src',
                'https://challenges.cloudflare.com',
            );
        }

        // Task 51 Admin Client Test embeds only the Task 49 pure-static gate origin.
        // Keep this exception isolated to the Traffic Quality page; no other Admin
        // surface gains cross-origin frame permission. The test runtime itself is
        // shipped in the self-hosted Vite bundle, so inline script remains blocked.
        if ($request->routeIs('admin.operations.traffic-quality')) {
            $policy = $this->appendSource($policy, 'frame-src', 'https://verify.horusmedia.net');
            $policy = $this->removeSource($policy, 'script-src', "'unsafe-inline'");
        }

        return $policy;
    }

    private function appendSource(string $policy, string $directive, string $source): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(';', $policy))));
        $found = false;
        foreach ($parts as &$part) {
            if ($part === $directive || str_starts_with($part, $directive.' ')) {
                $found = true;
                $sources = preg_split('/\s+/', $part) ?: [];
                if (! in_array($source, $sources, true)) {
                    $part .= ' '.$source;
                }
            }
        }
        unset($part);
        if (! $found) {
            $parts[] = $directive.' '.$source;
        }

        return implode('; ', $parts);
    }

    private function removeSource(string $policy, string $directive, string $source): string
    {
        $parts = array_values(array_filter(array_map('trim', explode(';', $policy))));
        foreach ($parts as &$part) {
            if ($part !== $directive && ! str_starts_with($part, $directive.' ')) {
                continue;
            }

            $tokens = preg_split('/\s+/', $part) ?: [];
            $name = array_shift($tokens);
            $tokens = array_values(array_filter($tokens, fn (string $token): bool => $token !== $source));
            $part = trim(($name ?: $directive).' '.implode(' ', $tokens));
        }
        unset($part);

        return implode('; ', $parts);
    }
}
