<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AccountStatusController;
use App\Http\Controllers\Admin\AdvertiserController;
use App\Http\Controllers\Admin\ContactController;
use App\Http\Controllers\Admin\ImpersonationController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Controllers\Admin\PublisherController;
use App\Http\Controllers\Admin\RolePermissionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\BrandingController;
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
    Route::get('/two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor/challenge', [TwoFactorController::class, 'verifyChallenge'])->middleware('throttle:6,1')->name('two-factor.verify');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::get('/verify-email', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])->middleware('signed')->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])->middleware('throttle:6,1')->name('verification.send');
    Route::get('/two-factor/setup', [TwoFactorController::class, 'setup'])->name('two-factor.setup');
    Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:6,1')->name('two-factor.confirm');
    Route::get('/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
    Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'regenerate'])->name('two-factor.recovery-codes.regenerate');
    Route::delete('/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');

    Route::middleware(['verified', 'admin.2fa'])->group(function (): void {
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
        Route::resource('/admin/organizations', OrganizationController::class)->except('show')->middleware('permission:organizations.manage')->names('admin.organizations');
        Route::resource('/admin/publishers', PublisherController::class)->except('show')->middleware('permission:publishers.manage')->names('admin.publishers');
        Route::resource('/admin/advertisers', AdvertiserController::class)->except('show')->middleware('permission:advertisers.manage')->names('admin.advertisers');
        Route::post('/admin/publishers/{publisher}/contacts', [ContactController::class, 'storePublisher'])->middleware('permission:publishers.manage')->name('admin.publishers.contacts.store');
        Route::put('/admin/publishers/{publisher}/contacts/{contact}', [ContactController::class, 'updatePublisher'])->middleware('permission:publishers.manage')->name('admin.publishers.contacts.update');
        Route::delete('/admin/publishers/{publisher}/contacts/{contact}', [ContactController::class, 'destroyPublisher'])->middleware('permission:publishers.manage')->name('admin.publishers.contacts.destroy');
        Route::post('/admin/advertisers/{advertiser}/contacts', [ContactController::class, 'storeAdvertiser'])->middleware('permission:advertisers.manage')->name('admin.advertisers.contacts.store');
        Route::put('/admin/advertisers/{advertiser}/contacts/{contact}', [ContactController::class, 'updateAdvertiser'])->middleware('permission:advertisers.manage')->name('admin.advertisers.contacts.update');
        Route::delete('/admin/advertisers/{advertiser}/contacts/{contact}', [ContactController::class, 'destroyAdvertiser'])->middleware('permission:advertisers.manage')->name('admin.advertisers.contacts.destroy');
        Route::get('/admin/access', [RolePermissionController::class, 'index'])->middleware('permission:roles.view')->name('admin.roles.index');
        Route::post('/admin/users/{user}/roles', [RolePermissionController::class, 'assignRole'])->middleware('permission:roles.manage')->name('admin.users.roles.assign');
        Route::put('/admin/roles/{role}/permissions', [RolePermissionController::class, 'syncPermissions'])->middleware('permission:roles.manage')->name('admin.roles.permissions.sync');
        Route::post('/admin/impersonate/{user}', [ImpersonationController::class, 'start'])->middleware('permission:users.impersonate')->name('admin.impersonate.start');
        Route::delete('/admin/impersonate', [ImpersonationController::class, 'stop'])->name('admin.impersonate.stop');
        Route::get('/account/branding', [BrandingController::class, 'edit'])->middleware('permission:branding.manage')->name('account.branding.edit');
        Route::put('/account/branding', [BrandingController::class, 'update'])->middleware('permission:branding.manage')->name('account.branding.update');
        Route::get('/admin/organizations/{organization}/branding', [BrandingController::class, 'edit'])->middleware('permission:organizations.manage')->name('admin.organizations.branding.edit');
        Route::put('/admin/organizations/{organization}/branding', [BrandingController::class, 'update'])->middleware('permission:organizations.manage')->name('admin.organizations.branding.update');
    });
});
