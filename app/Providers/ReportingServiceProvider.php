<?php

namespace App\Providers;

use App\Http\Controllers\Admin\ReportingController as AdminReportingController;
use App\Http\Controllers\Advertiser\ReportingController as AdvertiserReportingController;
use App\Http\Controllers\Publisher\ReportingController as PublisherReportingController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware(['web', 'auth', 'active', 'verified', 'admin.2fa'])->group(function (): void {
            Route::get('/admin/reporting', [AdminReportingController::class, 'index'])
                ->middleware('permission:reporting.admin.view')->name('admin.reporting.index');
            Route::post('/admin/reporting/connections', [AdminReportingController::class, 'storeConnection'])
                ->middleware('permission:reporting.sources.manage')->name('admin.reporting.connections.store');
            Route::patch('/admin/reporting/connections/{reportSourceConnection}/status', [AdminReportingController::class, 'connectionStatus'])
                ->middleware('permission:reporting.sources.manage')->name('admin.reporting.connections.status');
            Route::post('/admin/reporting/connections/{reportSourceConnection}/csv', [AdminReportingController::class, 'importCsv'])
                ->middleware('permission:reporting.import')->name('admin.reporting.import.csv');
            Route::post('/admin/reporting/connections/{reportSourceConnection}/manual', [AdminReportingController::class, 'manualImport'])
                ->middleware('permission:reporting.import')->name('admin.reporting.import.manual');
            Route::post('/admin/reporting/imports/{reportImportJob}/retry', [AdminReportingController::class, 'retry'])
                ->middleware('permission:reporting.import')->name('admin.reporting.import.retry');
            Route::post('/admin/reporting/rules', [AdminReportingController::class, 'storeRule'])
                ->middleware('permission:reporting.revenue.manage')->name('admin.reporting.rules.store');
            Route::post('/admin/reporting/rules/{revenueRule}/versions', [AdminReportingController::class, 'versionRule'])
                ->middleware('permission:reporting.revenue.manage')->name('admin.reporting.rules.version');
            Route::post('/admin/reporting/adjustments', [AdminReportingController::class, 'storeAdjustment'])
                ->middleware('permission:reporting.adjustments.manage')->name('admin.reporting.adjustments.store');
            Route::post('/admin/reporting/adjustments/{revenueAdjustment}/approve', [AdminReportingController::class, 'approveAdjustment'])
                ->middleware('permission:reporting.adjustments.approve')->name('admin.reporting.adjustments.approve');
            Route::post('/admin/reporting/adjustments/{revenueAdjustment}/reject', [AdminReportingController::class, 'rejectAdjustment'])
                ->middleware('permission:reporting.adjustments.approve')->name('admin.reporting.adjustments.reject');
            Route::post('/admin/reporting/periods/{financialPeriod}/close', [AdminReportingController::class, 'closePeriod'])
                ->middleware('permission:reporting.periods.close')->name('admin.reporting.periods.close');
            Route::get('/admin/reporting/statements/{publisherStatement}', [AdminReportingController::class, 'statement'])
                ->middleware('permission:reporting.admin.view')->name('admin.reporting.statements.show');
            Route::get('/admin/reporting/statements/{publisherStatement}/csv', [AdminReportingController::class, 'statementCsv'])
                ->middleware('permission:reporting.admin.view')->name('admin.reporting.statements.csv');
            Route::post('/admin/reporting/statements/{publisherStatement}/payments', [AdminReportingController::class, 'storePayment'])
                ->middleware('permission:reporting.payments.manage')->name('admin.reporting.payments.store');
            Route::post('/admin/reporting/payments/{publisherPayment}/approve', [AdminReportingController::class, 'approvePayment'])
                ->middleware('permission:reporting.payments.manage')->name('admin.reporting.payments.approve');
            Route::post('/admin/reporting/payments/{publisherPayment}/paid', [AdminReportingController::class, 'markPaymentPaid'])
                ->middleware('permission:reporting.payments.manage')->name('admin.reporting.payments.paid');

            Route::get('/publisher/finance', [PublisherReportingController::class, 'overview'])
                ->middleware('permission:finance.publisher.view_own')->name('publisher.finance.overview');
            Route::get('/publisher/finance/statements', [PublisherReportingController::class, 'statements'])
                ->middleware('permission:finance.publisher.view_own')->name('publisher.finance.statements.index');
            Route::get('/publisher/finance/statements/{publisherStatement}', [PublisherReportingController::class, 'statement'])
                ->middleware('permission:finance.publisher.view_own')->name('publisher.finance.statements.show');
            Route::get('/publisher/finance/statements/{publisherStatement}/csv', [PublisherReportingController::class, 'csv'])
                ->middleware('permission:finance.publisher.view_own')->name('publisher.finance.statements.csv');
            Route::post('/publisher/finance/statements/{publisherStatement}/invoice', [PublisherReportingController::class, 'invoice'])
                ->middleware('permission:finance.publisher.invoice.upload')->name('publisher.finance.statements.invoice');
            Route::get('/publisher/finance/statements/{publisherStatement}/invoice', [PublisherReportingController::class, 'invoiceDownload'])
                ->middleware(['permission:finance.publisher.view_own', 'throttle:20,1'])->name('publisher.finance.statements.invoice.download');
            Route::get('/publisher/finance/payment-method', [PublisherReportingController::class, 'paymentMethod'])
                ->middleware('permission:finance.publisher.view_own')->name('publisher.finance.payment-method.edit');
            Route::put('/publisher/finance/payment-method', [PublisherReportingController::class, 'updatePaymentMethod'])
                ->middleware('permission:finance.publisher.payment_profile.manage')->name('publisher.finance.payment-method.update');
            Route::get('/publisher/finance/payouts', [PublisherReportingController::class, 'payouts'])
                ->middleware('permission:finance.publisher.view_own')->name('publisher.finance.payouts.index');

            Route::get('/publisher/reporting', [PublisherReportingController::class, 'index'])
                ->middleware('permission:reporting.publisher.view')->name('publisher.reporting.index');
            Route::get('/publisher/reporting/statements/{publisherStatement}', [PublisherReportingController::class, 'statement'])
                ->middleware('permission:reporting.publisher.view')->name('publisher.reporting.statements.show');
            Route::get('/publisher/reporting/statements/{publisherStatement}/csv', [PublisherReportingController::class, 'csv'])
                ->middleware('permission:reporting.publisher.view')->name('publisher.reporting.statements.csv');
            Route::post('/publisher/reporting/statements/{publisherStatement}/invoice', [PublisherReportingController::class, 'invoice'])
                ->middleware('permission:reporting.publisher.invoice')->name('publisher.reporting.statements.invoice');

            Route::get('/advertiser/reporting', [AdvertiserReportingController::class, 'index'])
                ->middleware('permission:reporting.advertiser.view')->name('advertiser.reporting.index');
        });
    }
}
