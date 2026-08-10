<?php

namespace Tests\Feature;

use App\Enums\FinancialPeriodStatus;
use App\Enums\OrganizationType;
use App\Enums\PublisherPaymentProfileStatus;
use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Enums\ReportSourceCode;
use App\Enums\RevenueRuleScope;
use App\Enums\RoleName;
use App\Models\Advertiser;
use App\Models\Campaign;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\PublisherContract;
use App\Models\PublisherStatement;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\RevenueRule;
use App\Services\Reporting\FinancialPeriodService;
use App\Services\Reporting\PublisherPaymentProfileService;
use App\Services\Reporting\PublisherPaymentService;
use App\Services\Reporting\PublisherStatementService;
use App\Services\Reporting\ReportImportService;
use App\Services\Reporting\RevenueAdjustmentService;
use App\Services\Reporting\RevenueRuleService;
use App\Services\Reporting\UnifiedReportService;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\ReportingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class ReportingFinancialSystemTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_same_report_is_idempotent_and_multiple_sources_are_unified_with_horus_gam_identified(): void
    {
        [$admin, $publisher, $publisherUser, $site] = $this->reportingContext();
        $horus = $this->connection(ReportSourceCode::HorusGam, $admin->organization_id, 'Horus GAM');
        $native = $this->connection(ReportSourceCode::Mgid, $admin->organization_id, 'MGID');
        $date = now()->startOfMonth()->addDay()->toImmutable();
        $rows = [[
            'date' => $date->toDateString(), 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'ad_requests' => 1200, 'matched_requests' => 1000, 'impressions' => 900, 'clicks' => 18,
            'gross_revenue_minor' => 10000, 'currency' => 'USD', 'country' => 'EG', 'device' => 'MOBILE',
        ]];

        $imports = app(ReportImportService::class);
        $first = $imports->importRows($horus, $rows, ReportGranularity::Daily, ReportFinality::Finalized, $date, $date, $admin, 'horus-rpt-1', sourceTotals: ['impressions' => 900, 'gross_revenue_minor' => 10000]);
        $second = $imports->importRows($horus, $rows, ReportGranularity::Daily, ReportFinality::Finalized, $date, $date, $admin, 'horus-rpt-1', sourceTotals: ['impressions' => 900, 'gross_revenue_minor' => 10000]);
        $imports->importRows($native, [[
            'date' => $date->toDateString(), 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'impressions' => 100, 'clicks' => 2, 'gross_revenue_minor' => 2500, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $date, $date, $admin, 'mgid-rpt-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(2, DailyReport::withoutGlobalScopes()->count());
        $this->assertSame(0, $first->duplicate_count);
        $this->assertSame(1, $first->reconciliations()->count());

        $summary = app(UnifiedReportService::class)->adminSummary($date, $date, 'USD');
        $this->assertSame(1000, $summary['managed_impressions']);
        $this->assertSame(900, $summary['horus_gam_impressions']);
        $this->assertSame(12500, $summary['gross_revenue_minor']);
        $this->assertSame(8750, $summary['publisher_earnings_minor']);
        $this->assertSame(3750, $summary['horus_margin_minor']);

        $publisherSummary = app(UnifiedReportService::class)->publisherSummary($publisher, $date, $date);
        $this->assertSame(1000, $publisherSummary['impressions']);
        $this->assertSame(8750, $publisherSummary['revenue_minor']);
        $this->actingAs($publisherUser)->get(route('publisher.reporting.index'))->assertOk();
    }

    public function test_most_specific_rule_wins_every_change_versions_and_closed_history_is_immutable(): void
    {
        [$admin, $publisher, , $site] = $this->reportingContext();
        $service = app(RevenueRuleService::class);
        $effective = now()->startOfMonth()->toDateString();
        $global = RevenueRule::withoutGlobalScopes()->where('scope_type', RevenueRuleScope::Global->value)->firstOrFail();
        $publisherRule = $service->createRule([
            'name' => 'Publisher share', 'scope_type' => RevenueRuleScope::Publisher,
            'scope_id' => $publisher->id, 'effective_from' => $effective,
            'publisher_share_bp' => 7500, 'horus_share_bp' => 2500, 'mcm_partner_share_bp' => 0,
        ], $admin);
        $websiteRule = $service->createRule([
            'name' => 'Website share', 'scope_type' => RevenueRuleScope::Website,
            'scope_id' => $site->id, 'effective_from' => $effective,
            'publisher_share_bp' => 8000, 'horus_share_bp' => 2000, 'mcm_partner_share_bp' => 0,
        ], $admin);

        $resolved = $service->resolve($effective, ['publisher_id' => $publisher->id, 'site_id' => $site->id], 'USD');
        $this->assertSame($websiteRule->current_version_id, $resolved->id);
        $newVersion = $service->changeRule($websiteRule, [
            'effective_from' => now()->addDay()->toDateString(),
            'publisher_share_bp' => 7900, 'horus_share_bp' => 2100, 'mcm_partner_share_bp' => 0,
            'reason' => 'Approved commercial change',
        ], $admin);
        $this->assertSame(2, $newVersion->version);
        $this->assertSame(2, $websiteRule->versions()->count());
        $this->assertSame(1, $global->versions()->count());
        $this->assertSame(1, $publisherRule->versions()->count());

        $closedDate = now()->subMonthNoOverflow()->startOfMonth();
        FinancialPeriod::query()->create([
            'period_key' => $closedDate->format('Y-m'), 'starts_on' => $closedDate,
            'ends_on' => $closedDate->endOfMonth(), 'currency' => 'USD', 'status' => FinancialPeriodStatus::Closed,
        ]);
        $this->expectException(ValidationException::class);
        $service->changeRule($websiteRule, [
            'effective_from' => $closedDate->toDateString(),
            'publisher_share_bp' => 7800, 'horus_share_bp' => 2200, 'mcm_partner_share_bp' => 0,
        ], $admin);
    }

    public function test_financial_close_statements_adjustments_partial_payment_and_closed_period_protection(): void
    {
        Storage::fake('local');
        [$admin, $publisher, , $site] = $this->reportingContext();
        PublisherContract::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id,
            'contract_reference' => 'REPORTING-TEST', 'starts_at' => now()->subYear(), 'auto_renews' => false,
            'revenue_share_percent' => 70, 'payment_threshold' => 100, 'currency' => 'USD',
            'payment_terms' => 'Net 30', 'status' => 'ACTIVE', 'created_by' => $admin->id,
        ]);
        $connection = $this->connection(ReportSourceCode::HorusGam, $admin->organization_id, 'Horus GAM');
        $imports = app(ReportImportService::class);
        $periods = app(FinancialPeriodService::class);

        $firstDate = now()->subMonthsNoOverflow(2)->startOfMonth()->addDay()->toImmutable();
        $imports->importRows($connection, [[
            'date' => $firstDate->toDateString(), 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'impressions' => 1000, 'gross_revenue_minor' => 10000, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $firstDate, $firstDate, $admin, 'close-one');
        $periodOne = FinancialPeriod::query()->where('period_key', $firstDate->format('Y-m'))->firstOrFail();
        $periods->close($periodOne, $admin);
        $statementOne = PublisherStatement::withoutGlobalScopes()->where('publisher_id', $publisher->id)->where('financial_period_id', $periodOne->id)->firstOrFail();
        $this->assertSame('BELOW_THRESHOLD', $statementOne->status->value);
        $this->assertSame(7000, $statementOne->carry_forward_minor);
        $snapshot = $statementOne->snapshot_hash;

        $blocked = $imports->importRows($connection, [[
            'date' => $firstDate->toDateString(), 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'impressions' => 1, 'gross_revenue_minor' => 100, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $firstDate, $firstDate, $admin, 'closed-period-change');
        $this->assertSame(ReportImportStatus::BlockedClosedPeriod, $blocked->status);
        $this->assertSame($snapshot, $statementOne->fresh()->snapshot_hash);

        $secondDate = now()->subMonthNoOverflow()->startOfMonth()->addDay()->toImmutable();
        $periodTwo = app(FinancialPeriodService::class)->periodFor($secondDate, 'USD');
        $adjustment = app(RevenueAdjustmentService::class)->create([
            'financial_period_id' => $periodTwo->id, 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'effective_on' => $secondDate, 'type' => 'INVALID_TRAFFIC', 'amount_minor' => 1000,
            'currency' => 'USD', 'reason' => 'Approved IVT correction',
        ], $admin);
        app(RevenueAdjustmentService::class)->approve($adjustment, $admin);
        $this->assertDatabaseHas('audit_logs', ['event' => 'reporting.revenue_adjustment.created', 'auditable_id' => $adjustment->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'reporting.revenue_adjustment.approved', 'auditable_id' => $adjustment->id]);

        $imports->importRows($connection, [[
            'date' => $secondDate->toDateString(), 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'impressions' => 2000, 'gross_revenue_minor' => 20000, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $secondDate, $secondDate, $admin, 'close-two');
        $periods->close($periodTwo->fresh(), $admin);
        $statementTwo = PublisherStatement::withoutGlobalScopes()->where('publisher_id', $publisher->id)->where('financial_period_id', $periodTwo->id)->firstOrFail();
        $this->assertSame(7000, $statementTwo->opening_balance_minor);
        $this->assertSame(20300, $statementTwo->balance_due_minor);
        $this->assertSame('PENDING_INVOICE', $statementTwo->status->value);

        app(PublisherStatementService::class)->uploadInvoice(
            $statementTwo,
            UploadedFile::fake()->create('publisher-invoice.pdf', 100, 'application/pdf'),
            'PUB-INV-001',
            $admin,
        );
        $profile = app(PublisherPaymentProfileService::class)->save($publisher, [
            'beneficiary_name' => 'Publisher LLC', 'payment_method' => 'BANK_TRANSFER',
            'currency' => 'USD', 'country' => 'US', 'account_reference' => 'ACCOUNT-1234',
        ], $admin);
        app(PublisherPaymentProfileService::class)->review(
            $profile,
            PublisherPaymentProfileStatus::Verified,
            $admin,
        );
        $payments = app(PublisherPaymentService::class);
        $payment = $payments->create($statementTwo->fresh(), 10000, ['payment_method' => 'BANK_TRANSFER'], $admin);
        $payments->approve($payment, $admin);
        $payments->markPaid($payment->fresh(), 'HM-BANK-REF-001', $admin, 5000);
        $this->assertDatabaseHas('publisher_payments', ['id' => $payment->id, 'status' => 'PARTIALLY_PAID', 'amount_minor' => 10000, 'settled_amount_minor' => 5000, 'horus_payment_reference' => 'HM-BANK-REF-001']);
        $this->assertSame(15300, $statementTwo->fresh()->balance_due_minor);
        $this->assertSame('PARTIALLY_PAID', $statementTwo->fresh()->status->value);
    }

    public function test_advertiser_reports_are_unified_and_publisher_cannot_open_another_publishers_statement(): void
    {
        [$admin, $publisher, $publisherUser, $site] = $this->reportingContext();
        $advertiserOrg = $this->makeOrganization(OrganizationType::Advertiser, 'Advertiser');
        $advertiser = Advertiser::withoutGlobalScopes()->create([
            'organization_id' => $advertiserOrg->id, 'legal_name' => 'Advertiser LLC',
            'display_name' => 'Advertiser', 'status' => 'ACTIVE', 'billing_email' => 'billing@advertiser.test',
        ]);
        $campaign = Campaign::withoutGlobalScopes()->create([
            'public_key' => 'cmp_reporting_test', 'organization_id' => $advertiserOrg->id,
            'advertiser_id' => $advertiser->id, 'name' => 'Reporting campaign', 'objective' => 'Awareness',
            'pricing_model' => 'CPM', 'status' => 'ACTIVE', 'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(), 'currency' => 'USD', 'total_budget_minor' => 50000,
            'created_by' => $admin->id, 'updated_by' => $admin->id,
        ]);
        $connection = $this->connection(ReportSourceCode::HorusGam, $admin->organization_id, 'Horus GAM');
        $date = now()->startOfMonth()->addDay()->toImmutable();
        app(ReportImportService::class)->importRows($connection, [[
            'date' => $date, 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'advertiser_id' => $advertiser->id, 'campaign_id' => $campaign->id,
            'impressions' => 1000, 'clicks' => 20, 'spend_minor' => 5000,
            'gross_revenue_minor' => 5000, 'currency' => 'USD',
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $date, $date, $admin, 'advertiser-unified');
        $summary = app(UnifiedReportService::class)->advertiserSummary($advertiser, $date, $date);
        $this->assertSame(1000, $summary['impressions']);
        $this->assertSame(20, $summary['clicks']);
        $this->assertSame(200, $summary['ctr_bp']);
        $this->assertSame(5000, $summary['spend_minor']);
        $this->assertSame(45000, $summary['remaining_budget_minor']);

        $otherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Other Publisher');
        $otherUser = $this->makeUser($otherOrg, RoleName::PublisherAdmin);
        $otherPublisher = $this->makePublisherFor($otherUser, ['display_name' => 'Other Publisher']);
        $period = FinancialPeriod::query()->where('period_key', $date->format('Y-m'))->firstOrFail();
        $otherStatement = PublisherStatement::withoutGlobalScopes()->create([
            'organization_id' => $otherOrg->id, 'publisher_id' => $otherPublisher->id,
            'financial_period_id' => $period->id, 'statement_number' => 'HM-OTHER-STATEMENT',
            'status' => 'FINALIZED', 'currency' => 'USD', 'line_items' => [], 'snapshot' => [],
            'snapshot_hash' => hash('sha256', 'other'),
        ]);
        $this->actingAs($publisherUser)
            ->get('/publisher/reporting/statements/'.$otherStatement->id)
            ->assertNotFound();
    }

    private function reportingContext(): array
    {
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Publisher');
        $publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['display_name' => 'Publisher']);
        $site = $this->makeSiteFor($publisher, $publisherUser, ['display_name' => 'Publisher Site']);

        return [$admin, $publisher, $publisherUser, $site];
    }

    private function connection(ReportSourceCode $code, string $organizationId, string $name): ReportSourceConnection
    {
        $source = ReportSource::query()->where('code', $code->value)->firstOrFail();

        return ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $organizationId, 'report_source_id' => $source->id,
            'name' => $name, 'connection_type' => 'TEST', 'connection_id' => strtolower($code->value).'-test',
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
        ]);
    }
}
