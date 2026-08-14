<?php

namespace App\Providers;

use App\Http\Controllers\Admin\PrivacyReadinessController;
use App\Http\Controllers\PrivacyDiagnosticReportController;
use App\Http\Middleware\PrivacyDiagnosticRequest;
use App\Models\Site;
use App\Services\Privacy\PrivacyReadinessService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class PrivacyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware([PrivacyDiagnosticRequest::class, 'throttle:privacy-diagnostic-report'])->group(function (): void {
            Route::options('/privacy-diagnostics/report', fn () => response('', 204));
            Route::post('/privacy-diagnostics/report', PrivacyDiagnosticReportController::class)
                ->name('privacy-diagnostics.report');
        });

        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa', 'horus'])->group(function (): void {
            Route::post('/admin/sites/{site}/privacy-diagnostics', [PrivacyReadinessController::class, 'run'])
                ->middleware(['permission:configs.manage', 'throttle:privacy-diagnostic-run'])
                ->name('admin.sites.privacy-diagnostics.run');
            Route::put('/admin/sites/{site}/google-cmp-evidence', [PrivacyReadinessController::class, 'googleCmp'])
                ->middleware(['permission:configs.manage', 'throttle:sensitive'])
                ->name('admin.sites.google-cmp-evidence.update');
        });

        View::composer('publisher.sites.show', function ($view): void {
            $site = $view->getData()['site'] ?? null;
            if (! $site instanceof Site) {
                return;
            }
            $internal = (bool) ($view->getData()['internal'] ?? false);
            $service = app(PrivacyReadinessService::class);
            $view->with('privacyReadiness', $internal ? $service->admin($site) : $service->publisher($site));
        });
    }
}
