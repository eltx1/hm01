<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AccountStatusController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'email'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'update'])->name('password.update');
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.accept.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');

    Route::middleware('verified')->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/account/publishers/{publisher}', [AccountController::class, 'publisher'])->name('account.publisher');
        Route::get('/account/advertisers/{advertiser}', [AccountController::class, 'advertiser'])->name('account.advertiser');

        Route::get('/admin/invitations/create', [InvitationController::class, 'create'])->middleware('permission:users.invite')->name('admin.invitations.create');
        Route::post('/admin/invitations', [InvitationController::class, 'store'])->middleware('permission:users.invite')->name('admin.invitations.store');
        Route::patch('/admin/users/{user}/activate', [UserController::class, 'activate'])->middleware('permission:users.manage')->name('admin.users.activate');
        Route::patch('/admin/users/{user}/suspend', [UserController::class, 'suspend'])->middleware('permission:users.manage')->name('admin.users.suspend');
        Route::delete('/admin/users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.manage')->name('admin.users.destroy');
        Route::patch('/admin/organizations/{organization}/status', [AccountStatusController::class, 'organization'])->middleware('permission:organizations.manage')->name('admin.organizations.status');
        Route::patch('/admin/publishers/{publisher}/status', [AccountStatusController::class, 'publisher'])->middleware('permission:publishers.manage')->name('admin.publishers.status');
        Route::patch('/admin/advertisers/{advertiser}/status', [AccountStatusController::class, 'advertiser'])->middleware('permission:advertisers.manage')->name('admin.advertisers.status');
        Route::get('/admin/access', [RolePermissionController::class, 'index'])->middleware('permission:roles.view')->name('admin.roles.index');
        Route::post('/admin/users/{user}/roles', [RolePermissionController::class, 'assignRole'])->middleware('permission:roles.manage')->name('admin.users.roles.assign');
        Route::put('/admin/roles/{role}/permissions', [RolePermissionController::class, 'syncPermissions'])->middleware('permission:roles.manage')->name('admin.roles.permissions.sync');
        Route::post('/admin/impersonate/{user}', [ImpersonationController::class, 'start'])->middleware('permission:users.impersonate')->name('admin.impersonate.start');
        Route::delete('/admin/impersonate', [ImpersonationController::class, 'stop'])->name('admin.impersonate.stop');
    });
});
