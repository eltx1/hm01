<?php

use App\Http\Controllers\Admin\PlatformAdsTxtController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'admin.2fa', 'horus'])->prefix('admin/compliance/ads-txt')->group(function (): void {
    Route::get('/master', [PlatformAdsTxtController::class, 'index'])
        ->middleware('permission:supply_chain.ads_txt.view')->name('admin.compliance.ads-txt.master.index');
    Route::post('/master', [PlatformAdsTxtController::class, 'store'])
        ->middleware('permission:supply_chain.ads_txt.manage')->name('admin.compliance.ads-txt.master.store');
    Route::post('/master/import', [PlatformAdsTxtController::class, 'import'])
        ->middleware(['permission:supply_chain.ads_txt.manage', 'permission:supply_chain.sellers.review'])
        ->name('admin.compliance.ads-txt.master.import');
    Route::put('/master/{platformAdsTxtRecord}', [PlatformAdsTxtController::class, 'update'])
        ->middleware('permission:supply_chain.ads_txt.manage')->name('admin.compliance.ads-txt.master.update');
    Route::post('/master/{platformAdsTxtRecord}/review', [PlatformAdsTxtController::class, 'review'])
        ->middleware('permission:supply_chain.sellers.review')->name('admin.compliance.ads-txt.master.review');
    Route::post('/master/{platformAdsTxtRecord}/enable', [PlatformAdsTxtController::class, 'enable'])
        ->middleware(['permission:supply_chain.ads_txt.manage', 'permission:supply_chain.sellers.review'])->name('admin.compliance.ads-txt.master.enable');
    Route::post('/master/{platformAdsTxtRecord}/disable', [PlatformAdsTxtController::class, 'disable'])
        ->middleware('permission:supply_chain.ads_txt.manage')->name('admin.compliance.ads-txt.master.disable');
    Route::post('/master/{platformAdsTxtRecord}/verify', [PlatformAdsTxtController::class, 'verify'])
        ->middleware('permission:supply_chain.ads_txt.verify')->name('admin.compliance.ads-txt.master.verify');
});
