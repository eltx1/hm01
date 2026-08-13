<?php

namespace App\Providers;

use App\Http\Controllers\Admin\DemandNetworkController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DemandServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa', 'horus'])->group(function (): void {
            Route::get('/admin/demand', [DemandNetworkController::class, 'index'])
                ->middleware('permission:demand.view')->name('admin.demand.index');
            Route::patch('/admin/demand/master', [DemandNetworkController::class, 'toggleMaster'])
                ->middleware('permission:demand.manage')->name('admin.demand.master');
            Route::get('/admin/sites/{site}/demand', [DemandNetworkController::class, 'site'])
                ->middleware('permission:demand.view')->name('admin.sites.demand.index');
            Route::patch('/admin/sites/{site}/demand/status', [DemandNetworkController::class, 'toggleSiteNative'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.status');

            Route::post('/admin/demand/accounts', [DemandNetworkController::class, 'storeAccount'])
                ->middleware('permission:demand.manage')->name('admin.demand.accounts.store');
            Route::put('/admin/demand/accounts/{demandAccount}', [DemandNetworkController::class, 'updateAccount'])
                ->middleware('permission:demand.manage')->name('admin.demand.accounts.update');
            Route::put('/admin/demand/accounts/{demandAccount}/financial-source', [DemandNetworkController::class, 'updateFinancialSource'])
                ->middleware('permission:reporting.sources.manage')->name('admin.demand.accounts.financial-source');
            Route::patch('/admin/demand/accounts/{demandAccount}/enabled', [DemandNetworkController::class, 'toggleAccount'])
                ->middleware('permission:demand.manage')->name('admin.demand.accounts.enabled');
            Route::post('/admin/demand/accounts/{demandAccount}/tags/preview', [DemandNetworkController::class, 'tagPreview'])
                ->middleware('permission:demand.manage')->name('admin.demand.tags.preview');
            Route::post('/admin/demand/accounts/{demandAccount}/credentials', [DemandNetworkController::class, 'storeCredential'])
                ->middleware('permission:demand.manage')->name('admin.demand.credentials.store');
            Route::post('/admin/demand/accounts/{demandAccount}/test', [DemandNetworkController::class, 'testAccount'])
                ->middleware('permission:demand.test')->name('admin.demand.accounts.test');
            Route::post('/admin/demand/accounts/{demandAccount}/review', [DemandNetworkController::class, 'reviewAccount'])
                ->middleware('permission:demand.manage')->name('admin.demand.accounts.review');
            Route::patch('/admin/demand/networks/{demandNetwork}', [DemandNetworkController::class, 'toggleNetwork'])
                ->middleware('permission:demand.manage')->name('admin.demand.networks.toggle');
            Route::put('/admin/demand/networks/{demandNetwork}/settings', [DemandNetworkController::class, 'updateNetwork'])
                ->middleware('permission:demand.manage')->name('admin.demand.networks.settings');
            Route::patch('/admin/demand/networks/{demandNetwork}/direct-js', [DemandNetworkController::class, 'toggleNetworkRuntime'])
                ->middleware('permission:demand.manage')->name('admin.demand.networks.direct-js');

            Route::post('/admin/sites/{site}/demand/accounts/{demandAccount}', [DemandNetworkController::class, 'assignSite'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.assign');
            Route::put('/admin/sites/{site}/demand/mappings/{demandSite}', [DemandNetworkController::class, 'updateSite'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.mappings.update');
            Route::patch('/admin/sites/{site}/demand/mappings/{demandSite}/enabled', [DemandNetworkController::class, 'toggleSiteMapping'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.mappings.enabled');
            Route::post('/admin/sites/{site}/demand/mappings/{demandSite}/sync', [DemandNetworkController::class, 'syncSite'])
                ->middleware('permission:demand.test')->name('admin.sites.demand.mappings.sync');
            Route::post('/admin/sites/{site}/demand/mappings/{demandSite}/status', [DemandNetworkController::class, 'refreshSiteStatus'])
                ->middleware('permission:demand.test')->name('admin.sites.demand.mappings.status');
            Route::post('/admin/sites/{site}/demand/mappings/{demandSite}/ads-txt', [DemandNetworkController::class, 'syncAdsTxt'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.ads_txt');

            Route::post('/admin/sites/{site}/demand/mappings/{demandSite}/placements/{placement}', [DemandNetworkController::class, 'assignPlacement'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.placements.assign');
            Route::put('/admin/sites/{site}/demand/placements/{demandPlacement}', [DemandNetworkController::class, 'updatePlacement'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.placements.update');
            Route::patch('/admin/sites/{site}/demand/placements/{demandPlacement}/enabled', [DemandNetworkController::class, 'togglePlacementMapping'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.placements.enabled');
            Route::post('/admin/sites/{site}/demand/placements/{demandPlacement}/widgets', [DemandNetworkController::class, 'storeWidget'])
                ->middleware('permission:demand.manage')->name('admin.sites.demand.widgets.store');
            Route::post('/admin/sites/{site}/demand/placements/{demandPlacement}/sync', [DemandNetworkController::class, 'syncPlacement'])
                ->middleware('permission:demand.test')->name('admin.sites.demand.placements.sync');
            Route::post('/admin/sites/{site}/demand/placements/{demandPlacement}/status', [DemandNetworkController::class, 'placementStatus'])
                ->middleware('permission:demand.deploy')->name('admin.sites.demand.placements.status');

            Route::post('/admin/sites/{site}/demand/mappings/{demandSite}/gam', [DemandNetworkController::class, 'deployGam'])
                ->middleware('permission:demand.deploy')->name('admin.sites.demand.gam.deploy');
            Route::post('/admin/demand/accounts/{demandAccount}/reports/api', [DemandNetworkController::class, 'runApiReport'])
                ->middleware('permission:demand.reports')->name('admin.demand.reports.api');
            Route::post('/admin/demand/accounts/{demandAccount}/reports/csv', [DemandNetworkController::class, 'importCsv'])
                ->middleware('permission:demand.reports')->name('admin.demand.reports.csv');
        });
    }
}
