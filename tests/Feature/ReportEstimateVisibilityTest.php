<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, ReportFinality, ReportGranularity, ReportImportStatus, ReportSourceCode, RoleName};
use App\Models\{DailyReport, ReportDimension, ReportImportJob, ReportSource, ReportSourceConnection, SiteGamReportBinding, SiteGamUnfilledReport, SiteGamVideoReportBinding, SiteGamVideoUnfilledReport};
use App\Services\Reporting\{AdminWebsitePerformanceService, PublisherPerformanceService, ReportCoverageService, SiteGamReportMetrics, UnifiedReportService};
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\{InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class ReportEstimateVisibilityTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private function context(): array
    {
        config(['app.timezone' => 'UTC', 'reporting.canonical_currency' => 'USD']);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 12:00:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user, ['display_name' => 'Visible website']);
        $gam = $this->makeGamConnection($admin->organization, $admin);

        return compact('admin', 'user', 'publisher', 'site', 'gam');
    }

    private function binding(array $context, bool $video = false, string $timezone = 'UTC'): SiteGamReportBinding
    {
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $context['publisher']->organization_id,
            'report_source_id' => ReportSource::where('code', ($video ? ReportSourceCode::GamVideoAdUnit : ReportSourceCode::GamAdUnit)->value)->firstOrFail()->id,
            'name' => 'Private source name', 'connection_type' => $video ? 'SITE_GAM_VIDEO_AD_UNIT' : 'SITE_GAM_AD_UNIT',
            'connection_id' => (string) str()->ulid(), 'currency' => 'USD', 'timezone' => $timezone,
            'status' => 'ACTIVE', 'is_enabled' => true,
        ]);
        $class = $video ? SiteGamVideoReportBinding::class : SiteGamReportBinding::class;
        $binding = $class::withoutGlobalScopes()->create([
            'organization_id' => $context['publisher']->organization_id, 'site_id' => $context['site']->id,
            'gam_connection_id' => $context['gam']->id, 'report_source_connection_id' => $connection->id,
            'active_site_id' => $context['site']->id, 'network_code' => $context['gam']->network_code,
            'ad_unit_id' => (string) fake()->unique()->numberBetween(100000, 999999), 'ad_unit_name' => 'Private Google unit',
            'ad_unit_code' => $video ? 'private-video' : 'private-main',
            'starts_on' => '2026-10-01', 'created_by' => $context['admin']->id,
        ]);
        $connection->update(['connection_id' => $binding->id]);

        return $binding->load('connection');
    }

    /** Synthetic persisted facts isolate read behavior from import/settlement policy. */
    private function fact(array $context, SiteGamReportBinding $binding, string $date, ReportFinality $finality = ReportFinality::Estimated,
        int $gross = 10000, int $impressions = 1000, ReportImportStatus $status = ReportImportStatus::Completed): DailyReport
    {
        $job = ReportImportJob::withoutGlobalScopes()->create([
            'organization_id' => $context['publisher']->organization_id,
            'report_source_connection_id' => $binding->report_source_connection_id,
            'import_type' => 'API', 'granularity' => ReportGranularity::Daily, 'finality' => $finality,
            'status' => $status, 'period_start' => $date.' 00:00:00', 'period_end' => $date.' 23:59:59',
            'idempotency_key' => hash('sha256', (string) str()->ulid()), 'row_count' => 1,
            'completed_at' => $status === ReportImportStatus::Completed ? now() : null,
        ]);
        $dimension = ReportDimension::withoutGlobalScopes()->create([
            'organization_id' => $context['publisher']->organization_id,
            'publisher_id' => $context['publisher']->id, 'site_id' => $context['site']->id,
            'gam_connection_id' => $binding->gam_connection_id,
            'dimension_hash' => hash('sha256', (string) str()->ulid()),
            'external_dimensions' => ['gam_report_basis' => SiteGamReportMetrics::BASIS,
                'gam_report_site' => $context['site']->primary_domain, 'gam_ad_unit_id' => $binding->ad_unit_id,
                'gam_report_scope' => 'EXACT_SITE_V1'],
        ]);

        return DailyReport::withoutGlobalScopes()->create([
            'organization_id' => $context['publisher']->organization_id,
            'report_source_connection_id' => $binding->report_source_connection_id,
            'report_import_job_id' => $job->id, 'report_dimension_id' => $dimension->id,
            'report_date' => $date, 'finality' => $finality, 'currency' => 'USD',
            'gross_revenue_minor' => $gross, 'net_revenue_minor' => $gross,
            'publisher_earnings_minor' => (int) ($gross * 0.7), 'horus_earnings_minor' => (int) ($gross * 0.3),
            'impressions' => $impressions, 'clicks' => 0, 'ad_requests' => $impressions, 'matched_requests' => $impressions,
            'active_view_viewable_impressions' => 0, 'active_view_measurable_impressions' => 0,
            'source_row_hash' => hash('sha256', (string) str()->ulid()),
        ]);
    }

    public function test_financial_service_defaults_stay_finalized_only_while_reports_can_opt_in(): void
    {
        $context = $this->context();
        $main = $this->binding($context);
        $video = $this->binding($context, true);
        foreach ([$main, $video] as $binding) {
            $this->fact($context, $binding, '2026-10-07', ReportFinality::Finalized, 10000);
            $this->fact($context, $binding, '2026-10-08', ReportFinality::Estimated, 5000);
        }
        $this->actingAs($context['admin']);
        $unified = app(UnifiedReportService::class);
        $websites = app(AdminWebsitePerformanceService::class);
        $default = $unified->adminSummary('2026-10-07', '2026-10-09');
        $this->assertSame(10000, $default['gross_revenue_minor']);
        $this->assertSame(10000, $default['video']['revenue_minor']);
        $this->assertFalse($default['video']['has_estimates']);
        $this->assertSame(20000, $default['financial_totals_including_video']['gross_revenue_minor']);
        $this->assertSame(7000, $unified->publisherSummary($context['publisher'], '2026-10-07', '2026-10-09')['revenue_minor']);
        $this->assertSame(10000, $websites->summary($context['site'], '2026-10-07', '2026-10-09')['gross_revenue_minor']);
        $this->assertSame(10000, $websites->summaries(collect([$context['site']]), '2026-10-07', '2026-10-09')[$context['site']->id]['gross_revenue_minor']);

        $visible = $unified->adminSummary('2026-10-07', '2026-10-09', 'USD', includeEstimates: true);
        $this->assertSame(15000, $visible['gross_revenue_minor']);
        $this->assertSame(15000, $visible['video']['revenue_minor']);
        $this->assertTrue($visible['video']['has_estimates']);
        $this->assertSame(30000, $visible['financial_totals_including_video']['gross_revenue_minor']);
        $this->assertSame(21000, $visible['financial_totals_including_video']['publisher_earnings_minor']);
        $this->assertSame(15000, $websites->summaries(collect([$context['site']]), '2026-10-07', '2026-10-09', includeEstimates: true)[$context['site']->id]['gross_revenue_minor']);
    }

    public function test_estimated_finalized_and_mixed_intervals_keep_literal_dates_and_finality_labels(): void
    {
        $context = $this->context();
        $binding = $this->binding($context);
        $this->fact($context, $binding, '2026-10-07', ReportFinality::Finalized, 10000, 1000);
        $this->fact($context, $binding, '2026-10-08', ReportFinality::Estimated, 5000, 500);
        $this->fact($context, $binding, '2026-10-09', ReportFinality::Estimated, 2000, 200);
        $this->actingAs($context['admin']);
        foreach ([['2026-10-07', '2026-10-07', 10000, false, 1],
            ['2026-10-08', '2026-10-08', 5000, true, 1],
            ['2026-10-07', '2026-10-09', 17000, true, 3]] as [$from, $to, $gross, $estimated, $days]) {
            $summary = app(AdminWebsitePerformanceService::class)->summary($context['site'], $from, $to, includeEstimates: true);
            $staff = app(UnifiedReportService::class)->adminSummary($from, $to, 'USD', includeEstimates: true);
            $publisher = app(PublisherPerformanceService::class)->summary($context['publisher'], $from, $to);
            $this->assertSame($gross, $summary['gross_revenue_minor']);
            $this->assertSame($gross, $staff['gross_revenue_minor']);
            $this->assertSame($estimated, $summary['has_estimates']);
            $this->assertSame($estimated, $staff['has_estimates']);
            $this->assertSame($estimated, $publisher['has_estimates']);
            $this->assertCount($days, $summary['days']);
            $this->assertCount($days, $staff['daily_revenue']);
            $this->assertSame((int) ($gross * 0.7), $publisher['earnings_minor']);
            $this->assertSame(7000, $publisher['ecpm_minor']);
            foreach ($summary['days'] as $day) {
                $this->assertSame($day['date'] !== '2026-10-07', $day['has_estimates']);
            }
        }
    }

    public function test_admin_pages_and_csv_opt_in_without_combining_main_and_video_twice(): void
    {
        $context = $this->context();
        $main = $this->binding($context);
        $video = $this->binding($context, true);
        $this->fact($context, $main, '2026-10-08', ReportFinality::Finalized, 10000);
        $this->fact($context, $main, '2026-10-09', ReportFinality::Estimated, 5000, 500);
        $this->fact($context, $video, '2026-10-09', ReportFinality::Estimated, 3000, 300);
        $period = ['from' => '2026-10-08', 'to' => '2026-10-09'];
        $this->actingAs($context['admin'])->withSession(['two_factor_passed_at' => now()->timestamp]);
        $response = $this->get(route('admin.reporting.index', $period))->assertOk()->assertSee('Includes estimates');
        $summary = $response->viewData('summary');
        $this->assertSame(15000, $summary['gross_revenue_minor']);
        $this->assertSame(3000, $summary['video']['revenue_minor']);
        $this->assertSame(18000, $summary['financial_totals_including_video']['gross_revenue_minor']);
        $response = $this->get(route('admin.reporting.websites.show', ['site' => $context['site'], ...$period]))->assertOk()->assertSee('Includes estimates');
        $this->assertSame(15000, $response->viewData('summary')['gross_revenue_minor']);
        $response->assertSee('Awaiting finalization');
        $directory = $this->get(route('admin.reporting.websites.index', $period))->assertOk();
        $this->assertSame(15000, $directory->viewData('totals')[$context['site']->id]['gross_revenue_minor']);
        foreach (['admin.reporting.index' => $period, 'admin.reporting.websites.show' => ['site' => $context['site'], ...$period]] as $route => $parameters) {
            $csv = $this->get(route($route, [...$parameters, 'metrics' => ['impressions'], 'export' => 'csv']))->assertOk()->streamedContent();
            $rows = $this->csvRows($csv);
            $this->assertSame(['Date', 'Impressions', 'Gross revenue (USD)', 'Includes estimates'], $rows[0]);
            $this->assertSame(['2026-10-08', '1000', '100.00', 'No'], $rows[1]);
            $this->assertSame(['2026-10-09', '500', '50.00', 'Yes'], $rows[2]);
            $videoCsv = $this->get(route($route, [...$parameters, 'export' => 'video_csv']))->assertOk()->streamedContent();
            $this->assertStringContainsString('2026-10-09', $videoCsv);
            $this->assertStringContainsString('Yes', $videoCsv);
        }
    }

    public function test_failed_pending_and_processing_import_facts_never_contribute_to_visible_reports(): void
    {
        $context = $this->context();
        $main = $this->binding($context);
        $video = $this->binding($context, true);
        foreach ([$main, $video] as $binding) {
            $this->fact($context, $binding, '2026-10-09', gross: 1000, impressions: 100);
            foreach ([ReportImportStatus::Failed, ReportImportStatus::Pending, ReportImportStatus::Processing, ReportImportStatus::Duplicate] as $status) {
                foreach ([ReportFinality::Estimated, ReportFinality::Finalized] as $finality) {
                    $this->fact($context, $binding, '2026-10-09', $finality, 90000, 9000, $status);
                }
            }
        }
        $mismatchedImport = $this->fact($context, $main, '2026-10-09', gross: 88000, impressions: 8800);
        $mismatchedImport->import->update(['report_source_connection_id' => $video->report_source_connection_id]);
        $this->actingAs($context['admin']);
        $staff = app(UnifiedReportService::class)->adminSummary('2026-10-09', '2026-10-09', 'USD', includeEstimates: true);
        $site = app(AdminWebsitePerformanceService::class)->summary($context['site'], '2026-10-09', '2026-10-09', includeEstimates: true);
        $publisher = app(PublisherPerformanceService::class)->summary($context['publisher'], '2026-10-09', '2026-10-09');
        $this->assertSame(1000, $staff['gross_revenue_minor']);
        $this->assertSame(2000, $staff['financial_totals_including_video']['gross_revenue_minor']);
        $this->assertSame(1000, $site['gross_revenue_minor']);
        $this->assertSame(1000, $site['video']['revenue_minor']);
        $this->assertSame(700, $publisher['earnings_minor']);
        $this->assertSame(700, $publisher['video']['revenue_minor']);
        $this->assertSame(100, $publisher['impressions']);
    }

    public function test_coverage_distinguishes_a_real_zero_from_a_missing_site_and_keeps_main_video_independent(): void
    {
        $context = $this->context();
        $main = $this->binding($context);
        $this->binding($context, true);
        $this->fact($context, $main, '2026-10-09', gross: 0, impressions: 0);
        $second = [...$context, 'site' => $this->makeSiteFor($context['publisher'], $context['user'], ['display_name' => 'Awaiting website'])];
        $this->binding($second);
        $secondVideo = $this->binding($second, true);
        $this->fact($second, $secondVideo, '2026-10-09', gross: 0, impressions: 0);
        $this->actingAs($context['user']);
        $coverage = app(ReportCoverageService::class)->forPeriod('2026-10-09', '2026-10-09', 'USD', $context['publisher']);
        foreach (['main', 'video'] as $section) {
            $this->assertSame(2, $coverage[$section]['expected_count']);
            $this->assertSame(1, $coverage[$section]['reported_count']);
            $this->assertSame(1, $coverage[$section]['missing_count']);
            $this->assertCount(1, $coverage[$section]['pending_sites']);
        }
        $this->assertSame('Awaiting website', collect($coverage['main']['pending_sites'])->sole()['label']);
        $this->assertSame('Visible website', collect($coverage['video']['pending_sites'])->sole()['label']);
        $performance = app(PublisherPerformanceService::class)->summary($context['publisher'], '2026-10-09', '2026-10-09');
        $this->assertTrue($performance['available']);
        $this->assertSame(0, $performance['earnings_minor']);
        $this->assertSame(0, $performance['impressions']);
        $this->assertNull($performance['ecpm_minor']);
        $period = ['from' => '2026-10-09', 'to' => '2026-10-09'];
        foreach (['publisher.reporting.index', 'admin.reporting.index'] as $route) {
            $this->actingAs($route === 'publisher.reporting.index' ? $context['user'] : $context['admin'])
                ->withSession(['two_factor_passed_at' => now()->timestamp]);
            $response = $this->get(route($route, $period))->assertOk();
            $xpath = $this->xpath($response->getContent());
            $mainNotice = $xpath->query('//aside[@aria-label="Main website reporting coverage"]')->item(0);
            $videoNotice = $xpath->query('//aside[@aria-label="Video website reporting coverage"]')->item(0);
            $this->assertNotNull($mainNotice);
            $this->assertNotNull($videoNotice);
            $this->assertStringContainsString('is partial', $mainNotice->textContent);
            $this->assertStringContainsString('Awaiting website', $mainNotice->textContent);
            $this->assertStringNotContainsString('Visible website', $mainNotice->textContent);
            $this->assertStringContainsString('is partial', $videoNotice->textContent);
            $this->assertStringContainsString('Visible website', $videoNotice->textContent);
        }
        $this->actingAs($context['user']);
        $scoped = app(ReportCoverageService::class)->forPeriod('2026-10-09', '2026-10-09', 'USD', null, [$context['site']->id]);
        // A publisher cannot request the staff-wide coverage path, even with site IDs.
        $this->assertSame(0, $scoped['main']['expected_count']);
        $this->actingAs($context['admin']);
        $scoped = app(ReportCoverageService::class)->forPeriod('2026-10-09', '2026-10-09', 'USD', null, [$context['site']->id]);
        $this->assertSame(1, $scoped['main']['expected_count']);
        $this->assertSame(1, $scoped['main']['reported_count']);
        $this->assertSame(1, $scoped['video']['missing_count']);
    }

    public function test_empty_and_completed_zero_reports_have_distinct_rendered_states(): void
    {
        $context = $this->context();
        $binding = $this->binding($context);
        $period = ['from' => '2026-10-09', 'to' => '2026-10-09'];
        $this->actingAs($context['admin'])->withSession(['two_factor_passed_at' => now()->timestamp]);
        $empty = $this->get(route('admin.reporting.index', $period))->assertOk()->assertSee('No reports for these dates');
        $values = $this->xpath($empty->getContent())->query('//section[@aria-label="Performance totals"]//div[contains(@class,"report-kpi-value")]');
        $this->assertCount(4, $values);
        foreach ($values as $value) {
            $this->assertSame('Unavailable', trim($value->textContent));
        }
        $emptySite = $this->get(route('admin.reporting.websites.show', ['site' => $context['site'], ...$period]))
            ->assertOk()->assertSee('Awaiting imported data')->assertSee('No reports for these dates');
        $this->assertSame(0, $this->xpath($emptySite->getContent())->query('//section[@aria-label="Website revenue totals"]')->length);
        $this->actingAs($context['user']);
        $emptyPublisher = $this->get(route('publisher.reporting.index', $period))->assertOk()->assertSee('No reports for these dates yet');
        $this->assertSame(0, $this->xpath($emptyPublisher->getContent())->query('//p[@class="publisher-earnings-value"]')->length);

        $this->fact($context, $binding, '2026-10-09', gross: 0, impressions: 0);
        $publisherZero = $this->get(route('publisher.reporting.index', $period))->assertOk()->assertSee('Includes estimates');
        $earnings = $this->xpath($publisherZero->getContent())->query('//p[@class="publisher-earnings-value"]')->item(0);
        $this->assertNotNull($earnings);
        $this->assertStringContainsString('0.00', $earnings->textContent);
        $this->actingAs($context['admin'])->withSession(['two_factor_passed_at' => now()->timestamp]);
        $zero = $this->get(route('admin.reporting.index', $period))->assertOk()->assertDontSee('No reports for these dates');
        $this->assertStringContainsString('0.00', $this->xpath($zero->getContent())
            ->query('//section[@aria-label="Performance totals"]//div[contains(@class,"report-kpi-value")]')->item(0)->textContent);
        $zeroSite = $this->get(route('admin.reporting.websites.show', ['site' => $context['site'], ...$period]))->assertOk();
        $this->assertSame(1, $this->xpath($zeroSite->getContent())->query('//section[@aria-label="Website revenue totals"]')->length);
    }

    public function test_coverage_requires_exact_site_source_date_currency_and_completed_import_not_source_health(): void
    {
        $context = $this->context();
        $binding = $this->binding($context);
        $this->fact($context, $binding, '2026-10-08');
        $failed = $this->fact($context, $binding, '2026-10-09', status: ReportImportStatus::Failed);
        $foreignCurrency = $this->fact($context, $binding, '2026-10-09');
        $foreignCurrency->update(['currency' => 'EUR']);
        $second = [...$context, 'site' => $this->makeSiteFor($context['publisher'], $context['user'])];
        $otherSource = $this->binding($second);
        // A completed row at the wrong source or site cannot fill this binding's gap.
        $this->fact($context, $otherSource, '2026-10-09');
        $this->fact($second, $binding, '2026-10-09');
        $binding->connection->update(['last_successful_import_at' => now(), 'last_error' => 'Private Google response']);
        $this->actingAs($context['user']);
        $coverage = app(ReportCoverageService::class)->forPeriod('2026-10-08', '2026-10-09', 'USD', $context['site']);
        $this->assertSame(1, $coverage['main']['expected_count']);
        $this->assertSame(0, $coverage['main']['reported_count']);
        $this->assertSame('2026-10-09', collect($coverage['main']['pending_sites'])->sole()['date']);
        $serialized = json_encode($coverage, JSON_THROW_ON_ERROR);
        foreach (['Private Google response', 'Private Google unit', 'Private source name', $binding->report_source_connection_id] as $private) {
            $this->assertStringNotContainsString($private, $serialized);
        }
        $failed->import->update(['status' => ReportImportStatus::Completed]);
        $this->assertSame(1, app(ReportCoverageService::class)->forPeriod('2026-10-09', '2026-10-09', 'USD', $context['site'])['main']['reported_count']);
    }

    public function test_publisher_rows_and_coverage_do_not_leak_another_tenant_or_mismatched_dimension_organization(): void
    {
        $context = $this->context();
        $binding = $this->binding($context);
        $valid = $this->fact($context, $binding, '2026-10-09', gross: 1000, impressions: 100);
        $otherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $otherPublisher = $this->makePublisherFor($otherUser);
        $other = [...$context, 'user' => $otherUser, 'publisher' => $otherPublisher,
            'site' => $this->makeSiteFor($otherPublisher, $otherUser, ['display_name' => 'PRIVATE OTHER TENANT'])];
        $otherBinding = $this->binding($other);
        $this->fact($other, $otherBinding, '2026-10-09', gross: 9999900);
        $forged = $this->fact($context, $binding, '2026-10-09', gross: 8888800);
        $forged->dimension->update(['organization_id' => $otherPublisher->organization_id]);
        $this->actingAs($context['user']);
        $performance = app(PublisherPerformanceService::class)->summary($context['publisher'], '2026-10-09', '2026-10-09');
        $this->assertSame(700, $performance['earnings_minor']);
        $this->assertArrayNotHasKey('gross_revenue_minor', $performance);
        $coverage = app(ReportCoverageService::class)->forPeriod('2026-10-09', '2026-10-09', 'USD', $context['publisher']);
        $this->assertSame(1, $coverage['main']['expected_count']);
        $this->assertStringNotContainsString('PRIVATE OTHER TENANT', json_encode($coverage, JSON_THROW_ON_ERROR));
        $response = $this->get(route('publisher.reporting.index', ['from' => '2026-10-09', 'to' => '2026-10-09']))->assertOk()
            ->assertDontSee('PRIVATE OTHER TENANT')->assertDontSee('Gross revenue')->assertDontSee('Horus margin');
        $this->assertSame(700, $response->viewData('performance')['earnings_minor']);
        $valid->import->update(['status' => ReportImportStatus::Failed]);
        $coverage = app(ReportCoverageService::class)->forPeriod('2026-10-09', '2026-10-09', 'USD', $context['publisher']);
        $this->assertSame(0, $coverage['main']['reported_count']);
        $this->assertSame(1, $coverage['main']['missing_count']);
    }

    public function test_dubai_rollover_does_not_hide_the_selected_previous_day_estimate(): void
    {
        $context = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 21:00:00', 'UTC'));
        $this->assertSame('2026-10-09', now()->toDateString());
        $this->assertSame('2026-10-10', now('Asia/Dubai')->toDateString());
        foreach ([$this->binding($context, false, 'Asia/Dubai'), $this->binding($context, true, 'Asia/Dubai')] as $binding) {
            $this->fact($context, $binding, '2026-10-09', gross: 1000, impressions: 100);
            $this->fact($context, $binding, '2026-10-10', gross: 9000, impressions: 900);
        }
        $this->actingAs($context['admin'])->withSession(['two_factor_passed_at' => now()->timestamp]);
        $period = ['from' => '2026-10-09', 'to' => '2026-10-09'];
        $response = $this->get(route('admin.reporting.websites.show', ['site' => $context['site'], ...$period]))->assertOk()->assertSee('Includes estimates');
        $summary = $response->viewData('summary');
        $this->assertSame(1000, $summary['gross_revenue_minor']);
        $this->assertSame(1000, $summary['video']['revenue_minor']);
        $this->assertSame('2026-10-09', $summary['days']->sole()['date']);
        $this->assertTrue($summary['has_estimates']);
        $this->assertSame(1, app(ReportCoverageService::class)->forPeriod('2026-10-09', '2026-10-09', 'USD', $context['site'])['main']['reported_count']);
        $this->actingAs($context['user']);
        $publisher = $this->get(route('publisher.reporting.index', $period))->assertOk()->assertSee('Includes estimates')->viewData('performance');
        $this->assertSame(700, $publisher['earnings_minor']);
        $this->assertSame(700, $publisher['video']['revenue_minor']);
    }

    public function test_western_source_uses_its_local_latest_day_and_marks_future_selection_not_started(): void
    {
        $context = $this->context();
        $this->travelTo(CarbonImmutable::parse('2026-10-09 01:00:00', 'UTC'));
        $binding = $this->binding($context, false, 'America/Los_Angeles');
        $this->fact($context, $binding, '2026-10-08', gross: 5000, impressions: 500);
        $this->actingAs($context['user']);
        $this->assertSame('2026-10-08', now('America/Los_Angeles')->toDateString());
        $service = app(ReportCoverageService::class);
        $range = $service->forPeriod('2026-10-08', '2026-10-09', 'USD', $context['publisher']);
        $this->assertSame(1, $range['main']['expected_count']);
        $this->assertSame(1, $range['main']['reported_count']);
        $future = $service->forPeriod('2026-10-09', '2026-10-09', 'USD', $context['publisher']);
        $pending = collect($future['main']['pending_sites'])->sole();
        $this->assertSame('not_started', $pending['state']);
        $this->assertSame('2026-10-09', $pending['date']);
        $this->assertSame('America/Los_Angeles', $pending['timezone']);
        $summary = app(PublisherPerformanceService::class)->summary($context['publisher'], '2026-10-09', '2026-10-09');
        $this->assertFalse($summary['available']);
        $this->assertSame(0, $summary['earnings_minor']);
        $previous = app(PublisherPerformanceService::class)->summary($context['publisher'], '2026-10-08', '2026-10-08');
        $this->assertSame(3500, $previous['earnings_minor']);
        $this->assertTrue($previous['has_estimates']);
    }

    public function test_unconfigured_disabled_and_cancelled_sources_make_no_coverage_claim(): void
    {
        $context = $this->context();
        $this->actingAs($context['user']);
        $service = app(ReportCoverageService::class);
        $empty = $service->forPeriod('2026-10-09', '2026-10-09', 'USD', $context['publisher']);
        foreach (['main', 'video'] as $section) {
            $this->assertSame(0, $empty[$section]['expected_count']);
            $this->assertSame(0, $empty[$section]['missing_count']);
            $this->assertCount(0, $empty[$section]['pending_sites']);
        }
        $main = $this->binding($context);
        $main->connection->update(['is_enabled' => false, 'status' => 'DISABLED']);
        $video = $this->binding($context, true);
        $video->update(['cancelled_at' => now()]);
        $inactive = $service->forPeriod('2026-10-09', '2026-10-09', 'USD', $context['publisher']);
        $this->assertSame(0, $inactive['main']['expected_count']);
        $this->assertSame(0, $inactive['video']['expected_count']);
    }

    public function test_disabled_shared_gam_accounts_make_no_active_coverage_claim_for_admin_or_publisher(): void
    {
        $context = $this->context();
        $this->binding($context);
        $this->binding($context, true);
        $this->assertNotSame($context['publisher']->organization_id, $context['gam']->organization_id);
        $service = app(ReportCoverageService::class);

        foreach ([true, false, true] as $enabled) {
            $context['gam']->update(['is_enabled' => $enabled]);
            foreach ([$context['user'], $context['admin']] as $actor) {
                $this->actingAs($actor);
                $owner = $actor->id === $context['user']->id ? $context['publisher'] : null;
                $coverage = $service->forPeriod('2026-10-09', '2026-10-09', 'USD', $owner);
                foreach (['main', 'video'] as $channel) {
                    $this->assertSame($enabled ? 1 : 0, $coverage[$channel]['expected_count']);
                    $this->assertSame($enabled ? 1 : 0, $coverage[$channel]['missing_count']);
                    $this->assertCount($enabled ? 1 : 0, $coverage[$channel]['pending_sites']);
                }
            }
        }

        $context['gam']->delete();
        foreach ([$context['user'], $context['admin']] as $actor) {
            $this->actingAs($actor);
            $owner = $actor->id === $context['user']->id ? $context['publisher'] : null;
            $coverage = $service->forPeriod('2026-10-09', '2026-10-09', 'USD', $owner);
            $this->assertSame(0, $coverage['main']['expected_count']);
            $this->assertSame(0, $coverage['video']['expected_count']);
        }
    }

    public function test_estimates_preserve_all_unit_unfilled_deduplication_and_all_report_reads_are_read_only(): void
    {
        $context = $this->context();
        foreach ([$this->binding($context), $this->binding($context, true)] as $binding) {
            $this->fact($context, $binding, '2026-10-09', gross: 1000, impressions: 100);
            $this->fact($context, $binding, '2026-10-09', gross: 2000, impressions: 200);
            $video = $binding instanceof SiteGamVideoReportBinding;
            $class = $video ? SiteGamVideoUnfilledReport::class : SiteGamUnfilledReport::class;
            $class::withoutGlobalScopes()->create([
                'organization_id' => $context['publisher']->organization_id,
                'report_source_connection_id' => $binding->report_source_connection_id,
                ($video ? 'site_gam_video_report_binding_id' : 'site_gam_report_binding_id') => $binding->id,
                'gam_connection_id' => $binding->gam_connection_id, 'network_code' => $binding->network_code,
                'ad_unit_id' => $binding->ad_unit_id, 'report_date' => '2026-10-09', 'timezone' => 'UTC',
                'unfilled_impressions' => $video ? 71 : 53, 'google_report_job_id' => 'fixture', 'reported_at' => now(),
            ]);
        }
        $tables = ['daily_reports', 'monthly_reports', 'report_dimensions', 'report_import_jobs', 'report_source_connections',
            'site_gam_report_bindings', 'site_gam_video_report_bindings', 'site_gam_unfilled_reports', 'site_gam_video_unfilled_reports',
            'financial_periods', 'publisher_statements', 'publisher_payments', 'revenue_adjustments', 'revenue_rules', 'revenue_rule_versions'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()])->all();
        $before = $snapshot();
        $this->actingAs($context['admin'])->withSession(['two_factor_passed_at' => now()->timestamp]);
        $staff = app(UnifiedReportService::class)->adminSummary('2026-10-09', '2026-10-09', 'USD', includeEstimates: true);
        $site = app(AdminWebsitePerformanceService::class)->summary($context['site'], '2026-10-09', '2026-10-09', includeEstimates: true);
        $this->assertSame(53, $staff['performance']['unfilled_impressions']);
        $this->assertSame(71, $staff['video']['unfilled_impressions']);
        $this->assertSame(53, $site['unfilled_impressions']);
        $this->assertSame(71, $site['video']['unfilled_impressions']);
        $this->assertSame(6000, $staff['financial_totals_including_video']['gross_revenue_minor']);
        $period = ['from' => '2026-10-09', 'to' => '2026-10-09'];
        foreach (['admin.reporting.index' => $period, 'admin.reporting.websites.show' => ['site' => $context['site'], ...$period]] as $route => $parameters) {
            $this->get(route($route, $parameters))->assertOk();
            $this->get(route($route, [...$parameters, 'export' => 'csv']))->assertOk()->streamedContent();
        }
        $this->actingAs($context['user']);
        $publisher = app(PublisherPerformanceService::class)->summary($context['publisher'], ...array_values($period));
        $this->assertSame(53, $publisher['unfilled_impressions']);
        $this->assertSame(71, $publisher['video']['unfilled_impressions']);
        $this->assertSame(7000, $publisher['ecpm_minor']);
        $this->assertSame(7000, $publisher['video']['ecpm_minor']);
        $this->get(route('publisher.reporting.index', $period))->assertOk();
        app(ReportCoverageService::class)->forPeriod('2026-10-09', '2026-10-09', 'USD', $context['publisher']);
        $this->assertSame($before, $snapshot());
    }

    private function xpath(string $html): \DOMXPath
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);

        return new \DOMXPath($document);
    }

    private function csvRows(string $csv): array
    {
        return array_map(fn ($line) => str_getcsv($line, escape: ''), array_values(array_filter(explode("\n", trim($csv)))));
    }
}
