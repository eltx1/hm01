<?php

use App\Http\Controllers\Admin\SiteQualityReviewController;
use Illuminate\Support\Facades\Route;

Route::post('/admin/sites/{site}/quality-review', [SiteQualityReviewController::class, 'store'])
    ->middleware([
        'auth',
        'active',
        'verified',
        'admin.2fa',
        'horus',
        'permission:sites.review',
        'throttle:sensitive',
    ])
    ->name('admin.sites.quality-review');
