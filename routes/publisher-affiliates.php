<?php

use App\Http\Controllers\Admin\PublisherAffiliateController as AdminPublisherAffiliateController;
use App\Http\Controllers\Publisher\AffiliateController as PublisherAffiliateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'dashboard.access', 'permission:finance.publisher.view_own'])
    ->get('/publisher/affiliate', [PublisherAffiliateController::class, 'index'])
    ->name('publisher.affiliate.index');

Route::middleware(['auth', 'active', 'admin.2fa', 'horus'])
    ->prefix('admin')
    ->group(function (): void {
        Route::get('/publisher-affiliates', [AdminPublisherAffiliateController::class, 'index'])
            ->middleware('permission:publishers.view')
            ->name('admin.publisher-affiliates.index');
        Route::put('/publisher-affiliates/settings', [AdminPublisherAffiliateController::class, 'updateSettings'])
            ->middleware('permission:publishers.manage')
            ->name('admin.publisher-affiliates.settings.update');
        Route::put('/publisher-affiliates/publishers/{publisher}', [AdminPublisherAffiliateController::class, 'updatePublisher'])
            ->middleware('permission:publishers.manage')
            ->name('admin.publisher-affiliates.publishers.update');
    });
