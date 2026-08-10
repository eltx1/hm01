<?php

namespace App\Providers;

use App\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class NotificationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa', 'permission:notifications.view_own'])
            ->prefix('notifications')->name('notifications.')->group(function (): void {
                Route::get('/', [NotificationController::class, 'index'])->name('index');
                Route::post('/read-all', [NotificationController::class, 'readAll'])->name('read-all');
                Route::get('/preferences/manage', [NotificationController::class, 'preferences'])->name('preferences');
                Route::put('/preferences/manage', [NotificationController::class, 'updatePreferences'])->name('preferences.update');
                Route::patch('/{notification}/read', [NotificationController::class, 'read'])->name('read');
                Route::patch('/{notification}/unread', [NotificationController::class, 'unread'])->name('unread');
            });
    }
}
