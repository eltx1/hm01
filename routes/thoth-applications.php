<?php

use App\Http\Controllers\Admin\PublisherApplicationController;
use Illuminate\Support\Facades\Route;

Route::post('/admin/publishers/applications/{application}/thoth-review', [PublisherApplicationController::class, 'thothReview'])
    ->middleware([
        'auth',
        'active',
        'verified',
        'admin.2fa',
        'horus',
        'permission:publisher_quality.ai.run',
        'throttle:sensitive',
    ])
    ->name('admin.publisher-applications.thoth-review');
