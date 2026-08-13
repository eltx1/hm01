<?php

namespace App\Providers;

use App\Http\Controllers\Admin\PrebidController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PrebidServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa'])->group(function (): void {
            Route::get('/admin/sites/{site}/prebid', [PrebidController::class, 'index'])
                ->middleware('permission:inventory.view')->name('admin.sites.prebid.index');
            Route::put('/admin/sites/{site}/prebid/settings', [PrebidController::class, 'updateSettings'])
                ->middleware('permission:inventory.manage')->name('admin.sites.prebid.settings');
            Route::post('/admin/prebid/accounts', [PrebidController::class, 'storeAccount'])
                ->middleware('permission:inventory.manage')->name('admin.prebid.accounts.store');
            Route::put('/admin/prebid/accounts/{bidderAccount}/financial-source', [PrebidController::class, 'updateFinancialSource'])
                ->middleware(['horus', 'permission:reporting.sources.manage'])->name('admin.prebid.accounts.financial-source');
            Route::post('/admin/sites/{site}/prebid/accounts/{bidderAccount}', [PrebidController::class, 'assignSite'])
                ->middleware('permission:inventory.manage')->name('admin.sites.prebid.accounts.assign');
            Route::post('/admin/sites/{site}/prebid/mappings/{bidderSiteMapping}/placements/{placement}', [PrebidController::class, 'assignPlacement'])
                ->middleware('permission:inventory.manage')->name('admin.sites.prebid.placements.assign');
            Route::patch('/admin/sites/{site}/prebid/mappings/{bidderSiteMapping}', [PrebidController::class, 'toggleSiteMapping'])
                ->middleware('permission:inventory.manage')->name('admin.sites.prebid.mappings.toggle');
            Route::patch('/admin/sites/{site}/prebid/placement-mappings/{bidderPlacementMapping}', [PrebidController::class, 'togglePlacementMapping'])
                ->middleware('permission:inventory.manage')->name('admin.sites.prebid.placement-mappings.toggle');
            Route::post('/admin/gam/connections/{gamConnection}/prebid/setup', [PrebidController::class, 'setup'])
                ->middleware('permission:gam.connections.manage')->name('admin.gam.prebid.setup');
            Route::post('/admin/prebid/setup-runs/{prebidSetupRun}/resume', [PrebidController::class, 'resume'])
                ->middleware('permission:gam.connections.manage')->name('admin.gam.prebid.resume');
        });
    }
}
