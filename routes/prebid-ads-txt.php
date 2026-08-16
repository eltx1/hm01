<?php

use App\Http\Controllers\Admin\BidderAdsTxtController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'admin.2fa', 'horus'])->prefix('admin')->group(function (): void {
    Route::get('/sites/{site}/prebid/ads-txt', [BidderAdsTxtController::class, 'index'])
        ->middleware('permission:supply_chain.ads_txt.view')->name('admin.prebid.ads-txt.index');
    Route::put('/sites/{site}/prebid/ads-txt/accounts/{bidderAccount}/requirement', [BidderAdsTxtController::class, 'requirement'])
        ->middleware('permission:supply_chain.ads_txt.manage')->name('admin.prebid.ads-txt.requirement');
    Route::post('/sites/{site}/prebid/ads-txt/accounts/{bidderAccount}/records', [BidderAdsTxtController::class, 'store'])
        ->middleware('permission:supply_chain.ads_txt.manage')->name('admin.prebid.ads-txt.records.store');
    Route::put('/sites/{site}/prebid/ads-txt/records/{bidderAdsTxtRecord}', [BidderAdsTxtController::class, 'update'])
        ->middleware('permission:supply_chain.ads_txt.manage')->name('admin.prebid.ads-txt.records.update');
    Route::post('/sites/{site}/prebid/ads-txt/records/{bidderAdsTxtRecord}/disable', [BidderAdsTxtController::class, 'disable'])
        ->middleware('permission:supply_chain.ads_txt.manage')->name('admin.prebid.ads-txt.records.disable');
    Route::post('/sites/{site}/prebid/ads-txt/records/{bidderAdsTxtRecord}/review', [BidderAdsTxtController::class, 'review'])
        ->middleware('permission:supply_chain.sellers.review')->name('admin.prebid.ads-txt.records.review');
    Route::post('/sites/{site}/prebid/ads-txt/records/{bidderAdsTxtRecord}/verify-remote', [BidderAdsTxtController::class, 'verifyRemote'])
        ->middleware('permission:supply_chain.ads_txt.verify')->name('admin.prebid.ads-txt.records.verify-remote');
});
