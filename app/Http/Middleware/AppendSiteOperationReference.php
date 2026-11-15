<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/** Correlate successful site writes without resubmitting or publishing anything. */
final class AppendSiteOperationReference
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $request->isMethodSafe() && $request->routeIs('admin.sites.*')
            && $response->isRedirect() && $request->hasSession()) {
            $session = $request->session();
            $new = $session->get('_flash.new', []);
            $reference = $request->header('X-Request-ID');
            if (in_array('status', $new, true) && ! in_array('errors', $new, true)
                && ! in_array('operation_reference', $new, true)
                && is_string($reference) && Str::isUuid($reference)) {
                $session->flash('operation_reference', $reference);
            }
        }

        return $response;
    }
}
