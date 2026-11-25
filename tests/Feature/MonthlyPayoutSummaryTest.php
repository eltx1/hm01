<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, PublisherStatementStatus, ReportFinality, ReportGranularity, ReportImportStatus, ReportSourceCode, RoleName};
use App\Models\{DailyReport, FinancialPeriod, Publisher, PublisherStatement, ReportDimension, ReportImportJob, ReportSource, ReportSourceConnection, Site, SiteGamReportBinding, SiteGamVideoReportBinding};
use App\Services\Reporting\{MonthlyPayoutSummaryService, PublisherPerformanceService, SiteGamReportMetrics};
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

final class MonthlyPayoutSummaryTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private function context(): array
    {
        config(['app.timezone' => 'UTC', 'reporting.canonical_currency' => 'USD']);
        $this->travelTo(CarbonImmutable::parse('2026-11-25 12:00:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $gam = $this->makeGamConnection($admin->organization, $admin);

        return compact('admin', 'gam');
    }

    /** @return array{0: \App\Models\User, 1: Publisher, 2: Site} */
    private function publisher(string $name): array
    {
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, $name.' Org'), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user, ['display_name' => $name]);
        $site = $this->makeSiteFor($publisher, $user, ['display_name' => $name.' site']);

        return [$user, $publisher, $site];
    }

    private function binding(array $context, Publisher $publisher, Site $site, bool $video = false): SiteGamReportBinding|SiteGamVideoReportBinding
    {
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'report_source_id' => ReportSource::where('code', ($video ? ReportSourceCode::GamVideoAdUnit : ReportSourceCode::GamAdUnit)->value)->firstOrFail()->id,
            'name' => 'Source', 'connection_type' => $video ? 'SITE_GAM_VIDEO_AD_UNIT' : 'SITE_GAM_AD_UNIT',
            'connection_id' => (string) str()->ulid(), 'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
        ]);
        $class = $video ? SiteGamVideoReportBinding::class : SiteGamReportBinding::class;
        $binding = $class::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'site_id' => $site->id,
            'gam_connection_id' => $context['gam']->id, 'report_source_connection_id' => $connection->id,
            'active_site_id' => $site->id, 'network_code' => $context['gam']->network_code,
            'ad_unit_id' => (string) fake()->unique()->numberBetween(100000, 999999), 'ad_unit_name' => 'Unit',
            'ad_unit_code' => $video ? 'video' : 'main', 'starts_on' => '2026-09-01', 'created_by' => $context['admin']->id,
        ]);
        $connection->update(['connection_id' => $binding->id]);

        return $binding->load('connection');
    }

    private function fact(Publisher $publisher, Site $site, $binding, string $date, int $gross, int $publisherShare, int $horusShare,
        ReportFinality $finality = ReportFinality::Finalized, ReportImportStatus $status = ReportImportStatus::Completed): DailyReport
    {
        $job = ReportImportJob::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'report_source_connection_id' => $binding->report_source_connection_id,
            'import_type' => 'API', 'granularity' => ReportGranularity::Daily, 'finality' => $finality, 'status' => $status,
            'period_start' => $date.' 00:00:00', 'period_end' => $date.' 23:59:59',
            'idempotency_key' => hash('sha256', (string) str()->ulid()), 'row_count' => 1,
            'completed_at' => $status === ReportImportStatus::Completed ? now() : null,
        ]);
        $dimension = ReportDimension::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'publisher_id' => $publisher->id, 'site_id' => $site->id,
            'gam_connection_id' => $binding->gam_connection_id, 'dimension_hash' => hash('sha256', (string) str()->ulid()),
            'external_dimensions' => ['gam_report_basis' => SiteGamReportMetrics::BASIS, 'gam_report_site' => $site->primary_domain,
                'gam_ad_unit_id' => $binding->ad_unit_id, 'gam_report_scope' => 'EXACT_SITE_V1'],
        ]);

        return DailyReport::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id, 'report_source_connection_id' => $binding->report_source_connection_id,
            'report_import_job_id' => $job->id, 'report_dimension_id' => $dimension->id,
            'report_date' => $date, 'finality' => $finality, 'currency' => 'USD',
            'gross_revenue_minor' => $gross, 'net_revenue_minor' => $gross,
            'publisher_earnings_minor' => $publisherShare, 'horus_earnings_minor' => $horusShare,
            'impressions' => 1000, 'clicks' => 0, 'ad_requests' => 1000, 'matched_requests' => 1000,
            'active_view_viewable_impressions' => 0, 'active_view_measurable_impressions' => 0,
            'source_row_hash' => hash('sha256', (string) str()->ulid()),
        ]);
    }

    public function test_one_month_only_and_the_three_amounts_reconcile_per_publisher(): void
    {
        $context = $this->context();
        [, $nile, $nileSite] = $this->publisher('Nile Media');
        $main = $this->binding($context, $nile, $nileSite);
        // Neighbouring months must never leak into October.
        $this->fact($nile, $nileSite, $main, '2026-09-30', 99999, 70000, 29999);
        $this->fact($nile, $nileSite, $main, '2026-10-01', 10000, 7000, 2500);
        $this->fact($nile, $nileSite, $main, '2026-10-31', 20000, 14000, 5000);
        $this->fact($nile, $nileSite, $main, '2026-11-01', 88888, 60000, 28888);
        // A failed import never counts.
        $this->fact($nile, $nileSite, $main, '2026-10-15', 50000, 35000, 15000, ReportFinality::Finalized, ReportImportStatus::Failed);

        $summary = app(MonthlyPayoutSummaryService::class)->summary('2026-10');
        $row = $summary['rows']->sole();

        $this->assertSame('Nile Media', $row['publisher']);
        $this->assertSame([30000, 21000, 7500, 1500], [$row['gross_minor'], $row['publisher_minor'], $row['horus_minor'], $row['other_minor']]);
        $this->assertSame($row['gross_minor'], $row['publisher_minor'] + $row['horus_minor'] + $row['other_minor']);
        $this->assertSame(2, $row['days']);
        $this->assertTrue($row['final']);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$summary['from'], $summary['to']]);
        $this->assertTrue($summary['complete']);
    }

    public function test_payout_matches_what_the_publisher_sees_including_video_and_estimates(): void
    {
        $context = $this->context();
        [, $nile, $site] = $this->publisher('Nile Media');
        $main = $this->binding($context, $nile, $site);
        $video = $this->binding($context, $nile, $site, true);
        $this->fact($nile, $site, $main, '2026-10-05', 10000, 7000, 3000);
        $this->fact($nile, $site, $main, '2026-10-06', 4000, 2800, 1200, ReportFinality::Estimated);
        $this->fact($nile, $site, $video, '2026-10-07', 5000, 3500, 1500);

        $row = app(MonthlyPayoutSummaryService::class)->summary('2026-10')['rows']->sole();
        $own = app(PublisherPerformanceService::class)->summary($nile, '2026-10-01', '2026-10-31');

        // Publisher page: display earnings + its separate Video section.
        $this->assertSame($own['earnings_minor'] + $own['video']['revenue_minor'], $row['publisher_minor']);
        $this->assertSame(13300, $row['publisher_minor']);
        $this->assertSame(3500, $row['video_publisher_minor']);
        $this->assertSame(2800, $row['estimated_publisher_minor']);
        $this->assertFalse($row['final']);
    }

    public function test_publishers_are_separated_and_totals_add_up(): void
    {
        $context = $this->context();
        [, $nile, $nileSite] = $this->publisher('Nile Media');
        [, $delta, $deltaSite] = $this->publisher('Delta News');
        $this->fact($nile, $nileSite, $this->binding($context, $nile, $nileSite), '2026-10-10', 10000, 7000, 3000);
        $this->fact($delta, $deltaSite, $this->binding($context, $delta, $deltaSite), '2026-10-11', 30000, 21000, 9000);

        $summary = app(MonthlyPayoutSummaryService::class)->summary('2026-10');

        $this->assertSame(['Delta News', 'Nile Media'], $summary['rows']->pluck('publisher')->all());
        $total = $summary['totals']->sole();
        $this->assertSame(['USD', 2, 40000, 28000, 12000], [$total['currency'], $total['publishers'], $total['gross_minor'], $total['publisher_minor'], $total['horus_minor']]);
    }

    public function test_statement_balances_from_earlier_months_are_shown_beside_the_month_never_added(): void
    {
        $context = $this->context();
        [, $nile, $site] = $this->publisher('Nile Media');
        $this->fact($nile, $site, $this->binding($context, $nile, $site), '2026-10-10', 10000, 7000, 3000);
        $period = FinancialPeriod::query()->create(['organization_id' => null, 'period_key' => '2026-10', 'currency' => 'USD',
            'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'status' => 'OPEN']);
        PublisherStatement::withoutGlobalScopes()->create([
            'organization_id' => $nile->organization_id, 'publisher_id' => $nile->id, 'financial_period_id' => $period->id,
            'statement_number' => 'HM-PS-2026-10-TEST', 'currency' => 'USD', 'status' => PublisherStatementStatus::PendingInvoice,
            'opening_balance_minor' => 2500, 'gross_revenue_minor' => 9000, 'deductions_minor' => 0, 'net_revenue_minor' => 9000,
            'publisher_earnings_minor' => 6300, 'affiliate_earnings_minor' => 0, 'paid_minor' => 0, 'balance_due_minor' => 8800,
            'carry_forward_minor' => 0, 'payment_threshold_minor' => 5000, 'line_items' => [], 'snapshot' => [], 'snapshot_hash' => str_repeat('a', 64),
        ]);

        $summary = app(MonthlyPayoutSummaryService::class)->summary('2026-10');
        $row = $summary['rows']->sole();

        $this->assertSame(7000, $row['publisher_minor'], 'the carried-in balance is not part of October');
        $this->assertSame(2500, $row['statement']['carried_in_minor']);
        $this->assertSame(8800, $row['statement']['balance_due_minor']);
        $this->assertFalse($row['statement']['matches_ledger']);
        $this->assertSame(1, $summary['mismatches']);
    }

    public function test_admin_page_csv_default_month_and_permissions(): void
    {
        $context = $this->context();
        [$publisherUser, $nile, $site] = $this->publisher('Nile Media');
        $this->fact($nile, $site, $this->binding($context, $nile, $site), '2026-10-10', 123456, 86419, 30864);
        $admin = $this->actingAs($context['admin'])->withSession(['two_factor_passed_at' => now()->timestamp]);

        // Default: the last complete month (October while it is November).
        $html = $admin->get(route('admin.finance.monthly-payouts'))->assertOk()->getContent();
        $this->assertStringContainsString('October 2026', $html);
        $this->assertStringContainsString('Nile Media', $html);
        $this->assertStringContainsString('1,234.56', $html);
        $this->assertStringContainsString('864.19', $html);
        $this->assertStringContainsString('308.64', $html);
        $this->assertStringContainsString('aria-current="page" class="active">Monthly payouts</a>', $html);
        $admin->get(route('admin.finance.monthly-payouts', ['month' => '1999-01']))->assertOk()->assertSee('October 2026');
        $admin->get(route('admin.finance.monthly-payouts', ['month' => '2026-09']))->assertOk()->assertSee('No publisher earnings for this month');

        $csv = $admin->get(route('admin.finance.monthly-payouts.csv', ['month' => '2026-10']))->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', array_filter(explode("\n", trim($csv))));
        $this->assertSame(['Month', 'Publisher', 'Currency', 'Gross revenue', 'Publisher net earnings', 'Horus commission'], array_slice($lines[0], 0, 6));
        $this->assertSame(['2026-10', 'Nile Media', 'USD', '1234.56', '864.19', '308.64'], array_slice($lines[1], 0, 6));
        $this->assertSame(['2026-10', 'TOTAL', 'USD', '1234.56', '864.19', '308.64'], array_slice($lines[2], 0, 6));

        $this->actingAs($publisherUser)->get(route('admin.finance.monthly-payouts'))->assertForbidden();
    }
}
