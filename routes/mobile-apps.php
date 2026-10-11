<?php

use App\Http\Controllers\MobileAppController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'active', 'verified', 'admin.2fa'])->group(function () {
    foreach (['publisher', 'admin'] as $role) {
        Route::prefix($role.'/mobile-apps')->name($role.'.mobile-apps.')->middleware($role === 'admin' ? ['horus'] : [])->group(function () use ($role) {
            Route::get('/', [MobileAppController::class, 'index'])->middleware('permission:sites.view')->name('index');
            Route::get('/create', [MobileAppController::class, 'create'])->middleware('permission:sites.manage')->name('create');
            Route::post('/', [MobileAppController::class, 'store'])->middleware('permission:sites.manage')->name('store');
            Route::get('/{mobileApp}', [MobileAppController::class, 'show'])->middleware('permission:sites.view')->name('show');
            Route::get('/{mobileApp}/app-ads.txt', [MobileAppController::class, 'ads'])->middleware('permission:sites.view')->name('ads');
            Route::post('/{mobileApp}/verify', [MobileAppController::class, 'verify'])->middleware(['permission:sites.manage', 'throttle:10,1'])->name('verify');
            Route::post('/{mobileApp}/domain', [MobileAppController::class, 'domain'])->middleware('permission:sites.manage')->name('domain');
            Route::get('/{mobileApp}/reports', [MobileAppController::class, 'reports'])->middleware('permission:reporting.'.$role.'.view')->name('reports');
            if ($role === 'admin') {
                Route::post('/{mobileApp}/settings', [MobileAppController::class, 'settings'])->middleware('permission:sites.review')->name('settings');
                Route::post('/{mobileApp}/units', [MobileAppController::class, 'unit'])->middleware('permission:sites.serving.manage')->name('units');
                Route::post('/{mobileApp}/report-bindings', [MobileAppController::class, 'bind'])->middleware('permission:reporting.sources.manage')->name('bind');
                Route::post('/{mobileApp}/report-bindings/{binding}', [MobileAppController::class, 'toggle'])->middleware('permission:reporting.sources.manage')->name('toggle');
                Route::post('/{mobileApp}/share', [MobileAppController::class, 'share'])->middleware('permission:finance.revenue_rules.manage')->name('share');
            }
        });
    }
});
