<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, ReportFinality, ReportGranularity, ReportSourceCode, ReportImportStatus, RoleName};
use App\Models\{DailyReport, ReportSource, ReportSourceConnection};
use App\Services\Reporting\{AdminWebsitePerformanceService, PublisherPerformanceService, ReportImportService, SiteGamReportMetrics, UnifiedReportService};
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class VideoPerformanceReportingTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-21 12:00:00'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user);
        return [$admin, $user, $publisher, $site];
    }

    private function fact(array $context, bool $video, int $gross, int $impressions): void
    {
        [$admin, , $publisher, $site] = $context;
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $publisher->organization_id,
            'report_source_id' => ReportSource::where('code', ReportSourceCode::HorusGam->value)->firstOrFail()->id,
            'name' => $video ? 'Video test' : 'Main test', 'connection_type' => 'TEST',
            'connection_id' => (string) str()->ulid(), 'currency' => 'USD', 'timezone' => 'UTC',
            'status' => 'ACTIVE', 'is_enabled' => true,
        ]);
        $day = CarbonImmutable::parse('2026-09-20');
        $job = app(ReportImportService::class)->importRows($connection, [[
            'date' => '2026-09-20', 'site_id' => $site->id, 'publisher_id' => $publisher->id,
            'currency' => 'USD', 'gross_revenue_minor' => $gross, 'impressions' => $impressions, 'clicks' => 1,
        ]], ReportGranularity::Daily, ReportFinality::Finalized, $day, $day, $admin);
        $this->assertSame(ReportImportStatus::Completed, $job->status, $job->error_message ?? '');
        if ($video) {
            // Projection fixture: import authorization/binding is covered by the binding suite.
            $connection->update(['connection_type' => 'SITE_GAM_VIDEO_AD_UNIT']);
            $row = DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $connection->id)->sole();
            $row->dimension->update(['external_dimensions' => [
                'gam_report_basis' => SiteGamReportMetrics::BASIS, 'gam_report_site' => $site->primary_domain,
                'gam_ad_unit_id' => 'video-123', 'gam_report_scope' => 'EXACT_SITE_V1',
            ]]);
        }
    }

    public function test_video_is_separate_and_publisher_payload_and_csv_are_net_only(): void
    {
        $context = [$admin, $user, $publisher, $site] = $this->context();
        $this->fact($context, false, 10000, 1000);
        $this->fact($context, true, 20000, 500);
        $summary = app(PublisherPerformanceService::class)->summary($publisher, '2026-09-01', '2026-09-21');
        $this->assertSame(1000, $summary['impressions']);
        $this->assertSame(7000, $summary['earnings_minor']);
        $this->assertSame(500, $summary['video']['impressions']);
        $this->assertSame(14000, $summary['video']['revenue_minor']);
        $this->assertSame(28000, $summary['video']['ecpm_minor']);
        foreach (['gross_revenue_minor', 'horus_earnings_minor', 'publisher_earnings_minor'] as $key) {
            $this->assertStringNotContainsString($key, json_encode($summary['video']));
        }
        $this->actingAs($user);
        $this->get(route('publisher.reporting.index'))->assertOk()->assertSee('Video performance')->assertSee('140.00')->assertDontSee('Video gross revenue')->assertDontSee('Video Horus margin');
        $csv = $this->get(route('publisher.reporting.index', ['export' => 'video_csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Video publisher earnings', $csv);
        $this->assertStringContainsString('ad unit, all sites', $csv);
        $this->assertStringContainsString('140.00', $csv);
        $this->assertStringContainsString('280.00', $csv);
        $this->assertStringNotContainsString('gross', $csv);
        $this->assertStringNotContainsString('Horus', $csv);
        $main = $this->get(route('publisher.reporting.index', ['export' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('70.00', $main);
        $this->assertStringNotContainsString('140.00', $main);
        $staff = app(UnifiedReportService::class)->adminSummary('2026-09-01', '2026-09-21');
        $this->assertSame(1000, $staff['managed_impressions']);
        $this->assertSame(7000, $staff['publisher_earnings_minor']);
        $this->assertSame(21000, $staff['financial_totals_including_video']['publisher_earnings_minor']);
        $publisherCard = app(UnifiedReportService::class)->publisherSummary($publisher, '2026-09-01', '2026-09-21');
        $this->assertSame(1000, $publisherCard['impressions']);
        $this->assertSame(7000, $publisherCard['revenue_minor']);
        $this->assertSame(14000, $publisherCard['video']['revenue_minor']);
        $this->assertSame(20000, $staff['video']['gross_revenue_minor']);
        $this->assertSame(6000, $staff['video']['horus_earnings_minor']);
        $website = app(AdminWebsitePerformanceService::class)->summary($site, '2026-09-01', '2026-09-21');
        $this->assertSame(1000, $website['impressions']);
        $this->assertSame(500, $website['video']['impressions']);
    }

    public function test_video_is_tenant_scoped_and_management_remains_staff_only(): void
    {
        $context = [$admin, $user, $publisher, $site] = $this->context();
        $this->fact($context, true, 20000, 500);
        $otherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $other = $this->makePublisherFor($otherUser);
        $otherSite = $this->makeSiteFor($other, $otherUser);
        $this->fact([$admin, $otherUser, $other, $otherSite], true, 99999900, 999999);
        $this->actingAs($user);
        $csv = $this->get(route('publisher.reporting.index', ['export' => 'video_csv']))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('999999', $csv);
        $this->post(route('admin.sites.reporting.video.store', $site), [])->assertForbidden();
        $this->delete(route('admin.sites.reporting.video.destroy', $site))->assertForbidden();
        $this->get(route('admin.reporting.websites.show', [$site, 'export' => 'video_csv']))->assertForbidden();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->get(route('admin.sites.show', $site))->assertOk()->assertSee('Video reporting')->assertSee('Optional, off by default');
        $csv = $this->get(route('admin.reporting.websites.show', [$site, 'export' => 'video_csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Video gross revenue', $csv);
        $this->assertStringContainsString('Video Horus margin', $csv);
        $this->assertStringNotContainsString('999999', $csv);
    }
    public function test_unconfigured_reports_hide_video_and_pending_configuration_explains_source_clocks(): void
    {
        $this->withoutExceptionHandling();
        [$admin, $user, $publisher, $site] = $this->context();
        $this->actingAs($user);
        $this->get(route('publisher.reporting.index'))->assertOk()->assertDontSee('Video performance')->assertDontSee('Export Video CSV');
        $this->get(route('publisher.finance.overview'))->assertOk()->assertDontSee('Video performance');
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        $this->get(route('admin.reporting.index'))->assertOk()->assertDontSee('Video performance');
        $this->get(route('admin.reporting.websites.show', $site))->assertOk()->assertDontSee('Video performance');
        $video = app(\App\Services\Reporting\VideoPerformanceService::class)->summary(collect(), true, 'USD', [
            'configured' => true, 'timezones' => ['Africa/Cairo', 'America/New_York'],
        ]);
        $html = \Illuminate\Support\Facades\Blade::render('<x-video-performance :video="$video" :publisher="true" />', ['video' => $video]);
        $this->assertStringContainsString('Awaiting Video data', $html);
        $this->assertStringContainsString('Multiple source clocks', $html);
        $this->assertStringContainsString('Africa/Cairo', $html);
        $this->assertStringContainsString('America/New_York', $html);
        $this->assertStringNotContainsString('Export Video CSV', $html);
        $this->assertStringNotContainsString('gross_revenue_minor', json_encode($video));
    }

}
