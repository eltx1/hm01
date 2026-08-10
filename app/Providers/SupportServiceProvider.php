<?php

namespace App\Providers;

use App\Http\Controllers\Support\AdminSupportTicketController;
use App\Http\Controllers\Support\CustomerSupportTicketController;
use App\Http\Controllers\Support\SupportAttachmentController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SupportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa'])->group(function (): void {
            Route::prefix('support')->name('support.')->group(function (): void {
                Route::get('/tickets', [CustomerSupportTicketController::class, 'index'])->middleware('permission:support.tickets.view_own')->name('tickets.index');
                Route::get('/tickets/create', [CustomerSupportTicketController::class, 'create'])->middleware('permission:support.tickets.create')->name('tickets.create');
                Route::post('/tickets', [CustomerSupportTicketController::class, 'store'])->middleware(['permission:support.tickets.create', 'throttle:support-create'])->name('tickets.store');
                Route::get('/tickets/{ticket}', [CustomerSupportTicketController::class, 'show'])->middleware('permission:support.tickets.view_own')->name('tickets.show');
                Route::post('/tickets/{ticket}/replies', [CustomerSupportTicketController::class, 'reply'])->middleware(['permission:support.tickets.reply_own', 'throttle:support-reply'])->name('tickets.reply');
                Route::patch('/tickets/{ticket}/close', [CustomerSupportTicketController::class, 'close'])->middleware(['permission:support.tickets.reply_own', 'throttle:support-status'])->name('tickets.close');
                Route::patch('/tickets/{ticket}/reopen', [CustomerSupportTicketController::class, 'reopen'])->middleware(['permission:support.tickets.reply_own', 'throttle:support-status'])->name('tickets.reopen');
            });

            Route::prefix('admin/support')->name('admin.support.')->middleware(['horus', 'permission:support.admin.view'])->group(function (): void {
                Route::get('/tickets', [AdminSupportTicketController::class, 'index'])->name('tickets.index');
                Route::get('/tickets/{ticket}', [AdminSupportTicketController::class, 'show'])->name('tickets.show');
                Route::post('/tickets/{ticket}/replies', [AdminSupportTicketController::class, 'reply'])->middleware(['permission:support.admin.reply', 'throttle:support-reply'])->name('tickets.reply');
                Route::post('/tickets/{ticket}/notes', [AdminSupportTicketController::class, 'note'])->middleware(['permission:support.internal_notes.view', 'throttle:support-reply'])->name('tickets.note');
                Route::patch('/tickets/{ticket}/assignment', [AdminSupportTicketController::class, 'assign'])->middleware(['permission:support.admin.assign', 'throttle:support-status'])->name('tickets.assign');
                Route::patch('/tickets/{ticket}/priority', [AdminSupportTicketController::class, 'priority'])->middleware(['permission:support.admin.manage', 'throttle:support-status'])->name('tickets.priority');
                Route::patch('/tickets/{ticket}/status', [AdminSupportTicketController::class, 'status'])->middleware(['permission:support.admin.manage', 'throttle:support-status'])->name('tickets.status');
            });

            Route::get('/support/tickets/{ticket}/attachments/{attachment}', SupportAttachmentController::class)
                ->middleware('throttle:support-attachment')->name('support.attachments.download');
        });
    }
}
