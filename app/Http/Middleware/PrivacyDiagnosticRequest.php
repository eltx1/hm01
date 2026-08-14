<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PrivacyDiagnosticRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $origin = (string) $request->headers->get('Origin');
        $scheme = strtolower((string) parse_url($origin, PHP_URL_SCHEME));
        $hostname = strtolower(rtrim((string) parse_url($origin, PHP_URL_HOST), '.'));
        $port = parse_url($origin, PHP_URL_PORT);
        if ($scheme !== 'https' || ($port !== null && $port !== 443) || $hostname === '' || ! $this->knownHostname($hostname)) {
            abort(403, 'Privacy diagnostic origin is not authorized.');
        }

        if ($request->isMethod('OPTIONS')) {
            if (strtoupper((string) $request->header('Access-Control-Request-Method')) !== 'POST'
                || ! str_contains(strtolower((string) $request->header('Access-Control-Request-Headers')), 'x-horus-diagnostic-token')) {
                abort(403, 'Privacy diagnostic preflight is invalid.');
            }

            return $this->cors(response('', 204), $origin);
        }

        $maximum = max(1024, min(16384, (int) config('privacy.diagnostic_max_bytes', 4096)));
        $length = (int) $request->headers->get('Content-Length', 0);
        if ($length > $maximum || strlen($request->getContent()) > $maximum) {
            abort(413, 'Privacy diagnostic payload is too large.');
        }
        if (! $request->isJson()) {
            abort(415, 'Privacy diagnostic payload must be JSON.');
        }

        return $this->cors($next($request), $origin);
    }

    private function knownHostname(string $hostname): bool
    {
        return Site::withoutGlobalScopes()->where('primary_domain', $hostname)
            ->orWhereHas('domains', fn ($query) => $query->where('domain', $hostname))
            ->exists();
    }

    private function cors(Response $response, string $origin): Response
    {
        $response->headers->set('Access-Control-Allow-Origin', $origin);
        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-Horus-Diagnostic-Token');
        $response->headers->set('Access-Control-Max-Age', '300');
        $response->headers->set('Vary', 'Origin');
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
