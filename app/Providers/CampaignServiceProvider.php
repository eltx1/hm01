<?php

namespace App\Providers;

use App\Http\Controllers\Admin\DirectCampaignController as AdminCampaignController;
use App\Http\Controllers\Advertiser\CampaignController as AdvertiserCampaignController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CampaignServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa'])->group(function (): void {
            Route::get('/advertiser/campaigns', [AdvertiserCampaignController::class, 'index'])->middleware('permission:campaigns.view')->name('advertiser.campaigns.index');
            Route::get('/advertiser/campaigns/create', [AdvertiserCampaignController::class, 'create'])->middleware('permission:campaigns.manage')->name('advertiser.campaigns.create');
            Route::post('/advertiser/campaigns', [AdvertiserCampaignController::class, 'store'])->middleware('permission:campaigns.manage')->name('advertiser.campaigns.store');
            Route::get('/advertiser/campaigns/{campaign}', [AdvertiserCampaignController::class, 'show'])->middleware('permission:campaigns.view')->name('advertiser.campaigns.show');
            Route::get('/advertiser/campaigns/{campaign}/edit', [AdvertiserCampaignController::class, 'edit'])->middleware('permission:campaigns.manage')->name('advertiser.campaigns.edit');
            Route::put('/advertiser/campaigns/{campaign}', [AdvertiserCampaignController::class, 'update'])->middleware('permission:campaigns.manage')->name('advertiser.campaigns.update');
            Route::post('/advertiser/campaigns/{campaign}/submit', [AdvertiserCampaignController::class, 'submit'])->middleware('permission:campaigns.manage')->name('advertiser.campaigns.submit');
            Route::post('/advertiser/campaigns/{campaign}/creatives', [AdvertiserCampaignController::class, 'creative'])->middleware('permission:creatives.manage')->name('advertiser.campaigns.creatives.store');
            Route::post('/advertiser/campaigns/{campaign}/creatives/{campaignCreative}/replace', [AdvertiserCampaignController::class, 'replaceCreative'])->middleware('permission:creatives.manage')->name('advertiser.campaigns.creatives.replace');
            Route::post('/advertiser/billing-profile', [AdvertiserCampaignController::class, 'billingProfile'])->middleware('permission:billing.advertiser.manage')->name('advertiser.billing-profile.store');
            Route::get('/advertiser/invoices/{advertiserInvoice}/download', [AdvertiserCampaignController::class, 'invoice'])->middleware('permission:billing.advertiser.view')->name('advertiser.invoices.download');

            Route::get('/admin/campaigns', [AdminCampaignController::class, 'index'])->middleware('permission:campaigns.review')->name('admin.campaigns.index');
            Route::get('/admin/campaigns/{campaign}', [AdminCampaignController::class, 'show'])->middleware('permission:campaigns.review')->name('admin.campaigns.show');
            Route::post('/admin/advertisers/{advertiser}/campaign-users', [AdminCampaignController::class, 'linkAdvertiserUser'])->middleware('permission:advertisers.manage')->name('admin.advertisers.campaign-users');
            Route::post('/admin/advertisers/{advertiser}/campaign-review', [AdminCampaignController::class, 'reviewAdvertiser'])->middleware('permission:advertisers.manage')->name('admin.advertisers.campaign-review');
            Route::post('/admin/campaigns/{campaign}/creatives/{campaignCreative}/review', [AdminCampaignController::class, 'reviewCreative'])->middleware('permission:creatives.review')->name('admin.campaigns.creatives.review');
            Route::post('/admin/campaigns/{campaign}/approve', [AdminCampaignController::class, 'approve'])->middleware('permission:campaigns.review')->name('admin.campaigns.approve');
            Route::post('/admin/campaigns/{campaign}/reject', [AdminCampaignController::class, 'reject'])->middleware('permission:campaigns.review')->name('admin.campaigns.reject');
            Route::post('/admin/campaigns/{campaign}/schedule', [AdminCampaignController::class, 'schedule'])->middleware('permission:campaigns.deploy')->name('admin.campaigns.schedule');
            Route::post('/admin/campaigns/{campaign}/pause', [AdminCampaignController::class, 'pause'])->middleware('permission:campaigns.deploy')->name('admin.campaigns.pause');
            Route::post('/admin/campaigns/{campaign}/resume', [AdminCampaignController::class, 'resume'])->middleware('permission:campaigns.deploy')->name('admin.campaigns.resume');
            Route::post('/admin/campaigns/{campaign}/complete', [AdminCampaignController::class, 'complete'])->middleware('permission:campaigns.deploy')->name('admin.campaigns.complete');
            Route::post('/admin/campaigns/{campaign}/bonus', [AdminCampaignController::class, 'bonus'])->middleware('permission:campaigns.manage')->name('admin.campaigns.bonus');
            Route::put('/admin/campaigns/{campaign}/targeting', [AdminCampaignController::class, 'targeting'])->middleware('permission:campaigns.manage')->name('admin.campaigns.targeting');
            Route::post('/admin/campaigns/{campaign}/gam/dry-run', [AdminCampaignController::class, 'dryRun'])->middleware('permission:campaigns.deploy')->name('admin.campaigns.gam.dry-run');
            Route::post('/admin/campaigns/{campaign}/gam/deploy', [AdminCampaignController::class, 'deploy'])->middleware('permission:campaigns.deploy')->name('admin.campaigns.gam.deploy');
            Route::post('/admin/campaigns/{campaign}/gam/instances/{campaignNetworkInstance}/retry', [AdminCampaignController::class, 'retry'])->middleware('permission:campaigns.deploy')->name('admin.campaigns.gam.retry');
            Route::post('/admin/campaigns/{campaign}/gam/synchronize', [AdminCampaignController::class, 'synchronize'])->middleware('permission:campaigns.reports')->name('admin.campaigns.gam.synchronize');
            Route::post('/admin/campaigns/{campaign}/gam/reconcile', [AdminCampaignController::class, 'reconcile'])->middleware('permission:campaigns.deploy')->name('admin.campaigns.gam.reconcile');
        });
    }
}
