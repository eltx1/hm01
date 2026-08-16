<?php

namespace App\Providers;

use App\Http\Controllers\Admin\AdsTxtComplianceController as AdminAdsTxtComplianceController;
use App\Http\Controllers\Admin\SellerComplianceController;
use App\Http\Controllers\Admin\SupplyChainControlCenterController;
use App\Http\Controllers\Publisher\AdsTxtComplianceController as PublisherAdsTxtComplianceController;
use App\Services\Compliance\SupplyChainControlCenterService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ComplianceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa'])->group(function (): void {
            Route::get('/admin/compliance/supply-chain', [SupplyChainControlCenterController::class, 'overview'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.view'])->name('admin.compliance.supply-chain.overview');
            Route::get('/admin/compliance/supply-chain/websites/{site}', [SupplyChainControlCenterController::class, 'site'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.view'])->name('admin.compliance.supply-chain.site');
            Route::get('/admin/compliance/supply-chain/bidder-authorizations/{bidderAccount}', [SupplyChainControlCenterController::class, 'bidder'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.view'])->name('admin.compliance.supply-chain.bidder');
            Route::post('/admin/compliance/supply-chain/sellers-json/verify', [SupplyChainControlCenterController::class, 'verifySellersJson'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.verify', 'throttle:ads-txt-verification'])->name('admin.compliance.supply-chain.sellers-json.verify');
            Route::get('/admin/compliance/supply-chain/{section}', [SupplyChainControlCenterController::class, 'section'])
                ->whereIn('section', array_values(array_diff(SupplyChainControlCenterService::SECTIONS, ['overview'])))
                ->middleware(['horus', 'permission:supply_chain.ads_txt.view'])->name('admin.compliance.supply-chain.section');

            Route::get('/admin/compliance/ads-txt', [AdminAdsTxtComplianceController::class, 'index'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.view'])->name('admin.compliance.ads-txt.index');
            Route::get('/admin/compliance/ads-txt/sites/{site}', [AdminAdsTxtComplianceController::class, 'show'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.view'])->name('admin.compliance.ads-txt.show');
            Route::get('/admin/compliance/ads-txt/sites/{site}/download', [AdminAdsTxtComplianceController::class, 'download'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.view', 'throttle:30,1'])->name('admin.compliance.ads-txt.download');
            Route::post('/admin/compliance/ads-txt/sites/{site}/verify', [AdminAdsTxtComplianceController::class, 'verify'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.verify', 'throttle:ads-txt-verification'])->name('admin.compliance.ads-txt.verify');
            Route::post('/admin/compliance/ads-txt/records', [AdminAdsTxtComplianceController::class, 'storeRecord'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.manage'])->name('admin.compliance.ads-txt.records.store');
            Route::put('/admin/compliance/ads-txt/records/{record}', [AdminAdsTxtComplianceController::class, 'updateRecord'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.manage'])->name('admin.compliance.ads-txt.records.update');
            Route::patch('/admin/compliance/ads-txt/records/{record}/disable', [AdminAdsTxtComplianceController::class, 'disableRecord'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.manage'])->name('admin.compliance.ads-txt.records.disable');
            Route::post('/admin/compliance/ads-txt/bulk-assign', [AdminAdsTxtComplianceController::class, 'bulkAssign'])
                ->middleware(['horus', 'permission:supply_chain.ads_txt.manage'])->name('admin.compliance.ads-txt.bulk-assign');

            Route::get('/admin/compliance/sellers', [SellerComplianceController::class, 'index'])
                ->middleware(['horus', 'permission:supply_chain.sellers.view'])->name('admin.compliance.sellers.index');
            Route::get('/admin/compliance/sellers/artifact', [SellerComplianceController::class, 'artifact'])
                ->middleware(['horus', 'permission:supply_chain.sellers.view', 'throttle:30,1'])->name('admin.compliance.sellers.artifact');
            Route::post('/admin/compliance/sellers', [SellerComplianceController::class, 'store'])
                ->middleware(['horus', 'permission:supply_chain.sellers.manage'])->name('admin.compliance.sellers.store');
            Route::get('/admin/compliance/sellers/{seller}', [SellerComplianceController::class, 'show'])
                ->middleware(['horus', 'permission:supply_chain.sellers.view'])->name('admin.compliance.sellers.show');
            Route::put('/admin/compliance/sellers/{seller}', [SellerComplianceController::class, 'update'])
                ->middleware(['horus', 'permission:supply_chain.sellers.manage'])->name('admin.compliance.sellers.update');
            Route::post('/admin/compliance/sellers/{seller}/review', [SellerComplianceController::class, 'review'])
                ->middleware(['horus', 'permission:supply_chain.sellers.review'])->name('admin.compliance.sellers.review');
            Route::patch('/admin/compliance/sellers/{seller}/activate', [SellerComplianceController::class, 'activate'])
                ->middleware(['horus', 'permission:supply_chain.sellers.manage'])->name('admin.compliance.sellers.activate');
            Route::patch('/admin/compliance/sellers/{seller}/deactivate', [SellerComplianceController::class, 'deactivate'])
                ->middleware(['horus', 'permission:supply_chain.sellers.manage'])->name('admin.compliance.sellers.deactivate');

            Route::get('/publisher/ads-txt', [PublisherAdsTxtComplianceController::class, 'index'])
                ->middleware('permission:publisher.ads_txt.view')->name('publisher.ads-txt.index');
            Route::get('/publisher/ads-txt/sites/{site}/download', [PublisherAdsTxtComplianceController::class, 'download'])
                ->middleware(['permission:publisher.ads_txt.view', 'throttle:30,1'])->name('publisher.ads-txt.download');
            Route::post('/publisher/ads-txt/sites/{site}/verify', [PublisherAdsTxtComplianceController::class, 'verify'])
                ->middleware(['permission:publisher.ads_txt.verify_own', 'throttle:ads-txt-verification'])->name('publisher.ads-txt.verify');
        });
    }
}
