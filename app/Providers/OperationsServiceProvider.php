<?php

namespace App\Providers;

use App\Http\Controllers\Admin\OperationsController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class OperationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa', 'permission:operations.view'])
            ->prefix('admin/operations')->name('admin.operations.')->group(function (): void {
                Route::get('/', [OperationsController::class, 'index'])->name('index');
                Route::post('/controls', [OperationsController::class, 'control'])->middleware(['permission:operations.manage', 'throttle:operations'])->name('controls.update');
                Route::post('/sites/{site}', [OperationsController::class, 'site'])->middleware(['permission:operations.manage', 'throttle:operations'])->name('sites.update');
                Route::post('/placements/{placement}', [OperationsController::class, 'placement'])->middleware(['permission:operations.manage', 'throttle:operations'])->name('placements.update');
                Route::post('/gam-connections/{gamConnection}', [OperationsController::class, 'gamConnection'])->middleware(['permission:operations.manage', 'throttle:operations'])->name('gam-connections.update');
                Route::post('/failed-jobs/{uuid}/retry', [OperationsController::class, 'retryFailedJob'])->middleware(['permission:operations.manage', 'throttle:operations'])->name('failed-jobs.retry');
                Route::delete('/failed-jobs/{uuid}', [OperationsController::class, 'forgetFailedJob'])->middleware(['permission:operations.manage', 'throttle:operations'])->name('failed-jobs.forget');
                Route::post('/loader-releases/{loaderRelease}/rollback', [OperationsController::class, 'rollbackLoader'])->middleware(['permission:operations.manage', 'throttle:operations'])->name('loaders.rollback');
                Route::post('/config-versions/{configVersion}/rollback', [OperationsController::class, 'rollbackConfig'])->middleware(['permission:operations.manage', 'throttle:operations'])->name('configs.rollback');
            });
    }
}
