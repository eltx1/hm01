<?php

namespace App\Providers;

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\OperationsController;
use App\Models\StaticDeliveryBatch;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::model('staticDeliveryBatch', StaticDeliveryBatch::class);

        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa', 'horus'])->group(function (): void {
            Route::get('/admin/operations', [OperationsController::class, 'index'])
                ->middleware('permission:operations.view')
                ->name('admin.operations.index');
            Route::post('/admin/operations/controls', [OperationsController::class, 'control'])
                ->middleware(['permission:operations.manage', 'throttle:sensitive'])
                ->name('admin.operations.controls');
            Route::delete('/admin/operations/jobs/{uuid}', [OperationsController::class, 'forgetFailedJob'])
                ->middleware(['permission:operations.manage', 'throttle:sensitive'])
                ->name('admin.operations.jobs.forget');
            Route::post('/admin/operations/loader/rollback', [OperationsController::class, 'rollbackLoader'])
                ->middleware(['permission:operations.manage', 'throttle:sensitive'])
                ->name('admin.operations.loader.rollback');
            Route::post('/admin/operations/static-delivery/{staticDeliveryBatch}/retry', [OperationsController::class, 'retryStaticDelivery'])
                ->middleware(['permission:operations.manage', 'throttle:sensitive'])
                ->name('admin.operations.static-delivery.retry');

            Route::get('/admin/security/audit-log', [AuditLogController::class, 'index'])
                ->middleware('permission:audit.view')
                ->name('admin.audit.index');
        });
    }
}
