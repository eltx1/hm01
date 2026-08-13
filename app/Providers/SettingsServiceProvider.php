<?php

namespace App\Providers;

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\ThothSettingsController;
use App\Services\Settings\GlobalSettingsService;
use App\Services\Settings\TypedSettingsRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class SettingsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TypedSettingsRegistry::class);
        $this->app->singleton(GlobalSettingsService::class);
    }

    public function boot(): void
    {
        $this->app->make(GlobalSettingsService::class)->applyRuntimeOverrides();

        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa', 'horus'])->prefix('admin/settings')->group(function (): void {
            Route::get('/', [SettingsController::class, 'index'])
                ->middleware('permission:settings.view')
                ->name('admin.settings.index');
            Route::get('/thoth/quality-advisor', [ThothSettingsController::class, 'index'])
                ->middleware('permission:thoth.settings.view')->name('admin.thoth.settings');
            Route::put('/thoth/quality-advisor', [ThothSettingsController::class, 'update'])
                ->middleware(['permission:thoth.settings.manage', 'throttle:sensitive'])->name('admin.thoth.settings.update');
            Route::put('/thoth/providers/{provider}', [ThothSettingsController::class, 'updateConnection'])
                ->middleware(['permission:thoth.settings.manage', 'throttle:sensitive'])->name('admin.thoth.connections.update');
            Route::put('/thoth/providers/{provider}/credential', [ThothSettingsController::class, 'updateCredential'])
                ->middleware(['permission:thoth.credentials.manage', 'throttle:sensitive'])->name('admin.thoth.credentials.update');
            Route::delete('/thoth/providers/{provider}/credential', [ThothSettingsController::class, 'removeCredential'])
                ->middleware(['permission:thoth.credentials.manage', 'throttle:sensitive'])->name('admin.thoth.credentials.destroy');
            Route::post('/thoth/providers/{provider}/test', [ThothSettingsController::class, 'test'])
                ->middleware(['permission:thoth.settings.manage', 'throttle:sensitive'])->name('admin.thoth.connections.test');
            Route::put('/{key}', [SettingsController::class, 'update'])->where('key', '[A-Za-z0-9._-]+')
                ->middleware(['permission:settings.manage', 'throttle:sensitive'])
                ->name('admin.settings.update');
            Route::delete('/{key}', [SettingsController::class, 'reset'])->where('key', '[A-Za-z0-9._-]+')
                ->middleware(['permission:settings.manage', 'throttle:sensitive'])
                ->name('admin.settings.reset');
        });
    }
}
