<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ValidateTrustedHost
{
    public function handle(Request $request, Closure $next): Response
    {
        $patterns = array_values(array_filter((array) config('security.trusted_hosts', [])));

        if (app()->environment('production') && $patterns !== [] && ! $this->matches($request->getHost(), $patterns)) {
            abort(400, 'Untrusted host.');
        }

        return $next($request);
    }

    private function matches(string $host, array $patterns): bool
    {
        $host = strtolower($host);
        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '' || $pattern === $host) {
                if ($pattern === $host) return true;
                continue;
            }
            if (str_starts_with($pattern, '*.') && ($host === substr($pattern, 2) || str_ends_with($host, substr($pattern, 1)))) {
                return true;
            }
        }
        return false;
    }
}
