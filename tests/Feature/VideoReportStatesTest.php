<?php

namespace Tests\Feature;

use App\Enums\{OrganizationType, ReportFinality, ReportSourceCode, RoleName};
use App\Models\{DailyReport, ReportDimension, ReportSource, ReportSourceConnection, Site, SiteGamVideoReportBinding};
use App\Services\Reporting\{PerformanceMetrics, SiteGamReportMetrics, VideoPerformanceService};
use Carbon\CarbonImmutable;
use Database\Seeders\{InventoryDeliverySeeder, ReportingSeeder};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\{InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites};
use Tests\TestCase;

class VideoReportStatesTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private function context(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, ReportingSeeder::class]);
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $site = $this->makeSiteFor($publisher, $user);
        $gam = $this->makeGamConnection($admin->organization, $admin);

        return [$admin, $user, $publisher, $site, $gam];
    }

    private function binding(array $context, array $attributes = [], array $connectionAttributes = []): SiteGamVideoReportBinding
    {
        [$admin, , $publisher, $site, $gam] = $context;
        $id = (string) str()->ulid();
        $connection = ReportSourceConnection::withoutGlobalScopes()->create(array_replace([
            'organization_id' => $publisher->organization_id,
            'report_source_id' => ReportSource::where('code', ReportSourceCode::HorusGam->value)->firstOrFail()->id,
            'name' => 'Private video source', 'connection_type' => 'SITE_GAM_VIDEO_AD_UNIT',
            'connection_id' => $id, 'account_identifier' => 'private-account',
            'currency' => 'USD', 'timezone' => 'Africa/Cairo', 'status' => 'ACTIVE', 'is_enabled' => true,
        ], $connectionAttributes));

        return SiteGamVideoReportBinding::withoutGlobalScopes()->create(array_replace([
            'id' => $id, 'organization_id' => $publisher->organization_id, 'site_id' => $site->id,
            'gam_connection_id' => $gam->id, 'report_source_connection_id' => $connection->id,
            'active_site_id' => $site->id, 'active_unit_key' => $id,
            'network_code' => $gam->network_code, 'ad_unit_id' => '12345',
            'ad_unit_name' => 'Private video unit', 'ad_unit_code' => 'private-unit',
            'starts_on' => '2026-10-03', 'created_by' => $admin->id,
        ], $attributes));
    }

    public function test_first_sync_exposes_activation_and_safe_status_without_private_source_details(): void
    {
        $context = [, $user, $publisher] = $this->context();
        $this->binding($context);
        $this->actingAs($user);
        $service = app(VideoPerformanceService::class);
        $video = $service->summary(collect(), true, 'USD', $service->configuration($publisher));

        $this->assertFalse($video['available']);
        $this->assertTrue($video['configured']);
        $this->assertTrue($video['has_configuration']);
        $this->assertSame('enabled', $video['configuration_state']);
        $this->assertSame('pending', $video['source_health']);
        $this->assertSame('2026-10-03', $video['starts_on']);
        $this->assertSame(1, $video['source_count']);
        $this->assertSame(1, $video['pending_source_count']);
        $this->assertSame(0, $video['reported_day_count']);
        $this->assertNull($video['first_reported_on']);
        $this->assertNull($video['last_reported_on']);
        $this->assertSame(['Africa/Cairo'], $video['timezones']);
        $this->assertStringNotContainsString('private-', json_encode($video));
        $this->assertStringNotContainsString('Private video', json_encode($video));
    }

    public function test_a_new_failure_is_reported_but_a_later_success_supersedes_stale_error_text_and_status(): void
    {
        $context = [, $user, $publisher] = $this->context();
        $binding = $this->binding($context, [], [
            'status' => 'ERROR', 'last_error' => 'Private error with account details',
            'last_attempted_at' => '2026-10-04 11:00:00', 'last_successful_import_at' => '2026-10-04 10:00:00',
        ]);
        $this->actingAs($user);
        $service = app(VideoPerformanceService::class);
        $failed = $service->configuration($publisher);
        $this->assertSame('failed', $failed['source_health']);
        $this->assertSame(1, $failed['failed_source_count']);
        $this->assertSame(0, $failed['pending_source_count']);
        $this->assertStringNotContainsString('Private error', json_encode($failed));

        $binding->connection->update(['last_successful_import_at' => '2026-10-04 11:30:00']);
        $ready = $service->configuration($publisher);
        $this->assertSame('ready', $ready['source_health']);
        $this->assertSame(0, $ready['failed_source_count']);
        $this->assertSame('2026-10-04 11:30:00', $ready['last_successful_import_at']->format('Y-m-d H:i:s'));
    }

    public function test_disabled_history_remains_distinct_from_an_unconfigured_source(): void
    {
        $context = [, $user, $publisher] = $this->context();
        $this->binding($context, ['active_site_id' => null, 'active_unit_key' => null, 'ends_on' => '2026-10-03'], [
            // Retired sources may remain enabled to finalize their last owned day.
            'is_enabled' => true, 'status' => 'ERROR', 'last_error' => 'Old source failure',
        ]);
        $this->actingAs($user);
        $configuration = app(VideoPerformanceService::class)->configuration($publisher);

        $this->assertFalse($configuration['configured']);
        $this->assertTrue($configuration['has_configuration']);
        $this->assertSame('disabled', $configuration['configuration_state']);
        $this->assertSame('disabled', $configuration['source_health']);
        $this->assertSame('2026-10-03', $configuration['ends_on']);
        $this->assertSame(0, $configuration['failed_source_count']);
    }

    public function test_cancelled_future_bindings_do_not_claim_historical_video_reporting(): void
    {
        $context = [, $user, $publisher] = $this->context();
        $this->binding($context, [
            'active_site_id' => null, 'active_unit_key' => null, 'starts_on' => '2026-10-10',
            'ends_on' => '2026-10-09', 'cancelled_at' => now(),
        ], ['is_enabled' => false, 'status' => 'DISABLED']);
        $this->actingAs($user);
        $configuration = app(VideoPerformanceService::class)->configuration($publisher);

        $this->assertFalse($configuration['has_configuration']);
        $this->assertSame('unconfigured', $configuration['configuration_state']);
        $this->assertNull($configuration['starts_on']);
        $this->assertSame([], $configuration['timezones']);
    }

    public function test_configuration_is_scoped_by_publisher_site_and_staff_permission(): void
    {
        $context = [$admin, $user, $publisher, $site, $gam] = $this->context();
        $this->binding($context);
        $otherSite = $this->makeSiteFor($publisher, $user);
        $this->binding([$admin, $user, $publisher, $otherSite, $gam], [], ['timezone' => 'Asia/Tokyo', 'status' => 'ERROR']);
        $otherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $otherOwner = $this->makePublisherFor($otherUser);
        $externalSite = $this->makeSiteFor($otherOwner, $otherUser);
        $this->binding([$admin, $otherUser, $otherOwner, $externalSite, $gam], [], ['timezone' => 'America/New_York']);
        $service = app(VideoPerformanceService::class);
        $this->actingAs($user);

        $this->assertSame(['Africa/Cairo', 'Asia/Tokyo'], $service->configuration($publisher)['timezones']);
        $this->assertSame(['Africa/Cairo'], $service->configuration($site)['timezones']);
        $this->assertSame(0, $service->configuration($externalSite)['source_count']);
        $this->assertSame('unconfigured', $service->configuration()['configuration_state']);
        $this->actingAs($admin);
        $this->assertSame(3, $service->configuration()['source_count']);
    }

    public function test_current_source_health_does_not_inherit_a_retired_source_failure(): void
    {
        $context = [, $user, $publisher] = $this->context();
        $this->binding($context, ['active_site_id' => null, 'active_unit_key' => null, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02'], [
            'status' => 'ERROR', 'last_error' => 'Retired failure', 'timezone' => 'UTC',
        ]);
        $this->binding($context, [], ['last_successful_import_at' => '2026-10-04 11:00:00']);
        $this->actingAs($user);
        $configuration = app(VideoPerformanceService::class)->configuration($publisher);

        $this->assertSame('enabled', $configuration['configuration_state']);
        $this->assertSame('ready', $configuration['source_health']);
        $this->assertSame(0, $configuration['failed_source_count']);
        $this->assertSame(1, $configuration['source_count']);
        $this->assertSame('2026-10-01', $configuration['starts_on']);
        $this->assertSame(['Africa/Cairo'], $configuration['timezones']);
    }

    private function report(Site $site, string $date, int $gross, int $impressions, ReportFinality $finality): DailyReport
    {
        $connection = new ReportSourceConnection(['connection_type' => 'SITE_GAM_VIDEO_AD_UNIT', 'timezone' => 'UTC']);
        $connection->setRelation('source', new ReportSource(['code' => ReportSourceCode::GamVideoAdUnit]));
        $dimension = new ReportDimension(['site_id' => $site->id, 'external_dimensions' => [
            'gam_report_basis' => SiteGamReportMetrics::BASIS, 'gam_report_site' => $site->primary_domain,
            'gam_ad_unit_id' => '12345', 'gam_report_scope' => 'EXACT_SITE_V1',
        ]]);
        $dimension->setRelation('site', $site);
        $row = new DailyReport([
            'report_date' => $date, 'finality' => $finality, 'impressions' => $impressions,
            'gross_revenue_minor' => $gross, 'publisher_earnings_minor' => (int) ($gross * .7),
            'horus_earnings_minor' => (int) ($gross * .3), 'updated_at' => '2026-10-04 11:00:00',
        ]);
        $row->setRelation('connection', $connection);
        $row->setRelation('dimension', $dimension);

        return $row;
    }

    public function test_breakdown_identity_observed_dates_and_weighted_metrics_preserve_publisher_net_only_projection(): void
    {
        [, $user, $publisher, $site] = $this->context();
        $secondSite = $this->makeSiteFor($publisher, $user, ['display_name' => 'Higher earnings']);
        $rows = collect([
            $this->report($site, '2026-10-01', 1000, 1000, ReportFinality::Finalized),
            $this->report($secondSite, '2026-10-03', 6000, 3000, ReportFinality::Estimated),
        ]);
        $service = app(VideoPerformanceService::class);
        $publisherVideo = $service->summary($rows, true, 'USD');

        $this->assertSame(2, $publisherVideo['reported_day_count']);
        $this->assertSame('2026-10-01', $publisherVideo['first_reported_on']);
        $this->assertSame('2026-10-03', $publisherVideo['last_reported_on']);
        $this->assertSame(['2026-10-01', '2026-10-03'], $publisherVideo['days']->pluck('date')->all());
        $this->assertTrue($publisherVideo['has_estimates']);
        $this->assertSame(4900, $publisherVideo['revenue_minor']);
        $this->assertSame(1225, $publisherVideo['ecpm_minor']);
        $this->assertSame(4000, $publisherVideo['impressions']);
        $this->assertNull($publisherVideo['unfilled_impressions']);
        $this->assertSame($secondSite->id, $publisherVideo['websites']->first()['site_id']);
        $this->assertSame($secondSite->primary_domain, $publisherVideo['websites']->first()['domain']);
        $this->assertStringContainsString('ad unit, all sites', PerformanceMetrics::label('unfilled_impressions', $publisherVideo));
        foreach (['gross_revenue_minor', 'publisher_earnings_minor', 'horus_earnings_minor'] as $key) {
            $this->assertStringNotContainsString($key, json_encode($publisherVideo));
        }
        $adminVideo = $service->summary($rows, false, 'USD');
        $this->assertSame(7000, $adminVideo['revenue_minor']);
        $this->assertSame(1750, $adminVideo['ecpm_minor']);
        $this->assertSame(4900, $adminVideo['publisher_earnings_minor']);
        $this->assertSame(2100, $adminVideo['horus_earnings_minor']);
    }
}
