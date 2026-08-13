<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\DemandNetwork;
use App\Models\HorusNotification;
use App\Models\PrebidBidder;
use App\Models\Site;
use App\Services\ControlPlane\ActionCenter;
use App\Services\Demand\DemandAccountService;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Monetization\MonetizationHealthMonitor;
use App\Services\Monetization\ReportingHealthService;
use App\Services\Monetization\SiteMonetizationReadinessService;
use App\Services\Monetization\SiteServingOverviewService;
use App\Services\Operations\PlatformControlService;
use App\Services\Prebid\PrebidManager;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class UnifiedMultiEngineServingControlCenterTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $horus;
    private $gam;
    private $publisherUser;
    private $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, PrebidSeeder::class, DemandNetworkSeeder::class]);

        $this->horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($this->horus, RoleName::SuperAdmin);
        $this->gam = $this->makeGamConnection($this->horus, $this->admin, [
            'type' => GamConnectionType::HorusGam,
            'driver' => 'MOCK',
            'network_code' => '246813579',
            'is_primary' => true,
            'configuration' => ['root_ad_unit_id' => '1111'],
        ]);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Task 19 Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Task 19 Publisher']);
    }

    public function test_gam_off_prebid_and_direct_combinations_are_active_without_gam(): void
    {
        foreach ([[true, true], [true, false], [false, true]] as [$prebid, $direct]) {
            $site = $this->site(ServingMode::HorusDirect, $prebid, $direct);
            if ($prebid) {
                $this->mapPrebid($site, $this->placement($site, 'pb_'.($direct ? 'both' : 'only')), standalone: true);
            }
            if ($direct) {
                $this->mapDirectJs($site, $this->placement($site, 'direct_'.($prebid ? 'both' : 'only')));
            }

            $payload = $this->config($site);
            $readiness = app(SiteMonetizationReadinessService::class)->admin($site->refresh());

            $this->assertFalse(data_get($payload, 'engines.gam.enabled'));
            $this->assertSame($prebid, (bool) data_get($payload, 'engines.prebid.enabled'));
            $this->assertSame($direct, (bool) data_get($payload, 'engines.directJs.enabled'));
            $this->assertSame('ACTIVE', data_get($readiness, 'overall.status'));
            $this->assertSame('NOT_CONFIGURED', collect($readiness['modules'])->firstWhere('key', 'display')['status']);
        }
    }

    public function test_gam_bridge_and_direct_js_can_run_on_independent_placement_surfaces(): void
    {
        $site = $this->site(ServingMode::HorusGam, true, true);
        $bridge = $this->placement($site, 'gam_bridge');
        $direct = $this->placement($site, 'direct_surface', gamEligible: false);
        $this->mapPrebid($site, $bridge, standalone: false);
        $this->mapDirectJs($site, $direct);

        $payload = $this->config($site);
        $placements = collect($payload['placements'])->keyBy('code');
        $this->assertTrue(data_get($payload, 'engines.gam.enabled'));
        $this->assertTrue(data_get($payload, 'engines.prebid.enabled'));
        $this->assertSame('GAM_BRIDGE', data_get($payload, 'engines.prebid.deliveryMode'));
        $this->assertTrue(data_get($payload, 'engines.directJs.enabled'));
        $this->assertSame('GAM', $placements['gam_bridge']['renderer']);
        $this->assertSame('DIRECT_JS', $placements['direct_surface']['renderer']);

        $withoutPrebid = $this->site(ServingMode::HorusGam, false, true);
        $directOnly = $this->placement($withoutPrebid, 'direct_with_gam', gamEligible: false);
        $this->mapDirectJs($withoutPrebid, $directOnly);
        $payload = $this->config($withoutPrebid);
        $this->assertTrue(data_get($payload, 'engines.gam.enabled'));
        $this->assertFalse(data_get($payload, 'engines.prebid.enabled'));
        $this->assertTrue(data_get($payload, 'engines.directJs.enabled'));
        $this->assertSame('DIRECT_JS', collect($payload['placements'])->firstWhere('code', 'direct_with_gam')['renderer']);
    }

    public function test_engine_controls_are_independent_and_master_pause_stops_everything(): void
    {
        $site = $this->site(ServingMode::HorusDirect, true, true);
        $this->mapPrebid($site, $this->placement($site, 'pb_control'), standalone: true);
        $this->mapDirectJs($site, $this->placement($site, 'direct_control'));
        $controls = app(PlatformControlService::class);

        $controls->set('SITE', $site->id, 'PREBID', true, 'Task 19 Prebid isolation.', $this->admin);
        $payload = $this->config($site->refresh());
        $this->assertFalse(data_get($payload, 'engines.prebid.enabled'));
        $this->assertTrue(data_get($payload, 'engines.directJs.enabled'));
        $controls->set('SITE', $site->id, 'PREBID', false, 'Task 19 Prebid resume.', $this->admin);

        $controls->set('SITE', $site->id, 'DIRECT_JS', true, 'Task 19 Direct JS isolation.', $this->admin);
        $payload = $this->config($site->refresh());
        $this->assertTrue(data_get($payload, 'engines.prebid.enabled'));
        $this->assertFalse(data_get($payload, 'engines.directJs.enabled'));
        $controls->set('SITE', $site->id, 'DIRECT_JS', false, 'Task 19 Direct JS resume.', $this->admin);

        $controls->set('SITE', $site->id, 'PREBID', true, 'Task 19 all engines off.', $this->admin);
        $controls->set('SITE', $site->id, 'DIRECT_JS', true, 'Task 19 all engines off.', $this->admin);
        $readiness = app(SiteMonetizationReadinessService::class)->admin($site->refresh());
        $this->assertSame('ACTION_REQUIRED', data_get($readiness, 'overall.status'));

        $controls->set('SITE', $site->id, 'AD_SERVING', true, 'Task 19 master serving pause.', $this->admin);
        $payload = $this->config($site->refresh());
        $this->assertSame('paused', $payload['status']);
        $this->assertFalse(data_get($payload, 'engines.gam.enabled'));
        $this->assertFalse(data_get($payload, 'engines.prebid.enabled'));
        $this->assertFalse(data_get($payload, 'engines.directJs.enabled'));
        $this->assertSame('PAUSED', data_get(app(SiteMonetizationReadinessService::class)->admin($site->refresh()), 'overall.status'));
    }

    public function test_site_360_uses_published_renderer_matrix_and_publisher_output_is_white_labelled(): void
    {
        $site = $this->site(ServingMode::HorusDirect, true, true);
        $this->mapPrebid($site, $this->placement($site, 'header_728'), standalone: true);
        $this->mapDirectJs($site, $this->placement($site, 'sidebar_300'));
        $version = app(SiteConfigPublisher::class)->publishActiveProduction($site, $this->admin);
        $this->assertNotNull($version);
        $this->assertGreaterThan(0, $version->version);

        $overview = app(SiteServingOverviewService::class)->forSite($site->refresh());
        $matrix = collect($overview['placement_matrix'])->keyBy('placement');
        $this->assertSame('NOT_CONFIGURED', data_get($overview, 'gam.status'));
        $this->assertSame('ON', data_get($overview, 'prebid.status'));
        $this->assertSame('STANDALONE', data_get($overview, 'prebid.resolved_mode'));
        $this->assertSame('ON', data_get($overview, 'direct_js.status'));
        $this->assertSame('PREBID_STANDALONE', $matrix['header_728']['renderer']);
        $this->assertSame('DIRECT_JS', $matrix['sidebar_300']['renderer']);

        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.show', $site))
            ->assertOk()
            ->assertSee('Serving Control Center')
            ->assertSee('Placement Matrix')
            ->assertSee('MASTER AD SERVING')
            ->assertSee('PREBID_STANDALONE');

        $publisher = app(SiteMonetizationReadinessService::class)->publisher($site->refresh());
        $publisherJson = json_encode($publisher, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Display Monetization', $publisherJson);
        $this->assertStringContainsString('Header Bidding', $publisherJson);
        $this->assertStringContainsString('Direct Monetization', $publisherJson);
        $this->assertStringNotContainsString('GAM / Display', $publisherJson);
        $this->assertStringNotContainsString('MGID', $publisherJson);
        $this->assertStringNotContainsString('network_code', strtolower($publisherJson));
    }

    public function test_reporting_health_is_source_aware_and_monitor_notifications_are_transition_deduped(): void
    {
        $site = $this->site(ServingMode::HorusDirect, false, true);
        $this->mapDirectJs($site, $this->placement($site, 'reporting_direct'));
        app(SiteConfigPublisher::class)->publishActiveProduction($site, $this->admin);

        $reporting = app(ReportingHealthService::class)->forSite($site->refresh());
        $this->assertSame('PENDING', $reporting['status']);
        $this->assertNotEmpty($reporting['sources']);
        $this->assertSame('DIRECT_JS', $reporting['sources'][0]['engine']);
        $this->assertSame('MISSING', $reporting['sources'][0]['status']);

        $monitor = app(MonetizationHealthMonitor::class);
        $baseline = HorusNotification::withoutGlobalScopes()->count();
        $monitor->observe($site->refresh());
        $this->assertSame($baseline, HorusNotification::withoutGlobalScopes()->count(), 'First observation seeds state without notification noise.');

        app(PlatformControlService::class)->set('SITE', $site->id, 'DIRECT_JS', true, 'Task 19 transition failure.', $this->admin);
        $monitor->observe($site->refresh());
        $afterBreak = HorusNotification::withoutGlobalScopes()->count();
        $this->assertGreaterThan($baseline, $afterBreak);
        $monitor->observe($site->refresh());
        $this->assertSame($afterBreak, HorusNotification::withoutGlobalScopes()->count(), 'Unchanged broken state must not notify again.');

        $items = collect(app(ActionCenter::class)->items($this->admin));
        $this->assertGreaterThan(0, data_get($items->firstWhere('key', 'monetization-no-engine'), 'count', 0));
        $this->assertGreaterThan(0, data_get($items->firstWhere('key', 'monetization-report-stale'), 'count', 0));

        app(PlatformControlService::class)->set('SITE', $site->id, 'DIRECT_JS', false, 'Task 19 transition recovery.', $this->admin);
        $monitor->observe($site->refresh());
        $this->assertGreaterThan($afterBreak, HorusNotification::withoutGlobalScopes()->count());
    }

    public function test_monitor_detects_renderer_conflict_rejected_mapping_suspended_account_and_unsafe_recipe(): void
    {
        $site = $this->site(ServingMode::HorusDirect, true, true);
        $placement = $this->placement($site, 'conflict_surface');
        $this->mapPrebid($site, $placement, standalone: true);
        [$account, $demandSite] = $this->mapDirectJs($site, $placement);
        app(SiteConfigPublisher::class)->publishActiveProduction($site, $this->admin);
        $monitor = app(MonetizationHealthMonitor::class);

        $states = collect($monitor->states($site->refresh()))->keyBy('key');
        $this->assertSame('BROKEN', $states['renderer_conflict']['status']);

        $demandSite->update(['approval_status' => DemandApprovalStatus::Rejected]);
        $states = collect($monitor->states($site->refresh()))->keyBy('key');
        $this->assertSame('BROKEN', $states['direct_mapping_rejected']['status']);

        $demandSite->update(['approval_status' => DemandApprovalStatus::Approved]);
        $account->update(['approval_status' => DemandApprovalStatus::Suspended]);
        $states = collect($monitor->states($site->refresh()))->keyBy('key');
        $this->assertSame('BROKEN', $states['provider_account_suspended']['status']);

        $account->update([
            'approval_status' => DemandApprovalStatus::Approved,
            'configuration' => array_replace((array) $account->configuration, ['script_url' => 'javascript:alert(1)']),
        ]);
        $states = collect($monitor->states($site->refresh()))->keyBy('key');
        $this->assertSame('BROKEN', $states['unsafe_tag_recipe']['status']);
    }

    private function site(ServingMode $mode, bool $prebid, bool $direct): Site
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Task19 '.fake()->unique()->numerify('#####'),
            'primary_domain' => fake()->unique()->domainName(),
            'prebid_enabled' => $prebid,
            'native_demand_enabled' => $direct,
        ]);
        $site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => $mode,
            'prebid_enabled' => $prebid,
            'native_demand_enabled' => $direct,
        ]);
        $site->servingSettings()->update([
            'serving_mode' => $mode,
            'prebid_enabled' => $prebid,
            'native_demand_enabled' => $direct,
        ]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        return $site->refresh();
    }

    private function placement(Site $site, string $code, bool $gamEligible = true)
    {
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => $code,
            'code' => $code,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
        if (! $gamEligible) {
            $adUnit->update(['is_enabled' => false]);
        }

        return $inventory->createPlacement($site, [
            'name' => $code,
            'code' => $code,
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
    }

    private function mapPrebid(Site $site, $placement, bool $standalone): void
    {
        $manager = app(PrebidManager::class);
        $bidder = PrebidBidder::withoutGlobalScopes()->where('code', 'msft')->firstOrFail();
        $account = $manager->addAccount($bidder, ['name' => 'Task19 '.$site->public_key.' '.$placement->code, 'enabled' => true], $this->admin);
        $mapping = $manager->assignToSite($account, $site, ['enabled' => true], $this->admin);
        $manager->assignToPlacement($mapping, $placement, ['placement_id_value' => 'task19-'.$placement->code, 'enabled' => true], $this->admin);

        if ($standalone) {
            $manager->updateStandaloneSettings($site, [
                'enabled' => true,
                'auction_timeout_ms' => 1200,
                'price_granularity' => 'medium',
                'currency' => 'USD',
                'bidder_sequence' => 'fixed',
                'gam_fallback' => false,
            ], $this->admin);
        } else {
            $manager->updateSettings($this->gam, [
                'enabled' => true,
                'auction_timeout_ms' => 1200,
                'price_granularity' => 'medium',
                'currency' => 'USD',
                'bidder_sequence' => 'fixed',
                'gam_fallback' => true,
            ], $this->admin);
        }
    }

    private function mapDirectJs(Site $site, $placement): array
    {
        $network = DemandNetwork::query()->where('code', 'MGID')->firstOrFail();
        $service = app(DemandAccountService::class);
        $account = $service->create([
            'organization_id' => $this->horus->id,
            'demand_network_id' => $network->id,
            'name' => 'Task19 Direct '.$site->public_key.' '.$placement->code,
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 20,
            'fallback_priority' => 10,
            'account_identifier' => 'public-'.$placement->code,
            'configuration' => [
                'script_url' => 'https://jsc.mgid.com/'.$site->primary_domain.'/'.$placement->code.'.js',
                'container_id' => 'widget-'.$placement->code,
                'render_timeout_ms' => 500,
                'currency' => 'USD',
            ],
        ], $this->admin);
        $demandSite = $service->assignSite($account, $site, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'fallback_priority' => 10,
            'remote_site_id' => 'remote-'.$site->public_key,
        ], $this->admin);
        $demandPlacement = $service->assignPlacement($demandSite, $placement, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'fallback_priority' => 10,
            'remote_placement_id' => 'remote-'.$placement->code,
            'placement_code' => 'widget-'.$placement->code,
        ], $this->admin);
        $service->upsertWidget($demandPlacement, [
            'name' => 'Task19 Widget '.$placement->code,
            'remote_widget_id' => 'widget-'.$placement->code,
            'widget_code' => 'widget-'.$placement->code,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'configuration' => [],
        ], $this->admin);

        return [$account->refresh(), $demandSite->refresh(), $demandPlacement->refresh()];
    }

    private function config(Site $site): array
    {
        return app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1900 + random_int(1, 500));
    }
}
