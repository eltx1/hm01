<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdministratorTwoFactor;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePlatformAvailable;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateTrustedHost;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES');
        if (is_string($trustedProxies) && $trustedProxies !== '') {
            $middleware->trustProxies(
                at: $trustedProxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
                headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX,
            );
        }
        $middleware->prepend(SecurityHeaders::class);
        $middleware->prepend(ValidateTrustedHost::class);
        $middleware->prepend(AssignRequestId::class);
        $middleware->web(append: [EnsurePlatformAvailable::class]);
        $middleware->validateCsrfTokens(except: []);
        $middleware->alias(['active' => EnsureActiveUser::class, 'admin.2fa' => EnsureAdministratorTwoFactor::class, 'permission' => EnsurePermission::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (Throwable $exception): void {
            Log::error('unhandled_exception', array_filter([
                'exception' => $exception::class,
                'message' => app()->environment('production') ? 'Unhandled application exception.' : $exception->getMessage(),
                'request_id' => request()->header('X-Request-ID'),
                'path' => request()->path(),
                'user_id' => request()->user()?->id,
            ]));
        })->stop();
    })->create();
