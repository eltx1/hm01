<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdministratorTwoFactor;
use App\Http\Middleware\EnsureDashboardAccess;
use App\Http\Middleware\EnsureHorusAdministrator;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\SecureResponseHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(SecureResponseHeaders::class);
        $middleware->validateCsrfTokens(except: []);
        $middleware->alias([
            'active' => EnsureActiveUser::class,
            'admin.2fa' => EnsureAdministratorTwoFactor::class,
            'dashboard.access' => EnsureDashboardAccess::class,
            'horus' => EnsureHorusAdministrator::class,
            'permission' => EnsurePermission::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): void {
            Log::error('unhandled_exception', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'request_id' => request()->header('X-Request-ID'),
            ]);
        })->stop();
    })->create();
