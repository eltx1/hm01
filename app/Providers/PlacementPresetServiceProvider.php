<?php

namespace App\Providers;

use App\Http\Controllers\Admin\SimplePlacementController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PlacementPresetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa', 'horus', 'permission:inventory.manage'])
            ->post('/admin/sites/{site}/inventory/placements/simple', [SimplePlacementController::class, 'store'])
            ->name('admin.sites.inventory.placements.simple');
    }
}
