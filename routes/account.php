<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\ProfileController;
use App\Http\Controllers\Account\SecurityController;
use App\Http\Controllers\Account\SessionController;
use App\Http\Controllers\Account\TwoFactorController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
    Route::get('/', AccountController::class)->name('index');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->middleware('throttle:20,1')
        ->name('profile.update');

    Route::get('/security', [SecurityController::class, 'show'])->name('security');
    Route::put('/security/password', [SecurityController::class, 'updatePassword'])
        ->middleware('throttle:sensitive')
        ->name('security.password.update');

    Route::post('/security/two-factor/setup', [TwoFactorController::class, 'begin'])
        ->middleware('throttle:sensitive')
        ->name('security.two-factor.begin');
    Route::get('/security/two-factor/setup', [TwoFactorController::class, 'setup'])
        ->name('security.two-factor.setup');
    Route::post('/security/two-factor/confirm', [TwoFactorController::class, 'confirm'])
        ->middleware('throttle:sensitive')
        ->name('security.two-factor.confirm');
    Route::get('/security/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
        ->name('security.two-factor.recovery-codes');
    Route::post('/security/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])
        ->middleware('throttle:sensitive')
        ->name('security.two-factor.recovery-codes.regenerate');
    Route::delete('/security/two-factor', [TwoFactorController::class, 'disable'])
        ->middleware('throttle:sensitive')
        ->name('security.two-factor.disable');

    Route::delete('/security/sessions/{reference}', [SessionController::class, 'revoke'])
        ->where('reference', '[a-f0-9]{64}')
        ->middleware('throttle:sensitive')
        ->name('security.sessions.revoke');
    Route::delete('/security/sessions', [SessionController::class, 'revokeOthers'])
        ->middleware('throttle:sensitive')
        ->name('security.sessions.revoke-others');
});
