<?php

use App\Http\Controllers\Admin\DemandNetworkController;
use App\Http\Controllers\Admin\DirectDemandAccountController;
use App\Http\Controllers\Admin\DirectDemandQuickMonetizeController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'admin.2fa', 'horus'])->prefix('admin')->group(function (): void {
    Route::get('/demand', [DemandNetworkController::class, 'index'])
        ->middleware('permission:demand.view')->name('admin.demand.index');

    Route::get('/demand/quick', [DirectDemandQuickMonetizeController::class, 'create'])
        ->middleware('permission:demand.manage')->name('admin.demand.quick.create');
    Route::post('/demand/quick', [DirectDemandQuickMonetizeController::class, 'store'])
        ->middleware('permission:demand.manage')->name('admin.demand.quick.store');

    Route::get('/demand/accounts/create', [DirectDemandAccountController::class, 'create'])
        ->middleware('permission:demand.manage')->name('admin.demand.accounts.create');
    Route::post('/demand/accounts', [DirectDemandAccountController::class, 'store'])
        ->middleware('permission:demand.manage')->name('admin.demand.accounts.store');
    Route::get('/demand/accounts/{demandAccount}', [DirectDemandAccountController::class, 'show'])
        ->middleware('permission:demand.view')->name('admin.demand.accounts.show');
    Route::put('/demand/accounts/{demandAccount}', [DirectDemandAccountController::class, 'update'])
        ->middleware('permission:demand.manage')->name('admin.demand.accounts.update');

    Route::patch('/demand/master', [DemandNetworkController::class, 'toggleMaster'])
        ->middleware('permission:demand.manage')->name('admin.demand.master');
    Route::patch('/demand/networks/{demandNetwork}/enabled', [DemandNetworkController::class, 'toggleNetwork'])
        ->middleware('permission:demand.manage')->name('admin.demand.networks.toggle');
    Route::put('/demand/networks/{demandNetwork}', [DemandNetworkController::class, 'updateNetwork'])
        ->middleware('permission:demand.manage')->name('admin.demand.networks.settings');
    Route::patch('/demand/networks/{demandNetwork}/runtime', [DemandNetworkController::class, 'toggleNetworkRuntime'])
        ->middleware('permission:demand.manage')->name('admin.demand.networks.direct-js');

    Route::patch('/demand/accounts/{demandAccount}/enabled', [DemandNetworkController::class, 'toggleAccount'])
        ->middleware('permission:demand.manage')->name('admin.demand.accounts.enabled');
    Route::post('/demand/accounts/{demandAccount}/tag-preview', [DemandNetworkController::class, 'tagPreview'])
        ->middleware('permission:demand.manage')->name('admin.demand.tags.preview');
    Route::put('/demand/accounts/{demandAccount}/financial-source', [DemandNetworkController::class, 'updateFinancialSource'])
        ->middleware('permission:demand.manage')->name('admin.demand.accounts.financial-source');
    Route::post('/demand/accounts/{demandAccount}/credentials', [DemandNetworkController::class, 'storeCredential'])
        ->middleware('permission:demand.manage')->name('admin.demand.credentials.store');
    Route::post('/demand/accounts/{demandAccount}/test', [DemandNetworkController::class, 'testAccount'])
        ->middleware('permission:demand.test')->name('admin.demand.accounts.test');
    Route::post('/demand/accounts/{demandAccount}/review', [DemandNetworkController::class, 'reviewAccount'])
        ->middleware('permission:demand.manage')->name('admin.demand.accounts.review');
    Route::post('/demand/accounts/{demandAccount}/reports/api', [DemandNetworkController::class, 'runApiReport'])
        ->middleware('permission:demand.reports')->name('admin.demand.reports.api');
    Route::post('/demand/accounts/{demandAccount}/reports/csv', [DemandNetworkController::class, 'importCsv'])
        ->middleware('permission:demand.reports')->name('admin.demand.reports.csv');

    Route::get('/sites/{site}/demand', [DemandNetworkController::class, 'site'])
        ->middleware('permission:demand.view')->name('admin.sites.demand.show');
    Route::patch('/sites/{site}/demand', [DemandNetworkController::class, 'toggleSiteNative'])
        ->middleware('permission:demand.manage')->name('admin.sites.demand.status');
    Route::post('/sites/{site}/demand/accounts/{demandAccount}', [DemandNetworkController::class, 'assignSite'])
        ->middleware('permission:demand.manage')->name('admin.sites.demand.assign');
    Route::put('/sites/{site}/demand/mappings/{demandSite}', [DemandNetworkController::class, 'updateSite'])
        ->middleware('permission:demand.manage')->name('admin.sites.demand.mappings.update');
    Route::patch('/sites/{site}/demand/mappings/{demandSite}/enabled', [DemandNetworkController::class, 'toggleSiteMapping'])
        ->middleware('permission:demand.manage')->name('admin.sites.demand.mappings.enabled');
    Route::post('/sites/{site}/demand/mappings/{demandSite}/sync', [DemandNetworkController::class, 'syncSite'])
        ->middleware('permission:demand.test')->name('admin.sites.demand.mappings.sync');
    Route::post('/sites/{site}/demand/mappings/{demandSite}/status', [DemandNetworkController::class, 'refreshSiteStatus'])
        ->middleware('permission:demand.test')->name('admin.sites.demand.mappings.status');
    Route::post('/sites/{site}/demand/mappings/{demandSite}/ads-txt', [DemandNetworkController::class, 'syncAdsTxt'])
        ->middleware('permission:demand.manage')->name('admin.sites.demand.ads_txt');
    Route::post('/sites/{site}/demand/mappings/{demandSite}/gam', [DemandNetworkController::class, 'deployGam'])
        ->middleware('permission:demand.deploy')->name('admin.sites.demand.gam.deploy');

    Route::post('/sites/{site}/demand/mappings/{demandSite}/placements/{placement}', [DemandNetworkController::class, 'assignPlacement'])
        ->middleware('permission:demand.manage')->name('admin.sites.demand.placements.assign');
    Route::put('/sites/{site}/demand/placements/{demandPlacement}', [DemandNetworkController::class, 'updatePlacement'])
        ->middleware('permission:demand.manage')->name('admin.sites.demand.placements.update');
    Route::patch('/sites/{site}/demand/placements/{demandPlacement}/enabled', [DemandNetworkController::class, 'togglePlacementMapping'])
        ->middleware('permission:demand.manage')->name('admin.sites.demand.placements.enabled');
    Route::post('/sites/{site}/demand/placements/{demandPlacement}/sync', [DemandNetworkController::class, 'syncPlacement'])
        ->middleware('permission:demand.test')->name('admin.sites.demand.placements.sync');
    Route::post('/sites/{site}/demand/placements/{demandPlacement}/status', [DemandNetworkController::class, 'placementStatus'])
        ->middleware('permission:demand.test')->name('admin.sites.demand.placements.status');
    Route::post('/sites/{site}/demand/placements/{demandPlacement}/widgets', [DemandNetworkController::class, 'storeWidget'])
        ->middleware('permission:demand.manage')->name('admin.sites.demand.widgets.store');
});
