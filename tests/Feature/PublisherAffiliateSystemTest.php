<?php

namespace Tests\Feature;

use App\Enums\FinancialPeriodStatus;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\FinancialPeriod;
use App\Models\GlobalSetting;
use App\Models\PublisherAffiliateCommission;
use App\Models\PublisherStatement;
use App\Services\Reporting\PublisherAffiliateService;
use App\Services\Reporting\PublisherStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class PublisherAffiliateSystemTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_default_commission_is_five_percent_of_publisher_earnings_not_gross_or_other_affiliate_income(): void
    {
        [, $referrer, $referred] = $this->affiliateContext();
        $service = app(PublisherAffiliateService::class);
        $period = $this->period();

        $this->assertSame(500, $service->defaultRateBp());
        $this->assertSame(350, $service->calculateCommission(7000, 500));

        $source = PublisherStatement::withoutGlobalScopes()->create([
            'organization_id' => $referred->organization_id,
            'publisher_id' => $referred->id,
            'financial_period_id' => $period->id,
            'statement_number' => 'HM-AFF-SOURCE-001',
            'status' => 'FINALIZED',
            'currency' => 'USD',
            'gross_revenue_minor' => 20000,
            'net_revenue_minor' => 10000,
            'publisher_earnings_minor' => 7000,
            'affiliate_earnings_minor' => 9000,
            'balance_due_minor' => 16000,
            'line_items' => [],
            'snapshot' => [],
            'snapshot_hash' => hash('sha256', 'affiliate-source-001'),
            'finalized_at' => now(),
        ]);

        $summary = $service->reconcilePeriod($period, null);

        $this->assertSame(350, $summary[$referrer->id]['total_minor']);
        $this->assertDatabaseHas('publisher_affiliate_commissions', [
            'source_publisher_statement_id' => $source->id,
            'referrer_publisher_id' => $referrer->id,
            'referred_publisher_id' => $referred->id,
            'basis_minor' => 7000,
            'commission_rate_bp' => 500,
            'commission_minor' => 350,
        ]);
    }

    public function test_reconciliation_is_idempotent_and_affiliate_earnings_are_added_to_referrer_statement_without_reducing_referred_statement(): void
    {
        [, $referrer, $referred] = $this->affiliateContext();
        $service = app(PublisherAffiliateService::class);
        $period = $this->period();

        $source = PublisherStatement::withoutGlobalScopes()->create([
            'organization_id' => $referred->organization_id,
            'publisher_id' => $referred->id,
            'financial_period_id' => $period->id,
            'statement_number' => 'HM-AFF-SOURCE-002',
            'status' => 'FINALIZED',
            'currency' => 'USD',
            'gross_revenue_minor' => 10000,
            'net_revenue_minor' => 9000,
            'publisher_earnings_minor' => 7000,
            'balance_due_minor' => 7000,
            'line_items' => [],
            'snapshot' => [],
            'snapshot_hash' => hash('sha256', 'affiliate-source-002'),
            'finalized_at' => now(),
        ]);

        $first = $service->reconcilePeriod($period, null);
        $second = $service->reconcilePeriod($period, null);

        $this->assertSame(350, $first[$referrer->id]['total_minor']);
        $this->assertSame(350, $second[$referrer->id]['total_minor']);
        $this->assertSame(1, PublisherAffiliateCommission::query()->where('financial_period_id', $period->id)->count());
        $this->assertSame(7000, $source->fresh()->balance_due_minor);
        $this->assertSame(7000, $source->fresh()->publisher_earnings_minor);

        $referrerStatement = app(PublisherStatementService::class)->generate(
            $period,
            $referrer,
            null,
            $second[$referrer->id]['total_minor'],
            $second[$referrer->id]['line_items'],
        );

        $this->assertSame(0, $referrerStatement->publisher_earnings_minor);
        $this->assertSame(350, $referrerStatement->affiliate_earnings_minor);
        $this->assertSame(350, $referrerStatement->balance_due_minor);
        $this->assertSame(350, data_get($referrerStatement->snapshot, 'affiliate_earnings_minor'));
        $this->assertSame('AFFILIATE', data_get($referrerStatement->line_items, '0.source'));
    }

    public function test_rate_is_snapshotted_and_later_default_changes_do_not_mutate_finalized_commission_rows(): void
    {
        [$admin, $referrer, $referred] = $this->affiliateContext();
        $period = $this->period();
        $source = PublisherStatement::withoutGlobalScopes()->create([
            'organization_id' => $referred->organization_id,
            'publisher_id' => $referred->id,
            'financial_period_id' => $period->id,
            'statement_number' => 'HM-AFF-SOURCE-003',
            'status' => 'FINALIZED',
            'currency' => 'USD',
            'publisher_earnings_minor' => 10000,
            'balance_due_minor' => 10000,
            'line_items' => [],
            'snapshot' => [],
            'snapshot_hash' => hash('sha256', 'affiliate-source-003'),
            'finalized_at' => now(),
        ]);

        app(PublisherAffiliateService::class)->reconcilePeriod($period, $admin);
        $commission = PublisherAffiliateCommission::query()->where('source_publisher_statement_id', $source->id)->firstOrFail();
        $this->assertSame(500, $commission->commission_rate_bp);
        $this->assertSame(500, $commission->commission_minor);

        GlobalSetting::query()->updateOrCreate(
            ['key' => PublisherAffiliateService::SETTING_KEY],
            ['value' => ['commission_bp' => 1000], 'changed_by' => $admin->id],
        );

        $service = app(PublisherAffiliateService::class);
        $this->assertSame(1000, $service->defaultRateBp());
        $this->assertSame(1000, $service->effectiveRateBp($referrer->fresh()));

        $referrer->update(['affiliate_commission_override_bp' => 750]);
        $this->assertSame(750, $service->effectiveRateBp($referrer->fresh()));
        $this->assertSame(500, $commission->fresh()->commission_rate_bp);
        $this->assertSame(500, $commission->fresh()->commission_minor);
    }

    public function test_closed_period_affiliate_ledger_cannot_be_rebuilt(): void
    {
        $this->affiliateContext();
        $period = $this->period();
        $period->update(['status' => FinancialPeriodStatus::Closed]);

        $this->expectException(ValidationException::class);
        app(PublisherAffiliateService::class)->reconcilePeriod($period->fresh(), null);
    }

    public function test_referral_created_after_period_end_does_not_backdate_commission(): void
    {
        [, $referrer, $referred] = $this->affiliateContext();
        $period = $this->period();
        $referred->update(['referred_at' => now()]);

        PublisherStatement::withoutGlobalScopes()->create([
            'organization_id' => $referred->organization_id,
            'publisher_id' => $referred->id,
            'financial_period_id' => $period->id,
            'statement_number' => 'HM-AFF-SOURCE-004',
            'status' => 'FINALIZED',
            'currency' => 'USD',
            'publisher_earnings_minor' => 10000,
            'balance_due_minor' => 10000,
            'line_items' => [],
            'snapshot' => [],
            'snapshot_hash' => hash('sha256', 'affiliate-source-004'),
            'finalized_at' => now(),
        ]);

        $summary = app(PublisherAffiliateService::class)->reconcilePeriod($period, null);

        $this->assertArrayNotHasKey($referrer->id, $summary);
        $this->assertSame(0, PublisherAffiliateCommission::query()->where('financial_period_id', $period->id)->count());
    }

    public function test_existing_referral_attribution_is_not_replaced_by_a_later_signup_code(): void
    {
        [, $referrer, $referred] = $this->affiliateContext();
        $service = app(PublisherAffiliateService::class);

        $result = $service->attribute($referred, 'NOT-A-REAL-CODE');

        $this->assertSame($referrer->id, $result->referred_by_publisher_id);
    }

    public function test_invalid_referral_code_is_rejected_for_an_unattributed_publisher(): void
    {
        [, , $referred] = $this->affiliateContext();
        $service = app(PublisherAffiliateService::class);
        $referred->forceFill(['referred_by_publisher_id' => null, 'referred_at' => null])->save();

        $this->expectException(ValidationException::class);
        $service->attribute($referred->fresh(), 'NOT-A-REAL-CODE');
    }

    private function affiliateContext(): array
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);

        $referrerOrg = $this->makeOrganization(OrganizationType::Publisher, 'Referrer Publisher');
        $referrerUser = $this->makeUser($referrerOrg, RoleName::PublisherAdmin);
        $referrer = $this->makePublisherFor($referrerUser, [
            'legal_name' => 'Referrer Publisher LLC',
            'display_name' => 'Referrer Publisher',
            'billing_email' => 'referrer@example.test',
        ]);

        $referredOrg = $this->makeOrganization(OrganizationType::Publisher, 'Referred Publisher');
        $referredUser = $this->makeUser($referredOrg, RoleName::PublisherAdmin);
        $referred = $this->makePublisherFor($referredUser, [
            'legal_name' => 'Referred Publisher LLC',
            'display_name' => 'Referred Publisher',
            'billing_email' => 'referred@example.test',
            'referred_by_publisher_id' => $referrer->id,
            'referred_at' => now()->subMonthsNoOverflow(2),
        ]);

        return [$admin, $referrer, $referred, $referrerUser, $referredUser];
    }

    private function period(): FinancialPeriod
    {
        $date = now()->subMonthNoOverflow()->startOfMonth();

        return FinancialPeriod::query()->create([
            'period_key' => $date->format('Y-m'),
            'starts_on' => $date,
            'ends_on' => $date->endOfMonth(),
            'currency' => 'USD',
            'status' => FinancialPeriodStatus::Closing,
        ]);
    }
}
