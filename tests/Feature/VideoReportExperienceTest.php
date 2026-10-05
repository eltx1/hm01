<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, ReportFinality, ReportGranularity, ReportImportStatus, ReportSourceCode, RoleName};
use App\Models\{DailyReport, ReportSource, ReportSourceConnection, SiteGamVideoReportBinding, SiteGamVideoUnfilledReport};
use App\Services\Reporting\{PublisherPerformanceService, ReportImportService, SiteGamReportMetrics, VideoPerformanceService};
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\{InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class VideoReportExperienceTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user);
        $site->update(['display_name' => 'Natega · Synthetic preview', 'primary_domain' => 'natega.example.test']);
        return [$admin, $user, $publisher, $site];
    }

    private function fact(array $context, string $date, int $gross, int $impressions, bool $video = true, ReportFinality $finality = ReportFinality::Finalized): void
    {
        [$admin, , $publisher, $site] = $context;
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'report_source_id' => ReportSource::where('code', ReportSourceCode::HorusGam->value)->firstOrFail()->id,
            'name' => 'Synthetic report', 'connection_type' => 'TEST', 'connection_id' => (string) str()->ulid(),
            'currency' => 'USD', 'timezone' => 'Africa/Cairo', 'status' => 'ACTIVE', 'is_enabled' => true,
        ]);
        $day = CarbonImmutable::parse($date);
        $job = app(ReportImportService::class)->importRows($connection, [[
            'date' => $date, 'site_id' => $site->id, 'publisher_id' => $publisher->id,
            'currency' => 'USD', 'gross_revenue_minor' => $gross, 'impressions' => $impressions, 'clicks' => 1,
        ]], ReportGranularity::Daily, $finality, $day, $day, $admin);
        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');
        if ($video) {
            $connection->update(['connection_type' => 'SITE_GAM_VIDEO_AD_UNIT']);
            $row = DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $connection->id)->sole();
            $row->dimension->update(['external_dimensions' => [
                'gam_report_basis' => SiteGamReportMetrics::BASIS, 'gam_report_site' => $site->primary_domain,
                'gam_ad_unit_id' => 'fixture-'.$site->id, 'gam_report_scope' => 'EXACT_SITE_V1',
            ]]);
        }
    }

    public function test_real_report_routes_render_complete_separate_video_and_safe_exports(): void
    {
        $context = [$admin, $user, $publisher, $site] = $this->context();
        $this->fact($context, '2026-09-18', 10000, 1000, false);
        $this->fact($context, '2026-09-18', 20000, 500);
        $this->fact($context, '2026-09-20', 5000, 1000);
        $otherSite = $this->makeSiteFor($publisher, $user);
        $otherSite->update(['display_name' => 'Second synthetic website', 'primary_domain' => 'second.example.test']);
        $this->fact([$admin, $user, $publisher, $otherSite], '2026-09-20', 10000, 2000);
        $this->fact($context, '2026-09-21', 1000, 100, true, ReportFinality::Estimated);
        $period = ['from' => '2026-09-18', 'to' => '2026-09-21'];
        $this->actingAs($user);
        $response = $this->get(route('publisher.reporting.index', $period))->assertOk()
            ->assertSee('Main performance')->assertSee('Total reported earnings')->assertSee('Video earnings over time')
            ->assertSee('Video daily breakdown')->assertSee('Video website breakdown')->assertSee('Video last updated:')
            ->assertDontSee('Independent Video results')->assertDontSee('After your revenue share')->assertSee('natega.example.test')
            ->assertDontSee('Video gross revenue')->assertDontSee('Horus margin')->assertDontSee('Publisher earnings (USD)');
        $this->fixture('publisher-reports', $response->getContent());
        $performance = app(PublisherPerformanceService::class)->summary($publisher, ...array_values($period));
        $this->assertSame(25200, $performance['video']['revenue_minor']);
        $this->assertSame(7000, $performance['video']['ecpm_minor']);
        $this->assertCount(3, $performance['video']['days']);
        $this->assertCount(2, $performance['video']['websites']);
        $this->assertNull($performance['video']['unfilled_impressions']);
        $csv = $this->get(route('publisher.reporting.index', [...$period, 'export' => 'video_csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('2026-09-21', $csv);
        $this->assertStringContainsString('Yes', $csv);
        $this->assertStringNotContainsString('2026-09-19', $csv);
        $this->assertStringNotContainsString('gross', $csv);
        $this->fixture('publisher-before', $this->before($performance['video'], true));
        $finance = $this->get(route('publisher.finance.overview', $period))->assertOk()->assertSee('Video website breakdown');
        $this->fixture('publisher-finance', $finance->getContent());

        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $response = $this->get(route('admin.reporting.index', $period))->assertOk()
            ->assertSee('Combined financial totals')->assertSee('Video revenue over time')->assertSee('Video Horus margin');
        $this->fixture('admin-reports', $response->getContent());
        $response = $this->get(route('admin.reporting.websites.show', [$site, ...$period]))->assertOk()
            ->assertSee('Video daily breakdown')->assertDontSee('second.example.test');
        $this->fixture('admin-website', $response->getContent());
        $video = app(\App\Services\Reporting\AdminWebsitePerformanceService::class)->summary($site, ...array_values($period))['video'];
        $this->fixture('admin-before', $this->before($video, false));
        $csv = $this->get(route('admin.reporting.websites.show', [$site, ...$period, 'export' => 'video_csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Video Horus margin', $csv);
        $this->assertStringNotContainsString('2026-09-21', $csv);
        $filtered = $this->get(route('publisher.reporting.index', ['from' => '2026-09-20', 'to' => '2026-09-20']));
        // No publisher belongs to the staff organization; the scoped lookup must not expose another tenant.
        $filtered->assertNotFound();
        $this->actingAs($user);
        $filtered = $this->get(route('publisher.reporting.index', ['from' => '2026-09-20', 'to' => '2026-09-20', 'export' => 'video_csv']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('2026-09-18', $filtered);
        $this->assertStringContainsString('105.00', $filtered);
    }

    public function test_video_only_zero_and_unavailable_states_are_distinct(): void
    {
        $context = [$admin, $user, $publisher, $site] = $this->context();
        $this->fact($context, '2026-09-20', 0, 0);
        $this->actingAs($user);
        $response = $this->get(route('publisher.reporting.index'))->assertOk()
            ->assertSee('No main reports for these dates')->assertSee('Video daily breakdown')->assertSee('0.00');
        $video = app(PublisherPerformanceService::class)->summary($publisher, '2026-09-01', '2026-09-21')['video'];
        $this->assertSame(0, $video['impressions']);
        $this->assertSame(0, $video['revenue_minor']);
        $this->assertNull($video['ecpm_minor']);
        $this->fixture('publisher-zero', $response->getContent());
        $empty = app(VideoPerformanceService::class)->summary(collect(), true, 'USD', ['configured' => true, 'timezones' => ['Africa/Cairo']]);
        $this->fixture('publisher-pending', $this->renderVideoFixture($empty));
        $this->fixture('publisher-failed', $this->renderVideoFixture([...$empty, 'source_health' => 'failed', 'has_configuration' => true]));
        $this->fixture('publisher-disabled', $this->renderVideoFixture([...$video, 'configured' => false, 'has_configuration' => true, 'configuration_state' => 'disabled']));
    }

    public function test_real_video_sidecar_zero_and_positive_counters_reach_the_report_and_csv(): void
    {
        $context = [$admin, $user, $publisher, $site] = $this->context();
        // Financial imports precede the synthetic binding setup; projection is real.
        $this->fact($context, '2026-09-19', 0, 0);
        $this->fact($context, '2026-09-20', 5000, 1000);
        $gam = $this->makeGamConnection($admin->organization, $admin);
        foreach (DailyReport::withoutGlobalScopes()->with(['connection', 'dimension'])->get() as $row) {
            $date = $row->report_date->toDateString();
            $binding = SiteGamVideoReportBinding::withoutGlobalScopes()->create([
                'organization_id' => $publisher->organization_id, 'site_id' => $site->id,
                'gam_connection_id' => $gam->id, 'report_source_connection_id' => $row->connection->id,
                'network_code' => $gam->network_code, 'ad_unit_id' => '80412',
                'ad_unit_name' => 'Synthetic Video unit', 'ad_unit_code' => 'synthetic-video',
                'starts_on' => $date, 'ends_on' => $date === '2026-09-19' ? $date : null,
                'active_site_id' => $date === '2026-09-20' ? $site->id : null, 'created_by' => $admin->id,
            ]);
            $row->connection->update(['connection_id' => $binding->id, 'last_successful_import_at' => now()]);
            $row->dimension->update(['gam_connection_id' => $gam->id, 'external_dimensions' => array_replace($row->dimension->external_dimensions, ['gam_ad_unit_id' => '80412'])]);
            SiteGamVideoUnfilledReport::withoutGlobalScopes()->create([
                'organization_id' => $publisher->organization_id, 'report_source_connection_id' => $row->connection->id,
                'site_gam_video_report_binding_id' => $binding->id, 'gam_connection_id' => $gam->id,
                'network_code' => $gam->network_code, 'ad_unit_id' => '80412', 'report_date' => $date,
                'timezone' => 'Africa/Cairo', 'unfilled_impressions' => $date === '2026-09-19' ? 0 : 57,
                'google_report_job_id' => 'synthetic-job', 'reported_at' => now(),
            ]);
        }
        $this->actingAs($user);
        $period = ['from' => '2026-09-19', 'to' => '2026-09-20'];
        $video = app(PublisherPerformanceService::class)->summary($publisher, ...array_values($period))['video'];
        $this->assertSame(57, $video['unfilled_impressions']);
        $this->assertSame([0, 57], $video['days']->pluck('unfilled_impressions')->all());
        $this->assertSame(57, $video['websites']->sole()['unfilled_impressions']);
        $response = $this->get(route('publisher.reporting.index', $period))->assertOk()->assertSee('Video daily breakdown');
        $this->fixture('publisher-counts', $response->getContent());
        $csv = $this->get(route('publisher.reporting.index', [...$period, 'export' => 'video_csv']))->assertOk()->streamedContent();
        $csvRows = array_map(fn ($line) => str_getcsv($line, escape: ''), array_filter(explode("\n", trim($csv))));
        $this->assertSame('0', $csvRows[1][3]);
        $this->assertSame('57', $csvRows[2][3]);
        $this->assertSame('0.00', $csvRows[1][4]);
        $this->assertSame('', $csvRows[1][5]);
    }

    private function renderVideoFixture(array $video): string
    {
        return Blade::render('@extends("layouts.admin") @section("content")<div class="reports-page"><x-video-performance :video="$video" :publisher="true" from="2026-09-18" to="2026-09-21" /></div>@endsection', ['video' => $video]);
    }

    private function before(array $video, bool $publisher): string
    {
        if (getenv('HORUS_UI_FIXTURES') !== '1') return '';
        return Blade::render('@extends("layouts.admin") @section("content")<div class="reports-page">'.file_get_contents(base_path('tests/Fixtures/views/video-performance-before.blade.php')).'</div>@endsection', compact('video', 'publisher'));
    }

    private function fixture(string $name, string $html): void
    {
        if (getenv('HORUS_UI_FIXTURES') !== '1') return;
        $directory = storage_path('framework/testing/video-reporting');
        if (! is_dir($directory)) mkdir($directory, 0755, true);
        $html = str_replace('</head>', '<link rel="stylesheet" href="/fixture.css"></head>', $html);
        $html = str_replace('</body>', '<script type="module" src="/fixture.js"></script></body>', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }
}
