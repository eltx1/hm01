<?php

use App\Http\Controllers\Admin\PrebidController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'admin.2fa'])->group(function (): void {
    Route::get('/admin/sites/{site}/prebid', [PrebidController::class, 'index'])
        ->middleware('permission:prebid.view')->name('admin.sites.prebid.index');
    Route::post('/admin/sites/{site}/prebid/accounts', [PrebidController::class, 'storeAccount'])
        ->middleware('permission:prebid.manage')->name('admin.sites.prebid.accounts.store');
    Route::post('/admin/sites/{site}/prebid/accounts/{bidderAccount}/site', [PrebidController::class, 'assignSite'])
        ->middleware('permission:prebid.manage')->name('admin.sites.prebid.accounts.assign-site');
    Route::post('/admin/sites/{site}/prebid/placements/{placement}/accounts/{bidderAccount}', [PrebidController::class, 'assignPlacement'])
        ->middleware('permission:prebid.manage')->name('admin.sites.prebid.accounts.assign-placement');
    Route::patch('/admin/sites/{site}/prebid/accounts/{bidderAccount}', [PrebidController::class, 'toggleAccount'])
        ->middleware('permission:prebid.manage')->name('admin.sites.prebid.accounts.toggle');
    Route::put('/admin/sites/{site}/prebid/connections/{gamConnection}/settings', [PrebidController::class, 'settings'])
        ->middleware('permission:prebid.manage')->name('admin.sites.prebid.settings');
    Route::post('/admin/sites/{site}/prebid/connections/{gamConnection}/price-buckets', [PrebidController::class, 'priceBucket'])
        ->middleware('permission:prebid.manage')->name('admin.sites.prebid.price-buckets.store');
    Route::post('/admin/sites/{site}/prebid/connections/{gamConnection}/setup/preview', [PrebidController::class, 'previewSetup'])
        ->middleware('permission:prebid.gam_setup')->name('admin.sites.prebid.setup.preview');
    Route::post('/admin/sites/{site}/prebid/setup/{prebidSetupRun}/execute', [PrebidController::class, 'executeSetup'])
        ->middleware('permission:prebid.gam_setup')->name('admin.sites.prebid.setup.execute');
    Route::post('/admin/sites/{site}/prebid/setup/{prebidSetupRun}/resume', [PrebidController::class, 'resumeSetup'])
        ->middleware('permission:prebid.gam_setup')->name('admin.sites.prebid.setup.resume');
    Route::post('/admin/prebid/setup/bulk/preview', [PrebidController::class, 'bulkPreviewSetup'])
        ->middleware('permission:prebid.gam_setup')->name('admin.prebid.setup.bulk.preview');
    Route::post('/admin/prebid/setup/bulk/execute', [PrebidController::class, 'bulkExecuteSetup'])
        ->middleware('permission:prebid.gam_setup')->name('admin.prebid.setup.bulk.execute');
});
