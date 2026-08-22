<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureAdministratorTwoFactor;
use App\Http\Middleware\EnsureDashboardAccess;
use App\Http\Middleware\EnsureHorusAdministrator;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsurePublisherApplicant;
use App\Http\Middleware\SecureResponseHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')->group(base_path('routes/account.php'));
            Route::middleware('web')->group(base_path('routes/admin-auth.php'));
            Route::middleware('web')->group(base_path('routes/prebid-ads-txt.php'));
            Route::middleware('web')->group(base_path('routes/platform-ads-txt.php'));
            Route::middleware('web')->group(base_path('routes/thoth-applications.php'));
            Route::middleware('web')->group(base_path('routes/traffic-gate.php'));
        },
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
            'publisher.applicant' => EnsurePublisherApplicant::class,
        ]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('admin') || $request->is('admin/*')
            ? route('admin.login')
            : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'account_reference',
            'routing_reference',
            'tax_identifier',
            'payment_details',
            'cf-turnstile-response',
            '_company_website',
        ]);
        $exceptions->report(function (Throwable $exception): void {
            if (! app()->bound('log')) {
                return;
            }

            app('log')->error('unhandled_exception', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'request_id' => app()->bound('request') ? request()->header('X-Request-ID') : null,
            ]);
        })->stop();
    })->create();
