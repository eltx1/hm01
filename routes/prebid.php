<?php

use App\Http\Controllers\Admin\PrebidController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'admin.2fa'])->prefix('admin/prebid')->name('admin.prebid.')->group(function (): void {
    Route::get('/', [PrebidController::class, 'index'])->middleware('permission:prebid.view')->name('index');
    Route::post('/accounts', [PrebidController::class, 'storeAccount'])->middleware('permission:prebid.manage')->name('accounts.store');
    Route::post('/accounts/{bidderAccount}/toggle', [PrebidController::class, 'toggleAccount'])->middleware('permission:prebid.manage')->name('accounts.toggle');

    Route::get('/sites/{site}', [PrebidController::class, 'site'])->middleware('permission:prebid.view')->name('sites.show');
    Route::put('/sites/{site}/settings', [PrebidController::class, 'updateSettings'])->middleware('permission:prebid.manage')->name('sites.settings.update');
    Route::post('/sites/{site}/accounts', [PrebidController::class, 'assignSite'])->middleware('permission:prebid.manage')->name('sites.accounts.assign');
    Route::post('/sites/{site}/mappings/{bidderSiteMapping}/placements', [PrebidController::class, 'assignPlacement'])->middleware('permission:prebid.manage')->name('sites.placements.assign');

    Route::put('/connections/{gamConnection}/template', [PrebidController::class, 'updateTemplate'])->middleware('permission:prebid.gam.manage')->name('templates.update');
    Route::post('/connections/{gamConnection}/preview', [PrebidController::class, 'previewSetup'])->middleware('permission:prebid.gam.manage')->name('connections.preview');
    Route::get('/setup-runs/{prebidSetupRun}', [PrebidController::class, 'showRun'])->middleware('permission:prebid.gam.manage')->name('setup-runs.show');
    Route::post('/setup-runs/{prebidSetupRun}/execute', [PrebidController::class, 'executeSetup'])->middleware(['permission:prebid.gam.manage', 'throttle:30,1'])->name('setup-runs.execute');
});
