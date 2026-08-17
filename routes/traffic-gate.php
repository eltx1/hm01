<?php

use App\Http\Controllers\Admin\TrafficGateController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'admin.2fa', 'horus', 'permission:sites.serving.manage'])->group(function (): void {
    Route::post('/admin/sites/{site}/traffic-gate', [TrafficGateController::class, 'updateSite'])
        ->name('admin.sites.traffic-gate');
});
