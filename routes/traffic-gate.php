<?php

use App\Http\Controllers\Admin\TrafficGateController;
use App\Http\Controllers\Admin\TrafficQualityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'admin.2fa', 'horus'])->group(function (): void {
    Route::middleware('permission:traffic_gate.manage')->group(function (): void {
        Route::get('/admin/operations/traffic-quality', [TrafficQualityController::class, 'index'])
            ->name('admin.operations.traffic-quality');
        Route::post('/admin/operations/traffic-quality/master', [TrafficQualityController::class, 'updateMaster'])
            ->name('admin.operations.traffic-quality.master');
        Route::post('/admin/operations/traffic-quality/policy', [TrafficQualityController::class, 'updatePolicy'])
            ->name('admin.operations.traffic-quality.policy');
        Route::post('/admin/operations/traffic-quality/advanced', [TrafficQualityController::class, 'updateAdvanced'])
            ->name('admin.operations.traffic-quality.advanced');
        Route::post('/admin/operations/traffic-quality/sitekey/candidate', [TrafficQualityController::class, 'stageSitekey'])
            ->name('admin.operations.traffic-quality.sitekey.candidate');
        Route::post('/admin/operations/traffic-quality/sitekey/test-result', [TrafficQualityController::class, 'recordClientTest'])
            ->name('admin.operations.traffic-quality.sitekey.test-result');
        Route::post('/admin/operations/traffic-quality/sitekey/activate', [TrafficQualityController::class, 'activateSitekey'])
            ->name('admin.operations.traffic-quality.sitekey.activate');
        Route::post('/admin/operations/traffic-quality/sites/bulk-inherit', [TrafficQualityController::class, 'bulkInherit'])
            ->name('admin.operations.traffic-quality.sites.bulk-inherit');
        Route::post('/admin/sites/{site}/traffic-gate', [TrafficGateController::class, 'updateSite'])
            ->name('admin.sites.traffic-gate');
    });

    Route::middleware('permission:traffic_gate.emergency_disable')->group(function (): void {
        Route::post('/admin/operations/traffic-quality/emergency-disable', [TrafficQualityController::class, 'emergencyDisable'])
            ->name('admin.operations.traffic-quality.emergency-disable');
        Route::post('/admin/operations/traffic-quality/clear-emergency', [TrafficQualityController::class, 'clearEmergency'])
            ->name('admin.operations.traffic-quality.clear-emergency');
    });
});
