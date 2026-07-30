<?php

namespace App\Http\Middleware;

use App\Services\Operations\PlatformControlService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class EnsurePlatformAvailable
{
    public function __construct(private readonly PlatformControlService $controls) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $maintenance = $this->controls->enabled('maintenance_mode');
            $platformEnabled = $this->controls->enabled('platform_enabled');
        } catch (Throwable) {
            return $next($request);
        }

        $recoveryPath = $request->is('up')
            || $request->is('login')
            || $request->is('forgot-password')
            || $request->is('reset-password/*')
            || $request->is('invitations/*')
            || $request->is('two-factor/*');

        if ((! $maintenance && $platformEnabled) || $recoveryPath) {
            return $next($request);
        }

        if ($request->user()?->isHorusAdministrator()) return $next($request);

        return response()->view('errors.503', ['message' => $maintenance
            ? 'Horus Media is temporarily in maintenance mode.'
            : 'The Horus Media control plane is temporarily unavailable.'], 503, ['Retry-After' => '300']);
    }
}
