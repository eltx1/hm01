<?php

namespace App\Providers;

use App\Http\Controllers\Admin\AdsTxtComplianceController as AdminAdsTxtComplianceController;
use App\Http\Controllers\Publisher\AdsTxtComplianceController as PublisherAdsTxtComplianceController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class ComplianceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa'])->group(function (): void {
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

            Route::get('/publisher/ads-txt', [PublisherAdsTxtComplianceController::class, 'index'])
                ->middleware('permission:publisher.ads_txt.view')->name('publisher.ads-txt.index');
            Route::get('/publisher/ads-txt/sites/{site}/download', [PublisherAdsTxtComplianceController::class, 'download'])
                ->middleware(['permission:publisher.ads_txt.view', 'throttle:30,1'])->name('publisher.ads-txt.download');
            Route::post('/publisher/ads-txt/sites/{site}/verify', [PublisherAdsTxtComplianceController::class, 'verify'])
                ->middleware(['permission:publisher.ads_txt.verify_own', 'throttle:ads-txt-verification'])->name('publisher.ads-txt.verify');
        });
    }
}
