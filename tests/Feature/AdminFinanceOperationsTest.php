<?php

namespace Tests\Feature;

use App\Enums\FinancialPeriodStatus;
use App\Enums\OrganizationType;
use App\Enums\PublisherInvoiceStatus;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\PublisherPaymentStatus;
use App\Enums\PublisherStatementStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportSourceCode;
use App\Enums\RevenueRuleScope;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\PublisherContract;
use App\Models\PublisherPayment;
use App\Models\PublisherPaymentSettlement;
use App\Models\PublisherStatement;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Services\Reporting\AdminFinanceService;
use App\Services\Reporting\FinancialPeriodService;
use App\Services\Reporting\PublisherPaymentProfileService;
use App\Services\Reporting\PublisherPaymentService;
use App\Services\Reporting\PublisherStatementService;
use App\Services\Reporting\ReconciliationService;
use App\Services\Reporting\ReportImportService;
use App\Services\Reporting\RevenueAdjustmentService;
use App\Services\Reporting\RevenueRuleService;
use App\Services\Reporting\UnifiedReportService;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\ReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class AdminFinanceOperationsTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_control_center_renders_real_currency_isolated_totals_and_masked_profiles(): void
    {
        [$creator, $approver, , $publisher, $publisherUser, $site, $otherPublisher, $otherUser] = $this->context();
        $this->verifiedProfile($publisher, $publisherUser, $approver, 'USD', '=PRIVATE-USD-1234');
        $this->verifiedProfile($otherPublisher, $otherUser, $approver, 'EUR', 'PRIVATE-EUR-5678');
        $usd = $this->statement($publisher, 'OPS-USD', 'USD', 12345, 1000, PublisherStatementStatus::Payable, PublisherInvoiceStatus::NotRequired);
        $eur = $this->statement($otherPublisher, 'OPS-EUR', 'EUR', 6789, 1000, PublisherStatementStatus::Payable, PublisherInvoiceStatus::NotRequired);
        $historicalCarry = $this->statement($publisher, 'OPS-USD-CARRY', 'USD', 5000, 10000, PublisherStatementStatus::BelowThreshold, PublisherInvoiceStatus::NotRequired);

        $overview = app(AdminFinanceService::class)->overview();
        $this->assertSame(12345, $overview['currency_totals']['USD']['outstanding_liability_minor']);
        $this->assertSame(6789, $overview['currency_totals']['EUR']['outstanding_liability_minor']);
        $this->assertSame(12345, $overview['currency_totals']['USD']['ready_for_payout_minor']);
        $this->assertSame(6789, $overview['currency_totals']['EUR']['ready_for_payout_minor']);
        $this->assertSame(0, $overview['currency_totals']['USD']['below_threshold_minor']);
        $reporting = app(UnifiedReportService::class)->adminSummary(currency: 'USD');
        $this->assertSame('USD', $reporting['currency']);
        $this->assertSame(12345, $reporting['outstanding_publisher_payments_minor']);
        $publisherReporting = app(UnifiedReportService::class)->publisherSummary($publisher, currency: 'USD');
        $this->assertSame(12345, $publisherReporting['payment_balance_minor']);

        $admin = $this->actingAs($creator)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $admin->get(route('admin.finance.overview'))->assertOk()->assertSee('USD')->assertSee('123.45')->assertSee('EUR')->assertSee('67.89');
        $this->get(route('admin.finance.periods.index'))->assertOk();
        $this->get(route('admin.finance.statements.index'))->assertOk()->assertSee($usd->statement_number)->assertSee($eur->statement_number);
        $this->get(route('admin.finance.payouts.index'))->assertOk();
        $profiles = $this->get(route('admin.finance.payment-profiles.index'));
        $profiles->assertOk()->assertSee('••••1234')->assertSee('••••5678')
            ->assertDontSee('=PRIVATE-USD-1234')->assertDontSee('PRIVATE-EUR-5678');
        $this->get(route('admin.finance.revenue-rules.index'))->assertOk();
        $this->get(route('admin.finance.adjustments.index'))->assertOk();
        $this->get(route('admin.finance.reconciliation.index'))->assertOk();

        $this->actingAs($publisherUser)->get(route('admin.finance.overview'))->assertForbidden();
        $this->assertSame($site->publisher_id, $publisher->id);
        $this->assertSame(5000, $historicalCarry->carry_forward_minor);
    }

    public function test_statement_eligibility_enforces_threshold_invoice_profile_and_currency(): void
    {
        [, $approver, , $publisher, $publisherUser] = $this->context();
        $this->verifiedProfile($publisher, $publisherUser, $approver, 'USD');
        $below = $this->statement($publisher, 'OPS-BELOW', 'USD', 900, 1000, PublisherStatementStatus::BelowThreshold, PublisherInvoiceStatus::NotRequired);
        $received = $this->statement($publisher, 'OPS-INVOICE', 'USD', 5000, 1000, PublisherStatementStatus::PendingInvoice, PublisherInvoiceStatus::Received);
        $euro = $this->statement($publisher, 'OPS-CURRENCY', 'EUR', 5000, 1000, PublisherStatementStatus::Payable, PublisherInvoiceStatus::NotRequired);
        $finance = app(AdminFinanceService::class);

        $this->assertFalse($finance->isEligible($below));
        $this->assertFalse($finance->isEligible($received));
        $this->assertFalse($finance->isEligible($euro));
        app(PublisherStatementService::class)->reviewInvoice($received, PublisherInvoiceStatus::Accepted, $approver);
        $this->assertTrue($finance->isEligible($received->fresh(['publisher.paymentProfile', 'payments'])));
    }

    public function test_payout_maker_checker_idempotency_partial_settlement_and_replay_safety(): void
    {
        [$creator, $approver, , $publisher, $publisherUser] = $this->context();
        $this->verifiedProfile($publisher, $publisherUser, $approver, 'USD');
        $statement = $this->statement($publisher, 'OPS-PAYOUT', 'USD', 20000, 1000, PublisherStatementStatus::Payable, PublisherInvoiceStatus::NotRequired, 2000);
        $payments = app(PublisherPaymentService::class);

        $payment = $payments->create($statement, 10000, ['idempotency_key' => 'payout-idempotency-1'], $creator);
        $replayedCreate = $payments->create($statement, 10000, ['idempotency_key' => 'payout-idempotency-1'], $creator);
        $this->assertSame($payment->id, $replayedCreate->id);
        $this->assertSame(1, PublisherPayment::withoutGlobalScopes()->count());

        try {
            $payments->approve($payment, $creator);
            $this->fail('A payout creator must not approve the same payout.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approval', $exception->errors());
        }
        $payments->approve($payment, $approver);
        $payments->schedule($payment->fresh(), now()->addDay(), $creator);
        $payments->beginExternalProcessing($payment->fresh(), $creator);
        $payments->recordSettlement($payment->fresh(), 'BANK-SETTLE-001', 4000, now(), $creator);
        $this->assertSame(PublisherPaymentStatus::PartiallyPaid, $payment->fresh()->status);
        $this->assertSame(16000, $statement->fresh()->balance_due_minor);
        $this->assertSame(4000, $statement->fresh()->paid_minor);

        $payments->recordSettlement($payment->fresh(), 'BANK-SETTLE-001', 4000, now(), $creator);
        $this->assertSame(1, PublisherPaymentSettlement::withoutGlobalScopes()->count());
        try {
            $payments->recordSettlement($payment->fresh(), 'BANK-SETTLE-001', 3000, now(), $creator);
            $this->fail('An immutable settlement reference must reject changed details.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement_reference', $exception->errors());
        }
        try {
            $payments->recordSettlement($payment->fresh(), 'BANK-SETTLE-001', 4000, now()->subDay(), $creator);
            $this->fail('An immutable settlement date must reject changed details.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement_reference', $exception->errors());
        }

        $payments->recordSettlement($payment->fresh(), 'BANK-SETTLE-002', 6000, now(), $creator);
        $this->assertSame(PublisherPaymentStatus::Paid, $payment->fresh()->status);
        $this->assertSame(10000, $payment->fresh()->settled_amount_minor);
        $this->assertSame(10000, $statement->fresh()->balance_due_minor);
        $this->assertSame(2, PublisherPaymentSettlement::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.payout.created', 'auditable_id' => $payment->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.payout.approved', 'auditable_id' => $payment->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.payout.partially_settled', 'auditable_id' => $payment->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.payout.settled', 'auditable_id' => $payment->id]);

        $second = $payments->create($statement->fresh(), 10000, ['idempotency_key' => 'payout-idempotency-2'], $creator);
        $payments->approve($second, $approver);
        try {
            $payments->recordSettlement($second->fresh(), 'BANK-SETTLE-001', 10000, now(), $creator);
            $this->fail('One external settlement reference must not be applied to two payouts.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('settlement_reference', $exception->errors());
        }
        $this->assertSame(2, PublisherPaymentSettlement::withoutGlobalScopes()->count());
        $this->assertSame(10000, $statement->fresh()->balance_due_minor);
    }

    public function test_permissions_hold_failure_and_rollback_preserve_earned_balance(): void
    {
        [$creator, $approver, , $publisher, $publisherUser] = $this->context();
        $support = $this->makeUser($creator->organization, RoleName::SupportAgent);
        $this->verifiedProfile($publisher, $publisherUser, $approver, 'USD');
        $statement = $this->statement($publisher, 'OPS-HOLD', 'USD', 20000, 1000, PublisherStatementStatus::Payable, PublisherInvoiceStatus::NotRequired);
        $payments = app(PublisherPaymentService::class);
        $payment = $payments->create($statement, 10000, ['idempotency_key' => 'hold-payout-1'], $creator);

        $this->actingAs($support)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.finance.payouts.approve', $payment), ['confirm_approval' => 1])->assertForbidden();
        $payments->approve($payment, $approver);
        $this->actingAs($support)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.finance.payouts.settle', $payment), [
                'settlement_reference' => 'FORBIDDEN', 'amount_minor' => 1, 'settled_on' => now()->toDateString(),
            ])->assertForbidden();

        $payments->recordSettlement($payment->fresh(), 'HOLD-PARTIAL-1', 2000, now(), $creator);
        $payments->hold($payment->fresh(), 'Publisher destination is under review.', $creator);
        $this->assertSame(18000, $statement->fresh()->balance_due_minor);
        try {
            $payments->create($statement->fresh(), 11000, ['idempotency_key' => 'blocked-by-hold'], $creator);
            $this->fail('Held remainder must stay reserved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_minor', $exception->errors());
        }
        $payments->releaseHold($payment->fresh(), $creator);
        $payments->fail($payment->fresh(), 'External processor rejected the destination.', $creator);
        $this->assertSame(18000, $statement->fresh()->balance_due_minor);
        $replacement = $payments->create($statement->fresh(), 18000, ['idempotency_key' => 'replacement-after-failure'], $creator);
        $this->assertSame(18000, $replacement->amount_minor);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.payout.held', 'auditable_id' => $payment->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.payout.failed', 'auditable_id' => $payment->id]);
        $this->assertStringNotContainsString(
            'External processor rejected the destination.',
            AuditLog::query()->where('auditable_id', $payment->id)->get()->toJson(),
        );

        $beforeSettlements = PublisherPaymentSettlement::withoutGlobalScopes()->count();
        try {
            $payments->recordSettlement($replacement, 'ROLLBACK-OVERPAY', 18001, now(), $creator);
            $this->fail('Over-settlement must roll back.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertSame($beforeSettlements, PublisherPaymentSettlement::withoutGlobalScopes()->count());
        $this->assertSame(18000, $statement->fresh()->balance_due_minor);

        $pendingStatement = $this->statement($publisher, 'OPS-PENDING-HOLD', 'USD', 3000, 1000, PublisherStatementStatus::Payable, PublisherInvoiceStatus::NotRequired);
        $pending = $payments->create($pendingStatement, 3000, ['idempotency_key' => 'pending-hold-release'], $creator);
        $payments->hold($pending, 'Pending payout requires temporary review.', $creator);
        $payments->releaseHold($pending->fresh(), $creator);
        $this->assertSame(PublisherPaymentStatus::Pending, $pending->fresh()->status);
    }

    public function test_selected_payout_creation_is_atomic_and_double_payout_is_prevented(): void
    {
        [$creator, $approver, , $publisher, $publisherUser] = $this->context();
        $this->verifiedProfile($publisher, $publisherUser, $approver, 'USD');
        $eligible = $this->statement($publisher, 'OPS-BULK-OK', 'USD', 9000, 1000, PublisherStatementStatus::Payable, PublisherInvoiceStatus::NotRequired);
        $blocked = $this->statement($publisher, 'OPS-BULK-BLOCKED', 'USD', 8000, 1000, PublisherStatementStatus::PendingInvoice, PublisherInvoiceStatus::Received);

        $this->actingAs($creator)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->post(route('admin.finance.payouts.create-selected'), [
                'statement_ids' => [$eligible->id, $blocked->id],
                'batch_key' => 'atomic-batch-1',
            ])->assertSessionHasErrors('statement_ids');
        $this->assertSame(0, PublisherPayment::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::query()->where('event', 'finance.payout.created')->count());

        $payment = app(PublisherPaymentService::class)->create($eligible, 9000, ['idempotency_key' => 'full-reservation'], $creator);
        try {
            app(PublisherPaymentService::class)->create($eligible, 1, ['idempotency_key' => 'duplicate-reservation'], $creator);
            $this->fail('A second payout cannot reserve the same statement balance.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('amount_minor', $exception->errors());
        }
        $this->assertSame(1, PublisherPayment::withoutGlobalScopes()->where('publisher_statement_id', $eligible->id)->count());
        $this->assertSame('full-reservation', $payment->idempotency_key);
    }

    public function test_period_close_readiness_adjustment_separation_override_and_closed_mutation_block(): void
    {
        [$creator, $approver, $super, $publisher, , $site] = $this->context();
        $date = now()->subMonthNoOverflow()->startOfMonth()->addDay()->toImmutable();
        $connection = $this->connection($creator->organization_id, 'USD', 'period-readiness');
        $imports = app(ReportImportService::class);
        $imports->importRows($connection, [[
            'date' => $date, 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'impressions' => 100, 'gross_revenue_minor' => 10000, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $date, $date, $creator, 'ready-period', sourceTotals: [
            'impressions' => 100, 'gross_revenue_minor' => 10000,
        ]);
        $period = FinancialPeriod::query()->where('period_key', $date->format('Y-m'))->where('currency', 'USD')->firstOrFail();
        $periods = app(FinancialPeriodService::class);
        $this->assertTrue($periods->readiness($period)['ready']);

        $adjustment = app(RevenueAdjustmentService::class)->create([
            'financial_period_id' => $period->id, 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'effective_on' => $date, 'type' => 'INVALID_TRAFFIC', 'amount_minor' => 1000,
            'currency' => 'USD', 'reason' => 'Confirmed invalid traffic correction',
        ], $creator);
        $this->assertContains('PENDING_ADJUSTMENTS', collect($periods->readiness($period)['blockers'])->pluck('code'));
        try {
            app(RevenueAdjustmentService::class)->approve($adjustment, $creator);
            $this->fail('Adjustment creator must not approve the adjustment.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('approval', $exception->errors());
        }
        app(RevenueAdjustmentService::class)->approve($adjustment, $approver, 'Evidence reviewed independently.');
        $this->assertTrue($periods->readiness($period)['ready']);
        $periods->close($period, $creator);
        $this->assertSame(FinancialPeriodStatus::Closed, $period->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.financial_period.closed', 'auditable_id' => $period->id]);

        $blockedImport = $imports->importRows($connection, [[
            'date' => $date, 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'impressions' => 1, 'gross_revenue_minor' => 1, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $date, $date, $creator, 'after-close');
        $this->assertSame('BLOCKED_CLOSED_PERIOD', $blockedImport->status->value);

        $emptyDate = now()->subMonthsNoOverflow(2)->startOfMonth();
        $empty = FinancialPeriod::query()->create([
            'period_key' => $emptyDate->format('Y-m'), 'starts_on' => $emptyDate,
            'ends_on' => $emptyDate->endOfMonth(), 'currency' => 'EUR', 'status' => FinancialPeriodStatus::Open,
        ]);
        try {
            $periods->close($empty, $creator, 'Emergency finance override reason.');
            $this->fail('Normal Finance Admin must not override readiness blockers.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $periods->close($empty, $super, 'Emergency close approved after documented source review.');
        $this->assertSame(FinancialPeriodStatus::Closed, $empty->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.financial_period.close_overridden', 'auditable_id' => $empty->id]);
    }

    public function test_reconciliation_remediation_resolves_blocker_without_mutating_finance(): void
    {
        [$creator, , , $publisher, , $site] = $this->context();
        $date = now()->subMonthNoOverflow()->startOfMonth()->addDay()->toImmutable();
        $connection = $this->connection($creator->organization_id, 'GBP', 'reconciliation-warning');
        $job = app(ReportImportService::class)->importRows($connection, [[
            'date' => $date, 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'impressions' => 100, 'gross_revenue_minor' => 10000, 'currency' => 'GBP',
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $date, $date, $creator, 'warning-import', sourceTotals: [
            'impressions' => 120, 'gross_revenue_minor' => 12000,
        ]);
        $run = $job->reconciliations()->firstOrFail();
        $period = FinancialPeriod::query()->where('period_key', $date->format('Y-m'))->where('currency', 'GBP')->firstOrFail();
        $this->assertSame(ReconciliationStatus::Warning, $run->status);
        $this->assertContains('RECONCILIATION_ISSUES', collect(app(FinancialPeriodService::class)->readiness($period)['blockers'])->pluck('code'));
        $before = DailyReport::withoutGlobalScopes()->where('financial_period_id', $period->id)->pluck('source_row_hash', 'id')->all();

        app(ReconciliationService::class)->recordRemediation($run, 'Source variance accepted after provider statement review.', $creator);
        $this->assertSame(ReconciliationStatus::Resolved, $run->fresh()->status);
        $this->assertSame($before, DailyReport::withoutGlobalScopes()->where('financial_period_id', $period->id)->pluck('source_row_hash', 'id')->all());
        $this->assertTrue(app(FinancialPeriodService::class)->readiness($period)['ready']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'finance.reconciliation.remediation_recorded', 'auditable_id' => $run->id]);
    }

    public function test_revenue_rule_versions_are_locked_to_effective_history_and_export_is_formula_safe(): void
    {
        [$creator, $approver, , $publisher, $publisherUser] = $this->context();
        $service = app(RevenueRuleService::class);
        $rule = $service->createRule([
            'name' => 'Publisher operations share', 'scope_type' => RevenueRuleScope::Publisher,
            'scope_id' => $publisher->id, 'effective_from' => now()->toDateString(),
            'publisher_share_bp' => 7200, 'horus_share_bp' => 2800, 'mcm_partner_share_bp' => 0,
            'reason' => 'Approved commercial baseline',
        ], $creator);
        $firstId = $rule->current_version_id;
        $version = $service->changeRule($rule, [
            'effective_from' => now()->addDay()->toDateString(), 'publisher_share_bp' => 7100,
            'horus_share_bp' => 2900, 'mcm_partner_share_bp' => 0,
            'reason' => 'Future commercial change approved',
        ], $creator);
        $this->assertSame(2, $version->version);
        $this->assertSame(2, $rule->versions()->count());
        $this->assertDatabaseHas('revenue_rule_versions', ['id' => $firstId, 'publisher_share_bp' => 7200]);

        $this->verifiedProfile($publisher, $publisherUser, $approver, 'USD');
        $publisher->update(['display_name' => '=CMD|formula']);
        $statement = $this->statement($publisher->fresh(), 'OPS-CSV', 'USD', 5000, 1000, PublisherStatementStatus::Payable, PublisherInvoiceStatus::NotRequired);
        app(PublisherPaymentService::class)->create($statement, 5000, ['idempotency_key' => 'csv-export'], $creator);
        $response = $this->actingAs($creator)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.finance.payouts.csv'));
        $response->assertOk();
        ob_start();
        $response->sendContent();
        $csv = (string) ob_get_clean();
        $this->assertStringContainsString("'=CMD|formula", $csv);
        $this->assertStringNotContainsString("\n=CMD|formula", $csv);
    }

    private function context(): array
    {
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $creator = $this->makeUser($horus, RoleName::FinanceAdmin, ['name' => 'Finance Creator']);
        $approver = $this->makeUser($horus, RoleName::FinanceAdmin, ['name' => 'Finance Approver']);
        $super = $this->makeUser($horus, RoleName::SuperAdmin, ['name' => 'Super Finance']);

        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher One');
        $publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['display_name' => 'Publisher One']);
        $site = $this->makeSiteFor($publisher, $publisherUser, ['display_name' => 'Publisher One Site']);
        $otherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher Two');
        $otherUser = $this->makeUser($otherOrganization, RoleName::PublisherAdmin);
        $otherPublisher = $this->makePublisherFor($otherUser, ['display_name' => 'Publisher Two']);

        foreach ([$publisher, $otherPublisher] as $target) {
            PublisherContract::withoutGlobalScopes()->create([
                'organization_id' => $target->organization_id, 'publisher_id' => $target->id,
                'contract_reference' => 'OPS-'.$target->id, 'starts_at' => now()->subYear(),
                'auto_renews' => false, 'revenue_share_percent' => '70.00',
                'payment_threshold' => '10.00', 'currency' => 'USD', 'payment_terms' => 'NET_30',
                'status' => 'ACTIVE', 'created_by' => $creator->id,
            ]);
        }

        return [$creator, $approver, $super, $publisher, $publisherUser, $site, $otherPublisher, $otherUser];
    }

    private function verifiedProfile($publisher, $publisherUser, $reviewer, string $currency, string $account = 'ACCOUNT-1234')
    {
        $profile = app(PublisherPaymentProfileService::class)->save($publisher, [
            'beneficiary_name' => $publisher->display_name, 'payment_method' => 'BANK_TRANSFER',
            'currency' => $currency, 'country' => 'US', 'account_reference' => $account,
        ], $publisherUser);

        return app(PublisherPaymentProfileService::class)->review(
            $profile,
            PublisherPaymentProfileStatus::Verified,
            $reviewer,
        );
    }

    private function statement(
        $publisher,
        string $number,
        string $currency,
        int $balance,
        int $threshold,
        PublisherStatementStatus $status,
        PublisherInvoiceStatus $invoiceStatus,
        int $opening = 0,
    ): PublisherStatement {
        $existingStatements = PublisherStatement::withoutGlobalScopes()
            ->where('publisher_id', $publisher->id)
            ->where('currency', $currency)
            ->count();
        $month = now()->subMonthsNoOverflow($existingStatements + 1);
        $period = FinancialPeriod::query()->firstOrCreate([
            'organization_id' => null, 'period_key' => $month->format('Y-m'), 'currency' => $currency,
        ], [
            'starts_on' => $month->copy()->startOfMonth(), 'ends_on' => $month->copy()->endOfMonth(),
            'status' => FinancialPeriodStatus::Closed,
        ]);

        return PublisherStatement::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'publisher_id' => $publisher->id,
            'financial_period_id' => $period->id,
            'statement_number' => $number,
            'status' => $status,
            'currency' => $currency,
            'opening_balance_minor' => $opening,
            'publisher_earnings_minor' => $balance - $opening,
            'balance_due_minor' => $balance,
            'carry_forward_minor' => $status === PublisherStatementStatus::BelowThreshold ? $balance : 0,
            'payment_threshold_minor' => $threshold,
            'line_items' => [],
            'snapshot' => ['test' => $number],
            'snapshot_hash' => hash('sha256', $number),
            'finalized_at' => now(),
            'publisher_invoice_status' => $invoiceStatus,
            'publisher_invoice_number' => in_array($invoiceStatus, [PublisherInvoiceStatus::Received, PublisherInvoiceStatus::Accepted], true) ? 'INV-'.$number : null,
            'publisher_invoice_path' => in_array($invoiceStatus, [PublisherInvoiceStatus::Received, PublisherInvoiceStatus::Accepted], true) ? 'publisher-invoices/'.$number.'.pdf' : null,
        ]);
    }

    private function connection(string $organizationId, string $currency, string $key): ReportSourceConnection
    {
        $source = ReportSource::query()->where('code', ReportSourceCode::HorusGam->value)->firstOrFail();

        return ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $organizationId,
            'report_source_id' => $source->id,
            'name' => 'Horus GAM '.$currency,
            'connection_type' => 'TEST',
            'connection_id' => $key,
            'currency' => $currency,
            'timezone' => 'UTC',
            'status' => 'ACTIVE',
            'is_enabled' => true,
        ]);
    }
}
