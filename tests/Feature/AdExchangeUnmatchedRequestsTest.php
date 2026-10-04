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
    private const UNIT_UNFILLED_LABEL = 'Unfilled impressions (ad unit, all sites)';
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
        $this->fixture('publisher-legacy', $this->actingAs($context[1])->get(route('publisher.reporting.index', ['from' => self::FROM, 'to' => self::TO]))->assertOk());

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
        $this->assertNull($mixed['unfilled_impressions']);
        $this->assertContains('unfilled_impressions', PerformanceMetrics::defaultColumns($mixed));
        $this->assertContains(self::METRIC, PerformanceMetrics::defaultColumns($mixed));
    }

    public function test_true_unfilled_is_added_to_default_cards_columns_and_csv_without_replacing_current_metrics(): void
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
            $this->assertContains('unfilled_impressions', $response->viewData($name === 'directory' ? 'metrics' : 'reportMetrics'));
            if ($name !== 'directory') $this->assertDefaultCards($response, 'report-quality-metrics', '—');
            $this->fixture($name, $response);
            if ($name !== 'directory') {
                $this->assertDefaultCsv($route, $params + $extra);
                $this->assertCsv($route, $params + $extra);
            }
            $selected = $this->get(route($route, $params + $extra + ['metrics' => [self::METRIC]]))->assertOk();
            $this->assertSame([self::METRIC], $selected->viewData($name === 'directory' ? 'metrics' : 'reportMetrics'));
            $this->fixture($name.'-unmatched', $selected);
        }
        $this->actingAs($user);
        foreach (['publisher.reporting.index', 'publisher.finance.overview'] as $route) {
            $response = $this->get(route($route, $params))->assertOk()->assertSee('Ad Exchange unmatched requests')
                ->assertSee('not empty ad slots')->assertSee('Unfilled impressions are a source-reported metric');
            $this->assertContains(self::METRIC, $response->viewData('reportMetrics'));
            $this->assertContains('unfilled_impressions', $response->viewData('reportMetrics'));
            $this->assertDefaultCards($response, 'publisher-ad-metrics', 'Unavailable');
            if ($route === 'publisher.reporting.index') $this->fixture('publisher', $response);
            $this->assertDefaultCsv($route, $params);
            $this->assertCsv($route, $params);
            // Explicit selection never substitutes request counts for original Unfilled.
            $selected = $this->get(route($route, $params + ['metrics' => ['unfilled_impressions']]))->assertOk();
            $this->assertSame(['unfilled_impressions'], $selected->viewData('reportMetrics'));
            $this->assertDefaultCards($selected, 'publisher-ad-metrics', 'Unavailable');
            $selected = $this->get(route($route, $params + ['metrics' => [self::METRIC]]))->assertOk();
            $this->assertSame([self::METRIC], $selected->viewData('reportMetrics'));
            $this->assertDefaultCards($selected, 'publisher-ad-metrics', 'Unavailable');
            if ($route === 'publisher.reporting.index') $this->fixture('publisher-unmatched', $selected);
        }
        $all = $this->get(route('publisher.reporting.index', $params + ['customize' => 1, 'metrics' => array_keys(PerformanceMetrics::COLUMNS)]))->assertOk();
        $this->assertCount(7, $all->viewData('reportMetrics'));
    }

    public function test_mixed_directory_adds_true_unfilled_without_removing_distinct_source_metrics(): void
    {
        $context = [$admin, $user, $publisher, $site, $connection] = $this->context();
        $this->import($context);
        $otherSite = $this->makeSiteFor($publisher, $user, [
            'display_name' => 'Synthetic other-source website', 'primary_domain' => 'other-request-fixture.example',
        ]);
        $other = $connection->replicate();
        $other->connection_id = 'synthetic-directory-other';
        $other->save();
        $this->import([$admin, $user, $publisher, $otherSite, $other], metrics: ['unfilled_impressions' => 9], provenance: false);
        $this->classify($connection);
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $params = ['from' => self::FROM, 'to' => self::FROM];
        $response = $this->get(route('admin.reporting.websites.index', $params))->assertOk();
        $this->assertContains(self::METRIC, $response->viewData('metrics'));
        $this->assertContains('unfilled_impressions', $response->viewData('metrics'));
        $totals = $response->viewData('totals');
        $this->assertSame(20, $totals[$site->id][self::METRIC]);
        $this->assertNull($totals[$site->id]['unfilled_impressions']);
        $this->assertSame(9, $totals[$otherSite->id]['unfilled_impressions']);
        $this->assertNull($totals[$otherSite->id][self::METRIC]);
        $this->fixture('directory-mixed', $response);
        $selected = $this->get(route('admin.reporting.websites.index', $params + ['metrics' => [self::METRIC, 'unfilled_impressions']]))->assertOk();
        $this->assertSame([self::METRIC, 'unfilled_impressions'], $selected->viewData('metrics'));
        $this->fixture('directory-mixed-selected', $selected);
        $selected = $this->get(route('admin.reporting.websites.index', $params + ['metrics' => ['impressions']]))->assertOk();
        $this->assertSame(['impressions'], $selected->viewData('metrics'));
    }

    private function assertDefaultCards(TestResponse $response, string $class, string $unavailable): void
    {
        $cards = explode('</section>', explode('<section class="'.$class.'"', $response->getContent())[1])[0];
        $this->assertStringContainsString(self::UNIT_UNFILLED_LABEL, $cards);
        $this->assertStringContainsString('Ad Exchange unmatched requests', $cards);
        $this->assertMatchesRegularExpression('/'.preg_quote(self::UNIT_UNFILLED_LABEL, '/').'<\/(?:h3|p)>\s*<strong>'.preg_quote($unavailable, '/').'\s*<\/strong>/', $cards);
    }

    private function assertDefaultCsv(string $route, array $params): void
    {
        $csv = $this->get(route($route, $params + ['export' => 'csv']))->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', explode("\n", trim($csv)));
        $this->assertContains(self::UNIT_UNFILLED_LABEL, $lines[0]);
        $this->assertContains('Ad Exchange unmatched requests', $lines[0]);
        $column = array_search(self::UNIT_UNFILLED_LABEL, $lines[0], true);
        $this->assertSame('', $lines[1][$column]);
        $this->assertSame('', $lines[2][$column]);
        $unmatchedColumn = array_search('Ad Exchange unmatched requests', $lines[0], true);
        $this->assertSame('20', $lines[1][$unmatchedColumn]);
        $this->assertSame('0', $lines[2][$unmatchedColumn]);
    }

    private function assertCsv(string $route, array $params): void
    {
        $csv = $this->get(route($route, $params + ['export' => 'csv', 'metrics' => [self::METRIC, 'unfilled_impressions']]))->assertOk()->streamedContent();
        $lines = array_map('str_getcsv', explode("\n", trim($csv)));
        $this->assertSame(['Date', 'Ad Exchange unmatched requests', self::UNIT_UNFILLED_LABEL], array_slice($lines[0], 0, 3));
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
