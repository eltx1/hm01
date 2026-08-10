<?php

namespace App\Providers;

use App\Http\Controllers\Publisher\MonetizationController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class MonetizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa'])->group(function (): void {
            Route::get('/publisher/monetization', [MonetizationController::class, 'index'])
                ->middleware('permission:sites.view')
                ->name('publisher.monetization.index');
        });
    }
}
