<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, ReportFinality, ReportGranularity, ReportImportStatus, ReportSourceCode, RoleName};
use App\Models\{DailyReport, FinancialPeriod, GamRevenueCorrection, GamRevenueCorrectionReceipt, PublisherStatement, ReconciliationRun, ReportSource, ReportSourceConnection, SiteGamReportBinding};
use App\Services\Reporting\{AdminWebsitePerformanceService, FinancialPeriodService, PerformanceMetrics, PublisherFinanceService, PublisherPerformanceService, ReportImportService, SiteGamTodayReport, UnifiedReportService};
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\{InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class ReportMetricBasisTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private const FROM = '2034-04-15';
    private const KNOWN_DAY = '2034-04-16';
    private const TODAY = '2034-04-18';

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 12:00:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user, ['display_name' => 'Synthetic basis publisher']);
        $site = $this->makeSiteFor($publisher, $user, [
            'display_name' => 'Synthetic basis website', 'primary_domain' => 'basis-fixture.example',
        ]);
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'report_source_id' => ReportSource::where('code', ReportSourceCode::HorusGam->value)->firstOrFail()->id,
            'name' => 'Synthetic report basis', 'connection_type' => 'TEST', 'connection_id' => 'synthetic-basis',
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
        ]);

        return [$admin, $user, $publisher, $site, $connection];
    }

    private function provenance(string $scope = 'synthetic-historical-scope'): array
    {
        return [
            'gam_report_basis' => 'AD_EXCHANGE_V1', 'gam_report_site' => 'historical-fixture.example',
            'gam_ad_unit_id' => '80412', 'gam_report_scope' => $scope,
        ];
    }

    /** Import synthetic facts first, then classify their source to model retained legacy history. */
    private function import(array $context, string $date = self::FROM, array $metrics = [], array $provenance = [], ReportFinality $finality = ReportFinality::Finalized): DailyReport
    {
        [$admin, , $publisher, $site, $connection] = $context;
        $day = CarbonImmutable::parse($date);
        $job = app(ReportImportService::class)->importRows($connection, [[
            'date' => $date, 'site_id' => $site->id, 'publisher_id' => $publisher->id, 'currency' => 'USD',
            'ad_requests' => 750, 'matched_requests' => 650, 'unfilled_requests' => 100,
            'impressions' => 400, 'clicks' => 8, 'gross_revenue_minor' => 3900,
            'active_view_viewable_impressions' => 100, 'active_view_measurable_impressions' => 160,
            'unfilled_impressions' => 11, ...$metrics, ...$provenance,
        ]], ReportGranularity::Daily, $finality, $day, $day, $admin);
        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');

        return DailyReport::withoutGlobalScopes()->where('report_import_job_id', $job->id)->sole();
    }

    private function classify(ReportSourceConnection $connection, string $type = 'SITE_GAM_AD_UNIT', ReportSourceCode $source = ReportSourceCode::HorusGam): void
    {
        $connection->update([
            'connection_type' => $type,
            'report_source_id' => ReportSource::where('code', $source->value)->firstOrFail()->id,
            // Current configuration never supplies missing provenance for an older fact.
            'configuration' => ['site_report_scope' => [
                'metric_basis' => 'AD_EXCHANGE_V1', 'hostname' => 'current-fixture.example',
                'ad_unit_id' => '99021', 'fingerprint' => 'synthetic-current-scope',
            ]],
        ]);
    }

    private function assertUnavailable(array $summary): void
    {
        $this->assertTrue($summary['metric_basis_incomplete']);
        foreach (['impressions', 'clicks', 'ctr_bp', 'ecpm_minor', 'viewability_bp', 'ad_exchange_unmatched_requests', ...PerformanceMetrics::COUNTERS] as $metric) {
            $this->assertArrayHasKey($metric, $summary);
            $this->assertNull($summary[$metric], $metric.' must not mix incompatible report bases');
        }
    }

    private function assertKnown(array $summary, int $grossEcpm = 9750): void
    {
        $this->assertFalse($summary['metric_basis_incomplete']);
        $this->assertSame(($summary['has_site_ad_exchange'] ?? false) ? 100 : null, $summary['ad_exchange_unmatched_requests']);
        $this->assertSame(400, $summary['impressions']);
        $this->assertSame(8, $summary['clicks']);
        $this->assertSame(200, $summary['ctr_bp']);
        $this->assertSame($grossEcpm, $summary['ecpm_minor']);
        $this->assertSame(6250, $summary['viewability_bp']);
        $this->assertSame(100, $summary['active_view_viewable_impressions']);
        $this->assertSame(160, $summary['active_view_measurable_impressions']);
        $this->assertSame(11, $summary['unfilled_impressions']);
    }

    public function test_legacy_positive_and_zero_rows_are_unavailable_without_changing_revenue(): void
    {
        $context = [$admin, , $publisher, $site, $connection] = $this->context();
        $positive = $this->import($context);
        $zero = $this->import($context, self::KNOWN_DAY, array_fill_keys([
            'ad_requests', 'matched_requests', 'unfilled_requests', 'impressions', 'clicks', 'gross_revenue_minor',
            ...PerformanceMetrics::COUNTERS,
        ], 0));
        $this->classify($connection);
        $this->actingAs($admin);

        foreach ([$positive, $zero] as $row) {
            $date = $row->report_date->toDateString();
            $before = $row->getAttributes();
            $this->assertUnavailable(app(PerformanceMetrics::class)->summarize(collect([$row->fresh()]), 'gross_revenue_minor'));
            $publisherSummary = app(PublisherPerformanceService::class)->summary($publisher, $date, $date);
            $this->assertUnavailable($publisherSummary);
            $this->assertSame((int) $row->publisher_earnings_minor, $publisherSummary['earnings_minor']);
            $website = app(AdminWebsitePerformanceService::class)->summary($site, $date, $date);
            $this->assertUnavailable($website);
            $this->assertSame((int) $row->gross_revenue_minor, $website['gross_revenue_minor']);
            $this->assertSame($before, $row->fresh()->getAttributes());
        }
    }

    public function test_php_and_sql_reject_missing_null_and_malformed_immutable_provenance(): void
    {
        $context = [$admin, , , $site, $connection] = $this->context();
        $row = $this->import($context, provenance: $this->provenance());
        $this->classify($connection);
        $this->actingAs($admin);
        $valid = $this->provenance();
        $invalid = [null, [], ['gam_report_basis' => 'TOTAL_V1'], 'AD_EXCHANGE_V1', ['AD_EXCHANGE_V1']];
        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            $invalid[] = $missing;
            foreach ([null, '', '   ', "\t\r\n", "\0\v", 17, true, [], ['value' => $valid[$key]]] as $value) {
                $invalid[] = array_replace($valid, [$key => $value]);
            }
        }
        $invalid[] = array_replace($valid, ['gam_report_basis' => 'ad_exchange_v1']);
        $invalid[] = array_replace($valid, ['gam_report_basis' => 'AD_EXCHANGE_V1 ']);

        foreach ($invalid as $provenance) {
            $row->dimension()->firstOrFail()->update(['external_dimensions' => $provenance]);
            $this->assertUnavailable(app(PerformanceMetrics::class)->summarize(collect([$row->fresh()]), 'gross_revenue_minor'));
            $service = app(AdminWebsitePerformanceService::class);
            $this->assertUnavailable($service->summary($site, self::FROM, self::FROM));
            $this->assertUnavailable($service->summaries(collect([$site]), self::FROM, self::FROM)[$site->id]);
        }
    }

    public function test_complete_historical_and_forward_provenance_does_not_depend_on_current_binding_or_domain(): void
    {
        $context = [$admin, , $publisher, $site, $connection] = $this->context();
        $historical = $this->import($context, provenance: $this->provenance());
        $forward = array_replace($this->provenance('synthetic-forward-scope'), ['gam_report_site' => 'forward-fixture.example']);
        $this->import($context, self::KNOWN_DAY, [
            'impressions' => 600, 'clicks' => 4, 'gross_revenue_minor' => 6100,
            'active_view_viewable_impressions' => 80, 'active_view_measurable_impressions' => 100,
            'unfilled_impressions' => 9,
        ], $forward);
        $this->classify($connection);
        $site->update(['primary_domain' => 'current-fixture.example']);
        $this->actingAs($admin);
        $this->assertKnown(app(PerformanceMetrics::class)->summarize(collect([$historical->fresh()]), 'gross_revenue_minor'));

        $php = app(UnifiedReportService::class)->adminSummary(self::FROM, self::KNOWN_DAY)['performance'];
        $sql = app(AdminWebsitePerformanceService::class)->summary($site, self::FROM, self::KNOWN_DAY);
        foreach ([$php, $sql] as $summary) {
            $this->assertFalse($summary['metric_basis_incomplete']);
            $this->assertSame(1000, $summary['impressions']);
            $this->assertSame(12, $summary['clicks']);
            $this->assertSame(120, $summary['ctr_bp']);
            $this->assertSame(10000, $summary['ecpm_minor']);
            $this->assertSame(6923, $summary['viewability_bp']);
            $this->assertSame(20, $summary['unfilled_impressions']);
        }
        $publisherSummary = app(PublisherPerformanceService::class)->summary($publisher, self::FROM, self::KNOWN_DAY);
        $this->assertSame(7000, $publisherSummary['earnings_minor']);
        $this->assertSame(7000, $publisherSummary['ecpm_minor']);
        $this->assertSame(10000, $sql['gross_revenue_minor']);
    }

    public function test_mixed_periods_suppress_totals_and_all_unified_overrides_but_preserve_known_days(): void
    {
        $context = [$admin, , $publisher, $site, $connection] = $this->context();
        $this->import($context);
        $this->import($context, self::KNOWN_DAY, provenance: $this->provenance());
        $this->classify($connection);
        $this->actingAs($admin);
        $reports = app(UnifiedReportService::class);
        $staff = $reports->adminSummary(self::FROM, self::KNOWN_DAY);
        $this->assertUnavailable($staff['performance']);
        $this->assertNull($staff['managed_impressions']);
        $this->assertNull($staff['horus_gam_impressions']);
        foreach (['revenue_by_publisher', 'revenue_by_website', 'revenue_by_source', 'revenue_by_campaign'] as $key) {
            $this->assertUnavailable($staff[$key]->sole());
            $this->assertSame(7800, $staff[$key]->sole()['gross_revenue_minor']);
        }
        $this->assertUnavailable($staff['daily_revenue']->firstWhere('date', self::FROM));
        $this->assertKnown($staff['daily_revenue']->firstWhere('date', self::KNOWN_DAY));
        $this->assertSame(7800, $staff['gross_revenue_minor']);
        $this->assertSame(5460, $staff['publisher_earnings_minor']);
        $this->assertSame(2340, $staff['horus_margin_minor']);

        $publisherSummary = $reports->publisherSummary($publisher, self::FROM, self::KNOWN_DAY);
        $this->assertNull($publisherSummary['impressions']);
        $this->assertNull($publisherSummary['ecpm_micros']);
        $this->assertSame(5460, $publisherSummary['revenue_minor']);
        foreach (['websites', 'placements'] as $key) {
            $this->assertNull($publisherSummary[$key]->sole()['impressions']);
            $this->assertSame(5460, $publisherSummary[$key]->sole()['publisher_earnings_minor']);
        }
        $performance = app(PublisherPerformanceService::class)->summary($publisher, self::FROM, self::KNOWN_DAY);
        $this->assertUnavailable($performance);
        $this->assertUnavailable($performance['websites']->sole());
        $this->assertKnown($performance['days']->firstWhere('label', self::KNOWN_DAY), 6825);
        $service = app(AdminWebsitePerformanceService::class);
        $website = $service->summary($site, self::FROM, self::KNOWN_DAY);
        $this->assertUnavailable($website);
        $this->assertUnavailable($service->summaries(collect([$site]), self::FROM, self::KNOWN_DAY)[$site->id]);
        $this->assertKnown($website['days']->firstWhere('date', self::KNOWN_DAY));
        $this->assertKnown($service->summary($site, self::KNOWN_DAY, self::KNOWN_DAY));
    }

    public function test_either_site_source_marker_requires_provenance_while_other_sources_keep_their_metrics(): void
    {
        $context = [$admin, , , $site, $connection] = $this->context();
        $row = $this->import($context);
        $this->actingAs($admin);
        foreach ([['SITE_GAM_AD_UNIT', ReportSourceCode::HorusGam], ['TEST', ReportSourceCode::GamAdUnit]] as [$type, $source]) {
            $this->classify($connection, $type, $source);
            $this->assertUnavailable(app(PerformanceMetrics::class)->summarize(collect([$row->fresh()]), 'gross_revenue_minor'));
            $this->assertUnavailable(app(AdminWebsitePerformanceService::class)->summary($site, self::FROM, self::FROM));
        }

        foreach ([
            ['GAM_CONNECTION', ReportSourceCode::HorusGam],
            ['GAM_CONNECTION', ReportSourceCode::McmPartnerGam],
            ['GAM_CONNECTION', ReportSourceCode::PublisherGam],
            ['DEMAND_ACCOUNT', ReportSourceCode::Mgid],
            ['DIRECT_JS', ReportSourceCode::CustomCsv],
        ] as [$type, $source]) {
            $this->classify($connection, $type, $source);
            $this->assertKnown(app(PerformanceMetrics::class)->summarize(collect([$row->fresh()]), 'gross_revenue_minor'));
            $this->assertKnown(app(AdminWebsitePerformanceService::class)->summary($site, self::FROM, self::FROM));
        }
    }

    public function test_finance_today_month_and_bound_site_today_keep_money_when_legacy_counts_are_unavailable(): void
    {
        $context = [$admin, , $publisher, $site, $connection] = $this->context();
        $this->import($context);
        $this->import($context, self::KNOWN_DAY, provenance: $this->provenance());
        $this->import($context, self::TODAY, finality: ReportFinality::Estimated);
        $this->classify($connection);
        $gam = $this->makeGamConnection($admin->organization, $admin);
        SiteGamReportBinding::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id, 'site_id' => $site->id,
            'gam_connection_id' => $gam->id, 'report_source_connection_id' => $connection->id,
            'active_site_id' => $site->id, 'active_unit_key' => 'synthetic-today-unit',
            'network_code' => $gam->network_code, 'ad_unit_id' => '80412',
            'ad_unit_name' => 'Synthetic today unit', 'ad_unit_code' => 'synthetic_today',
            'starts_on' => self::FROM, 'created_by' => $admin->id,
        ]);
        $finance = app(PublisherFinanceService::class);
        $currency = $finance->overview($publisher)['currencies']->firstWhere('currency', 'USD');
        $this->assertTrue($currency['today_available']);
        $this->assertTrue($currency['today_metric_basis_incomplete']);
        $this->assertNull($currency['today_impressions']);
        $this->assertNull($currency['today_clicks']);
        $this->assertSame(2730, $currency['today_estimated_earnings_minor']);
        $this->assertSame(2730, $currency['estimated_earnings_minor']);
        $this->assertSame(5460, $currency['finalized_earnings_minor']);
        $dashboard = $finance->dashboard($publisher);
        $this->assertTrue($dashboard['metric_basis_incomplete']);
        $this->assertNull($dashboard['impressions']);
        $this->assertSame(5460, $dashboard['primary']['finalized_earnings_minor']);
        $today = app(SiteGamTodayReport::class)->forSite($site->fresh());
        $this->assertTrue($today['available']);
        $this->assertTrue($today['metric_basis_incomplete']);
        foreach (['ad_requests', 'impressions', 'clicks'] as $key) {
            $this->assertNull($today[$key]);
        }
        $this->assertSame(3900, $today['gross_revenue_minor']);
        $this->assertSame(2730, $today['publisher_earnings_minor']);
        $this->assertNotNull($today['updated_at']);
    }

    public function test_valid_zero_and_empty_periods_do_not_fabricate_ratios_or_incomplete_basis(): void
    {
        $context = [$admin, , $publisher, $site, $connection] = $this->context();
        $row = $this->import($context, metrics: array_fill_keys([
            'impressions', 'clicks', 'gross_revenue_minor', ...PerformanceMetrics::COUNTERS,
        ], 0), provenance: $this->provenance());
        $this->classify($connection);
        $this->actingAs($admin);
        $zero = app(PerformanceMetrics::class)->summarize(collect([$row->fresh()]), 'gross_revenue_minor');
        $this->assertFalse($zero['metric_basis_incomplete']);
        foreach (['impressions', 'clicks', ...PerformanceMetrics::COUNTERS] as $key) {
            $this->assertSame(0, $zero[$key]);
        }
        foreach (['ctr_bp', 'ecpm_minor', 'viewability_bp'] as $key) {
            $this->assertNull($zero[$key]);
        }
        $emptyPhp = app(PublisherPerformanceService::class)->summary($publisher, self::KNOWN_DAY, self::KNOWN_DAY);
        $emptySql = app(AdminWebsitePerformanceService::class)->summary($site, self::KNOWN_DAY, self::KNOWN_DAY);
        foreach ([$emptyPhp, $emptySql] as $empty) {
            $this->assertFalse($empty['available']);
            $this->assertFalse($empty['metric_basis_incomplete']);
            $this->assertSame(0, $empty['impressions']);
            $this->assertSame(0, $empty['clicks']);
            foreach (['ctr_bp', 'ecpm_minor', 'viewability_bp', ...PerformanceMetrics::COUNTERS] as $key) {
                $this->assertNull($empty[$key]);
            }
        }
    }

    public function test_csv_blanks_and_publisher_views_preserve_revenue_and_tenant_privacy(): void
    {
        $context = [$admin, $user, $publisher, $site, $connection] = $this->context();
        $this->import($context);
        $this->import($context, self::KNOWN_DAY, metrics: array_fill_keys([
            'impressions', 'clicks', 'gross_revenue_minor', ...PerformanceMetrics::COUNTERS,
        ], 0));
        $this->import($context, self::TODAY, provenance: $this->provenance());
        $otherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $otherPublisher = $this->makePublisherFor($otherUser, ['display_name' => 'PRIVATE BASIS TENANT']);
        $otherSite = $this->makeSiteFor($otherPublisher, $otherUser, [
            'display_name' => 'PRIVATE BASIS WEBSITE', 'primary_domain' => 'private-basis.example',
        ]);
        $this->import([$admin, $otherUser, $otherPublisher, $otherSite, $connection], metrics: [
            'impressions' => 987654, 'gross_revenue_minor' => 87654300,
        ]);
        $this->classify($connection);
        $parameters = ['from' => self::FROM, 'to' => self::TODAY, 'metrics' => array_keys(PerformanceMetrics::COLUMNS)];
        $this->actingAs($user);
        foreach (['publisher.reporting.index', 'publisher.finance.overview'] as $route) {
            $response = $this->get(route($route, $parameters))->assertOk()
                ->assertDontSee('PRIVATE BASIS')->assertDontSee('987654')->assertDontSee('876543')
                ->assertDontSee('Gross revenue')->assertDontSee('Horus margin')
                ->assertDontSee('gam_report_scope')->assertDontSee('historical-fixture.example');
            $summary = $response->viewData('performance');
            $this->assertUnavailable($summary);
            $this->assertSame(5460, $summary['earnings_minor']);
            $this->assertCount(3, $summary['days']);
            $csv = $this->get(route($route, [...$parameters, 'export' => 'csv']))->assertOk()->streamedContent();
            $rows = $this->csvRows($csv);
            $this->assertSame('Publisher earnings (USD)', $rows[0][7]);
            $this->assertSame([self::FROM, '', '', '', '', '', '', '27.30', '0.00', '27.30'], $rows[1]);
            $this->assertSame([self::KNOWN_DAY, '', '', '', '', '', '', '0.00', '0.00', '0.00'], $rows[2]);
            $this->assertSame([self::TODAY, '400', '8', '2.00%', '68.25', '62.50%', '11', '27.30', '0.00', '27.30'], $rows[3]);
            $this->assertStringNotContainsString('Gross revenue', $csv);
            $this->assertStringNotContainsString('876543', $csv);
        }
        $this->get(route('admin.reporting.websites.show', $site))->assertForbidden();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $csv = $this->get(route('admin.reporting.websites.show', ['site' => $site, ...$parameters, 'export' => 'csv']))
            ->assertOk()->streamedContent();
        $rows = $this->csvRows($csv);
        $this->assertSame([self::FROM, '', '', '', '', '', '', '39.00'], $rows[1]);
        $this->assertSame([self::KNOWN_DAY, '', '', '', '', '', '', '0.00'], $rows[2]);
        $this->assertSame([self::TODAY, '400', '8', '2.00%', '97.50', '62.50%', '11', '39.00'], $rows[3]);
        $this->assertStringNotContainsString('876543', $csv);
    }

    public function test_reporting_reads_do_not_rewrite_facts_imports_closed_statements_or_correction_receipts(): void
    {
        $context = [$admin, , $publisher, $site, $connection] = $this->context();
        $date = '2034-03-12';
        $row = $this->import($context, $date);
        $period = FinancialPeriod::withoutGlobalScopes()->where('period_key', '2034-03')->where('currency', 'USD')->sole();
        app(FinancialPeriodService::class)->close($period, $admin, 'Synthetic immutable reporting fixture');
        $statement = PublisherStatement::withoutGlobalScopes()->where('publisher_id', $publisher->id)->sole();
        $this->assertSame(2730, (int) $statement->publisher_earnings_minor);
        $this->classify($connection);
        $correction = GamRevenueCorrection::create([
            'actor_id' => $admin->id, 'organization_id' => $site->organization_id, 'site_id' => $site->id,
            'source_connection_id' => $connection->id, 'period_start' => $date, 'period_end' => $date,
            'status' => 'APPLIED', 'context' => ['fixture' => true], 'snapshot' => [],
            'query_hash' => hash('sha256', 'synthetic-query'), 'job' => [],
            'digest' => hash('sha256', 'synthetic-review'), 'expires_at' => now()->addHour(), 'applied_at' => now(),
        ]);
        GamRevenueCorrectionReceipt::create([
            'correction_id' => $correction->id, 'organization_id' => $site->organization_id,
            'approved_by' => $admin->id, 'report_import_job_id' => $row->report_import_job_id,
            'reconciliation_run_id' => ReconciliationRun::withoutGlobalScopes()->where('report_import_job_id', $row->report_import_job_id)->sole()->id,
            'digest' => $correction->digest, 'query_hash' => $correction->query_hash,
            'before_hash' => hash('sha256', 'synthetic-before'), 'after_hash' => hash('sha256', 'synthetic-after'),
            'reason' => 'Pre-existing synthetic evidence for read-only verification',
            'context' => ['fixture' => true], 'before' => ['facts' => []], 'after' => ['facts' => []],
            'applied_at' => now(),
        ]);
        $tables = ['daily_reports', 'hourly_reports', 'monthly_reports', 'report_dimensions', 'report_import_jobs',
            'report_source_connections', 'financial_periods', 'publisher_statements', 'reconciliation_runs',
            'revenue_adjustments', 'gam_revenue_corrections', 'gam_revenue_correction_receipts'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [
            $table => DB::table($table)->orderBy('id')->get()->map(fn ($record) => (array) $record)->all(),
        ])->all();
        $before = $snapshot();
        $this->actingAs($admin);
        $this->assertUnavailable(app(UnifiedReportService::class)->adminSummary($date, $date)['performance']);
        app(UnifiedReportService::class)->publisherSummary($publisher, $date, $date);
        $this->assertUnavailable(app(AdminWebsitePerformanceService::class)->summary($site, $date, $date));
        app(AdminWebsitePerformanceService::class)->summaries(collect([$site]), $date, $date);
        $this->assertUnavailable(app(PublisherPerformanceService::class)->summary($publisher, $date, $date));
        app(PublisherFinanceService::class)->overview($publisher);
        app(PublisherFinanceService::class)->dashboard($publisher);
        app(PublisherFinanceService::class)->statement($statement);
        $this->assertSame($before, $snapshot());
    }

    private function csvRows(string $csv): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, escape: '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
