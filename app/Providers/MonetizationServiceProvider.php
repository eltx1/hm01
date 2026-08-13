<?php

namespace App\Providers;

use App\Http\Controllers\Publisher\MonetizationController;
use App\Models\Site;
use App\Services\Monetization\SiteMonetizationReadinessService;
use App\Services\Monetization\SiteServingOverviewService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class MonetizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa'])->group(function (): void {
            Route::get('/publisher/monetization', [MonetizationController::class, 'index'])
                ->middleware('permission:sites.view')
                ->name('publisher.monetization.index');
        });

        View::composer('publisher.sites.show', function ($view): void {
            $data = $view->getData();
            $site = $data['site'] ?? null;
            if (! $site instanceof Site) {
                return;
            }

            $internal = (bool) ($data['internal'] ?? false);
            $service = app(SiteMonetizationReadinessService::class);
            $view->with('monetization', $internal ? $service->admin($site) : $service->publisher($site));
            if ($internal) {
                $view->with('servingOverview', app(SiteServingOverviewService::class)->forSite($site));
            }
        });
    }
}
