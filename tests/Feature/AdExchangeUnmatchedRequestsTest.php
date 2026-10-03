<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, ReportFinality, ReportGranularity, ReportImportStatus, ReportSourceCode, RoleName};
use App\Models\{DailyReport, ReportSource, ReportSourceConnection};
use App\Services\Reporting\{AdminWebsitePerformanceService, PerformanceMetrics, PublisherPerformanceService, ReportImportService, ReportMetricBasis, UnifiedReportService};
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\{InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class AdExchangeUnmatchedRequestsTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private const METRIC = 'ad_exchange_unmatched_requests';
    private const FROM = '2034-04-15';
    private const TO = '2034-04-16';

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2034-04-18 12:00:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user, ['display_name' => 'Synthetic request publisher']);
        $site = $this->makeSiteFor($publisher, $user, [
            'display_name' => 'Synthetic request website', 'primary_domain' => 'request-fixture.example',
        ]);
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'report_source_id' => ReportSource::where('code', ReportSourceCode::HorusGam->value)->firstOrFail()->id,
            'name' => 'Synthetic request source', 'connection_type' => 'TEST', 'connection_id' => 'synthetic-request',
            'currency' => 'USD', 'timezone' => 'UTC', 'status' => 'ACTIVE', 'is_enabled' => true,
        ]);

        return [$admin, $user, $publisher, $site, $connection];
    }

    private function import(array $context, string $date = self::FROM, array $metrics = [], bool $provenance = true, ReportFinality $finality = ReportFinality::Finalized): DailyReport
    {
        [$admin, , $publisher, $site, $connection] = $context;
        $day = CarbonImmutable::parse($date);
        $job = app(ReportImportService::class)->importRows($connection, [[
            'date' => $date, 'site_id' => $site->id, 'publisher_id' => $publisher->id, 'currency' => 'USD',
            'ad_requests' => 120, 'matched_requests' => 100, 'impressions' => 70, 'clicks' => 2,
            'gross_revenue_minor' => 1234, 'unfilled_impressions' => null,
            ...($provenance ? [
                'gam_report_basis' => 'AD_EXCHANGE_V1', 'gam_report_site' => $site->primary_domain,
                'gam_ad_unit_id' => '80412', 'gam_report_scope' => 'synthetic-immutable-request-scope',
            ] : []), ...$metrics,
        ]], ReportGranularity::Daily, $finality, $day, $day, $admin);
        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');

        return DailyReport::withoutGlobalScopes()->where('report_import_job_id', $job->id)->sole();
    }

    private function classify(ReportSourceConnection $connection): void
    {
        $connection->update(['connection_type' => 'SITE_GAM_AD_UNIT']);
    }

    public function test_existing_persisted_adx_requests_are_readable_immediately_across_all_aggregations_without_writes(): void
    {
        $context = [$admin, , $publisher, $site, $connection] = $this->context();
        $positive = $this->import($context, metrics: ['unfilled_requests' => 999]);
        $this->import($context, self::TO, ['ad_requests' => 0, 'matched_requests' => 0]);
        $this->classify($connection);
        $before = DB::table('daily_reports')->orderBy('id')->get()->toJson();
        $this->actingAs($admin);

        // Ignores old derived/clamped counters and never subtracts impressions.
        $this->assertSame(20, app(ReportMetricBasis::class)->adExchangeUnmatchedRequests(collect([$positive->fresh()])));
        $publisherSummary = app(PublisherPerformanceService::class)->summary($publisher, self::FROM, self::TO);
        $this->assertSame(20, $publisherSummary[self::METRIC]);
        $this->assertSame([20, 0], $publisherSummary['days']->pluck(self::METRIC)->all());
        $this->assertSame([20], $publisherSummary['websites']->pluck(self::METRIC)->all());
        $this->assertNull($publisherSummary['unfilled_impressions']);
        $this->assertArrayNotHasKey('gross_revenue_minor', $publisherSummary);
        $this->assertTrue($publisherSummary['has_site_ad_exchange']);
        $this->assertFalse($publisherSummary['has_other_sources']);

        $website = app(AdminWebsitePerformanceService::class)->summary($site, self::FROM, self::TO);
        $this->assertSame(20, $website[self::METRIC]);
        $this->assertSame([20, 0], $website['days']->pluck(self::METRIC)->all());
        $directory = app(AdminWebsitePerformanceService::class)->summaries(collect([$site]), self::FROM, self::TO);
        $this->assertSame(20, $directory[$site->id][self::METRIC]);
        $summary = app(UnifiedReportService::class)->adminSummary(self::FROM, self::TO);
        $this->assertSame(20, $summary['performance'][self::METRIC]);
        foreach (['revenue_by_publisher', 'revenue_by_website', 'revenue_by_source', 'revenue_by_campaign'] as $group) {
            $this->assertSame([20], $summary[$group]->pluck(self::METRIC)->all());
        }
        $this->assertSame([20, 0], $summary['daily_revenue']->pluck(self::METRIC)->all());
        $this->assertSame($before, DB::table('daily_reports')->orderBy('id')->get()->toJson());
    }

    public function test_inconsistent_day_cannot_be_hidden_by_positive_other_days_or_clamped_to_zero(): void
    {
        $context = [$admin, , $publisher, $site, $connection] = $this->context();
        $row = $this->import($context);
        $this->import($context, self::TO, ['ad_requests' => 200, 'matched_requests' => 100]);
        $this->classify($connection);
        $row->update(['matched_requests' => 121]);
        $this->actingAs($admin);
        $summary = app(PublisherPerformanceService::class)->summary($publisher, self::FROM, self::TO);
        $this->assertNull($summary[self::METRIC]);
        $this->assertSame([null, 100], $summary['days']->pluck(self::METRIC)->all());
        $this->assertFalse($summary['metric_basis_incomplete']);
        $this->assertSame(140, $summary['impressions']);
        $service = app(AdminWebsitePerformanceService::class);
        $this->assertNull($service->summary($site, self::FROM, self::TO)[self::METRIC]);
        $this->assertNull($service->summaries(collect([$site]), self::FROM, self::TO)[$site->id][self::METRIC]);
        $base = $row->fresh()->toArray();
        $base['connection'] = ['connection_type' => 'SITE_GAM_AD_UNIT'];
        $base['dimension'] = $row->dimension->toArray();
        foreach ([['ad_requests' => null], ['matched_requests' => null], ['ad_requests' => -1], ['matched_requests' => -1]] as $invalid) {
            $this->assertNull(app(ReportMetricBasis::class)->adExchangeUnmatchedRequests(collect([array_replace($base, $invalid)])));
        }
        $this->assertNull(app(ReportMetricBasis::class)->adExchangeUnmatchedRequests(collect()));
    }

    public function test_legacy_and_other_source_rows_never_turn_into_adx_requests(): void
    {
        $context = [$admin, , $publisher, $site, $connection] = $this->context();
        $this->import($context);
        $legacy = $this->import($context, self::TO, provenance: false);
        $this->classify($connection);
        $this->actingAs($admin);
        $service = app(AdminWebsitePerformanceService::class);
        $this->assertNull($service->summary($site, self::FROM, self::TO)[self::METRIC]);
        $summary = app(PublisherPerformanceService::class)->summary($publisher, self::FROM, self::TO);
        $this->assertNull($summary[self::METRIC]);
        $this->assertSame([20, null], $summary['days']->pluck(self::METRIC)->all());
        $legacy->update(['ad_requests' => 0, 'matched_requests' => 0]);
        $this->assertNull($service->summary($site, self::TO, self::TO)[self::METRIC]);
        $this->fixture('publisher-legacy', $this->actingAs($context[1])->get(route('publisher.reports.index', ['from' => self::FROM, 'to' => self::TO]))->assertOk());

        $other = $connection->replicate();
        $other->connection_type = 'TEST';
        $other->connection_id = 'synthetic-other-request-source';
        $other->save();
        $legacy->update(['report_source_connection_id' => $other->id, 'unfilled_impressions' => 9]);
        $this->actingAs($admin);
        $otherOnly = $service->summary($site, self::TO, self::TO);
        $this->assertSame(9, $otherOnly['unfilled_impressions']);
        $this->assertNull($otherOnly[self::METRIC]);
        $this->assertContains('unfilled_impressions', PerformanceMetrics::defaultColumns($otherOnly));
        $mixed = $service->summary($site, self::FROM, self::TO);
        $this->assertNull($mixed[self::METRIC]);
        $this->assertTrue($mixed['has_site_ad_exchange']);
        $this->assertTrue($mixed['has_other_sources']);
    }

    public function test_source_aware_cards_defaults_selectors_csv_and_explicit_legacy_selection_are_consistent(): void
    {
        $context = [$admin, $user, , $site, $connection] = $this->context();
        $this->import($context);
        $this->import($context, self::TO, ['ad_requests' => 100, 'matched_requests' => 100]);
        $this->classify($connection);
        $params = ['from' => self::FROM, 'to' => self::TO];
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        foreach ([['admin', 'admin.reporting.index', []], ['website', 'admin.reporting.websites.show', ['site' => $site]],
            ['directory', 'admin.reporting.websites.index', []]] as [$name, $route, $extra]) {
            $response = $this->get(route($route, $params + $extra))->assertOk()->assertSee('Ad Exchange unmatched requests');
            $this->assertContains(self::METRIC, $response->viewData($name === 'directory' ? 'metrics' : 'reportMetrics'));
            $this->assertNotContains('unfilled_impressions', $response->viewData($name === 'directory' ? 'metrics' : 'reportMetrics'));
            $this->fixture($name, $response);
            if ($name !== 'directory') $this->assertCsv($route, $params + $extra);
        }
        $this->actingAs($user);
        foreach (['publisher.reports.index', 'publisher.finance.overview'] as $route) {
            $response = $this->get(route($route, $params))->assertOk()->assertSee('Ad Exchange unmatched requests')
                ->assertSee('not empty ad slots')->assertSee('Unfilled impressions are a different source metric');
            $this->assertContains(self::METRIC, $response->viewData('reportMetrics'));
            $this->assertNotContains('unfilled_impressions', $response->viewData('reportMetrics'));
            if ($route === 'publisher.reports.index') $this->fixture('publisher', $response);
            $this->assertCsv($route, $params);
            // An old/custom URL cannot bring back the unsupported default card.
            // Explicit detail selection remains distinct and is not silently relabeled.
            $selected = $this->get(route($route, $params + ['metrics' => ['unfilled_impressions']]))->assertOk();
            $this->assertSame(['unfilled_impressions'], $selected->viewData('reportMetrics'));
            $html = $selected->getContent();
            $cards = explode('</section>', explode('<section class="publisher-ad-metrics"', $html)[1])[0];
            $this->assertStringContainsString('Ad Exchange unmatched requests', $cards);
            $this->assertStringNotContainsString('Unfilled impressions', $cards);
        }
        $all = $this->get(route('publisher.reports.index', $params + ['customize' => 1, 'metrics' => array_keys(PerformanceMetrics::COLUMNS)]))->assertOk();
        $this->assertCount(7, $all->viewData('reportMetrics'));
    }

    private function assertCsv(string $route, array $params): void
    {
        $csv = $this->get(route($route, $params + ['export' => 'csv', 'metrics' => [self::METRIC, 'unfilled_impressions']]))->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', explode("\n", trim($csv)));
        $this->assertSame(['Date', 'Ad Exchange unmatched requests', 'Unfilled impressions'], array_slice($lines[0], 0, 3));
        $this->assertSame([self::FROM, '20', ''], array_slice($lines[1], 0, 3));
        $this->assertSame([self::TO, '0', ''], array_slice($lines[2], 0, 3));
    }

    private function fixture(string $name, TestResponse $response): void
    {
        if (getenv('HORUS_UI_FIXTURES') !== '1') return;
        $directory = storage_path('framework/testing/adx-unmatched');
        if (! is_dir($directory)) mkdir($directory, 0755, true);
        $html = str_replace('</head>', '<link rel="stylesheet" href="/fixture.css"></head>', $response->getContent());
        $html = str_replace('</body>', '<script type="module" src="/fixture.js"></script></body>', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}
