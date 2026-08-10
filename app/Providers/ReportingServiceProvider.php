<?php

namespace App\Providers;

use App\Http\Controllers\Admin\FinanceOperationsController;
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
            Route::middleware('horus')->group(function (): void {
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
                    ->middleware('permission:finance.revenue_rules.manage')->name('admin.reporting.rules.store');
                Route::post('/admin/reporting/rules/{revenueRule}/versions', [AdminReportingController::class, 'versionRule'])
                    ->middleware('permission:finance.revenue_rules.manage')->name('admin.reporting.rules.version');
                Route::post('/admin/reporting/adjustments', [AdminReportingController::class, 'storeAdjustment'])
                    ->middleware('permission:finance.adjustments.create')->name('admin.reporting.adjustments.store');
                Route::post('/admin/reporting/adjustments/{revenueAdjustment}/approve', [AdminReportingController::class, 'approveAdjustment'])
                    ->middleware('permission:finance.adjustments.approve')->name('admin.reporting.adjustments.approve');
                Route::post('/admin/reporting/adjustments/{revenueAdjustment}/reject', [AdminReportingController::class, 'rejectAdjustment'])
                    ->middleware('permission:finance.adjustments.approve')->name('admin.reporting.adjustments.reject');
                Route::post('/admin/reporting/periods/{financialPeriod}/close', [AdminReportingController::class, 'closePeriod'])
                    ->middleware('permission:finance.periods.close')->name('admin.reporting.periods.close');
                Route::get('/admin/reporting/statements/{publisherStatement}', [AdminReportingController::class, 'statement'])
                    ->middleware('permission:reporting.admin.view')->name('admin.reporting.statements.show');
                Route::get('/admin/reporting/statements/{publisherStatement}/csv', [AdminReportingController::class, 'statementCsv'])
                    ->middleware('permission:reporting.admin.view')->name('admin.reporting.statements.csv');
                Route::post('/admin/reporting/statements/{publisherStatement}/payments', [AdminReportingController::class, 'storePayment'])
                    ->middleware('permission:finance.payments.create')->name('admin.reporting.payments.store');
                Route::post('/admin/reporting/payments/{publisherPayment}/approve', [AdminReportingController::class, 'approvePayment'])
                    ->middleware('permission:finance.payments.approve')->name('admin.reporting.payments.approve');
                Route::post('/admin/reporting/payments/{publisherPayment}/paid', [AdminReportingController::class, 'markPaymentPaid'])
                    ->middleware('permission:finance.payments.settle')->name('admin.reporting.payments.paid');

                Route::prefix('/admin/finance')->name('admin.finance.')->group(function (): void {
                    Route::get('/', [FinanceOperationsController::class, 'overview'])->middleware('permission:finance.operations.view')->name('overview');
                    Route::get('/periods', [FinanceOperationsController::class, 'periods'])->middleware('permission:finance.operations.view')->name('periods.index');
                    Route::post('/periods/{financialPeriod}/close', [FinanceOperationsController::class, 'closePeriod'])->middleware('permission:finance.periods.close')->name('periods.close');
                    Route::get('/statements', [FinanceOperationsController::class, 'statements'])->middleware('permission:finance.publisher.view')->name('statements.index');
                    Route::get('/statements/{publisherStatement}', [FinanceOperationsController::class, 'statement'])->middleware('permission:finance.publisher.view')->name('statements.show');
                    Route::get('/statements/{publisherStatement}/csv', [FinanceOperationsController::class, 'statementCsv'])->middleware('permission:finance.publisher.view')->name('statements.csv');
                    Route::post('/statements/{publisherStatement}/invoice-review', [FinanceOperationsController::class, 'reviewInvoice'])->middleware('permission:finance.statements.review')->name('statements.invoice-review');
                    Route::post('/statements/{publisherStatement}/payouts', [FinanceOperationsController::class, 'createPayout'])->middleware('permission:finance.payments.create')->name('payouts.store');
                    Route::post('/payouts/create-selected', [FinanceOperationsController::class, 'createSelectedPayouts'])->middleware('permission:finance.payments.create')->name('payouts.create-selected');
                    Route::get('/payouts', [FinanceOperationsController::class, 'payouts'])->middleware('permission:finance.payments.view')->name('payouts.index');
                    Route::get('/payouts.csv', [FinanceOperationsController::class, 'payoutsCsv'])->middleware('permission:finance.payments.view')->name('payouts.csv');
                    Route::post('/payouts/{publisherPayment}/approve', [FinanceOperationsController::class, 'approvePayout'])->middleware('permission:finance.payments.approve')->name('payouts.approve');
                    Route::post('/payouts/{publisherPayment}/schedule', [FinanceOperationsController::class, 'schedulePayout'])->middleware('permission:finance.payments.settle')->name('payouts.schedule');
                    Route::post('/payouts/{publisherPayment}/process', [FinanceOperationsController::class, 'processPayout'])->middleware('permission:finance.payments.settle')->name('payouts.process');
                    Route::post('/payouts/{publisherPayment}/settlements', [FinanceOperationsController::class, 'settlePayout'])->middleware('permission:finance.payments.settle')->name('payouts.settle');
                    Route::post('/payouts/{publisherPayment}/hold', [FinanceOperationsController::class, 'holdPayout'])->middleware('permission:finance.payments.settle')->name('payouts.hold');
                    Route::post('/payouts/{publisherPayment}/release', [FinanceOperationsController::class, 'releasePayout'])->middleware('permission:finance.payments.settle')->name('payouts.release');
                    Route::post('/payouts/{publisherPayment}/fail', [FinanceOperationsController::class, 'failPayout'])->middleware('permission:finance.payments.settle')->name('payouts.fail');
                    Route::get('/payment-profiles', [FinanceOperationsController::class, 'paymentProfiles'])->middleware('permission:finance.payment_profiles.verify')->name('payment-profiles.index');
                    Route::post('/payment-profiles/{publisherPaymentProfile}/review', [FinanceOperationsController::class, 'reviewPaymentProfile'])->middleware('permission:finance.payment_profiles.verify')->name('payment-profiles.review');
                    Route::get('/revenue-rules', [FinanceOperationsController::class, 'revenueRules'])->middleware('permission:finance.operations.view')->name('revenue-rules.index');
                    Route::post('/revenue-rules', [FinanceOperationsController::class, 'storeRevenueRule'])->middleware('permission:finance.revenue_rules.manage')->name('revenue-rules.store');
                    Route::post('/revenue-rules/{revenueRule}/versions', [FinanceOperationsController::class, 'versionRevenueRule'])->middleware('permission:finance.revenue_rules.manage')->name('revenue-rules.version');
                    Route::get('/adjustments', [FinanceOperationsController::class, 'adjustments'])->middleware('permission:finance.operations.view')->name('adjustments.index');
                    Route::post('/adjustments', [FinanceOperationsController::class, 'storeAdjustment'])->middleware('permission:finance.adjustments.create')->name('adjustments.store');
                    Route::post('/adjustments/{revenueAdjustment}/approve', [FinanceOperationsController::class, 'approveAdjustment'])->middleware('permission:finance.adjustments.approve')->name('adjustments.approve');
                    Route::post('/adjustments/{revenueAdjustment}/reject', [FinanceOperationsController::class, 'rejectAdjustment'])->middleware('permission:finance.adjustments.approve')->name('adjustments.reject');
                    Route::get('/reconciliation', [FinanceOperationsController::class, 'reconciliation'])->middleware('permission:finance.operations.view')->name('reconciliation.index');
                    Route::post('/reconciliation/{reconciliationRun}/remediation', [FinanceOperationsController::class, 'remediateReconciliation'])->middleware('permission:finance.reconciliation.manage')->name('reconciliation.remediate');
                    Route::post('/reconciliation/imports/{reportImportJob}/retry', [FinanceOperationsController::class, 'retryImport'])->middleware('permission:finance.reconciliation.manage')->name('reconciliation.retry');
                });
            });

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
