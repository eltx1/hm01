<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\GamConnectionType;
use App\Enums\MonetizationStatus;
use App\Enums\OrganizationType;
use App\Enums\PrebidConfiguredMode;
use App\Enums\PrebidDeliveryMode;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\PrebidBidder;
use App\Models\Site;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Monetization\SiteMonetizationReadinessService;
use App\Services\Operations\PlatformControlService;
use App\Services\Prebid\PrebidManager;
use App\Services\Serving\SiteEngineStateResolver;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PrebidModeControlHardeningTest extends TestCase
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
        $this->seed([InventoryDeliverySeeder::class, PrebidSeeder::class]);

        $this->horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($this->horus, RoleName::SuperAdmin);
        $this->gam = $this->makeGamConnection($this->horus, $this->admin, [
            'type' => GamConnectionType::HorusGam,
            'driver' => 'MOCK',
            'network_code' => '24681012',
            'is_primary' => true,
            'configuration' => ['root_ad_unit_id' => '1111'],
        ]);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Mode Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Mode Publisher']);
    }

    public function test_horus_gam_explicit_bridge_preserves_bridge_runtime(): void
    {
        $site = $this->site(ServingMode::HorusGam, PrebidConfiguredMode::GamBridge, true);
        $placement = $this->placement($site, 'bridge_slot');
        $this->mapPrebid($site, $placement, standalone: false);
        $this->enableGamProfile();

        $state = app(SiteEngineStateResolver::class)->resolve($site->refresh());
        $config = $this->config($site, 1601);

        $this->assertSame(PrebidConfiguredMode::GamBridge, $state->prebidConfiguredMode);
        $this->assertSame(PrebidDeliveryMode::GamBridge, $state->prebidDeliveryMode);
        $this->assertTrue($state->prebidEnabled);
        $this->assertTrue($config['prebidEnabled']);
        $this->assertSame('GAM', data_get($config, 'placements.0.renderer'));
        $this->assertSame('/24681012/bridge_slot', data_get($config, 'placements.0.adUnitPath'));
        $this->assertFalse(data_get($config, 'prebid.directRender.implemented'));
    }

    public function test_prebid_disabled_stops_both_modes(): void
    {
        foreach ([ServingMode::HorusGam, ServingMode::HorusDirect] as $mode) {
            $configured = $mode === ServingMode::HorusGam ? PrebidConfiguredMode::GamBridge : PrebidConfiguredMode::Standalone;
            $site = $this->site($mode, $configured, false);
            $state = app(SiteEngineStateResolver::class)->resolve($site);
            $this->assertFalse($state->prebidEnabled);
            $this->assertSame('SITE_PREBID_DISABLED', $state->prebidReason);
        }
    }

    public function test_horus_direct_explicit_standalone_is_active_without_gam(): void
    {
        $site = $this->site(ServingMode::HorusDirect, PrebidConfiguredMode::Standalone, true);
        $placement = $this->placement($site, 'standalone_slot');
        $this->mapPrebid($site, $placement, standalone: true);

        $state = app(SiteEngineStateResolver::class)->resolve($site->refresh());
        $config = $this->config($site, 1602);
        $readiness = app(SiteMonetizationReadinessService::class)->admin($site->refresh());
        $module = collect($readiness['modules'])->firstWhere('key', 'prebid');

        $this->assertSame(PrebidDeliveryMode::Standalone, $state->prebidDeliveryMode);
        $this->assertTrue($state->prebidEnabled);
        $this->assertNull($config['gamNetworkCode']);
        $this->assertFalse($config['prebidEnabled']);
        $this->assertTrue($config['prebid']['enabled']);
        $this->assertSame('PREBID_STANDALONE', data_get($config, 'placements.0.renderer'));
        $this->assertNull(data_get($config, 'placements.0.adUnitPath'));
        $this->assertSame(MonetizationStatus::Active->value, $module['status']);
    }

    public function test_gam_off_auto_resolves_to_configured_standalone_profile(): void
    {
        $site = $this->site(ServingMode::HorusGam, PrebidConfiguredMode::Auto, true);
        $placement = $this->placement($site, 'auto_slot');
        $this->mapPrebid($site, $placement, standalone: true);
        app(PlatformControlService::class)->set('SITE', $site->id, 'GAM', true, 'Task 16 AUTO fallback test.', $this->admin);

        $state = app(SiteEngineStateResolver::class)->resolve($site->refresh());
        $config = $this->config($site, 1603);

        $this->assertSame(PrebidConfiguredMode::Auto, $state->prebidConfiguredMode);
        $this->assertSame(PrebidDeliveryMode::Standalone, $state->prebidDeliveryMode);
        $this->assertTrue($state->prebidEnabled);
        $this->assertFalse($config['engines']['gam']['enabled']);
        $this->assertTrue($config['engines']['prebid']['enabled']);
        $this->assertSame('PREBID_STANDALONE', data_get($config, 'placements.0.renderer'));
        $this->assertNull(data_get($config, 'placements.0.adUnitPath'));
    }

    public function test_explicit_bridge_with_gam_unavailable_fails_safe_without_standalone_switch(): void
    {
        $site = $this->site(ServingMode::HorusGam, PrebidConfiguredMode::GamBridge, true);
        $placement = $this->placement($site, 'blocked_bridge');
        $this->mapPrebid($site, $placement, standalone: true);
        $this->enableGamProfile();
        app(PlatformControlService::class)->set('SITE', $site->id, 'GAM', true, 'Task 16 explicit bridge failure.', $this->admin);

        $state = app(SiteEngineStateResolver::class)->resolve($site->refresh());
        $config = $this->config($site, 1604);
        $readiness = app(SiteMonetizationReadinessService::class)->admin($site->refresh());
        $module = collect($readiness['modules'])->firstWhere('key', 'prebid');

        $this->assertSame(PrebidDeliveryMode::GamBridge, $state->prebidDeliveryMode);
        $this->assertFalse($state->prebidEnabled);
        $this->assertSame('GAM_BRIDGE_CONNECTION_REQUIRED', $state->prebidReason);
        $this->assertFalse($config['engines']['prebid']['enabled']);
        $this->assertNotSame('PREBID_STANDALONE', data_get($config, 'placements.0.renderer'));
        $this->assertSame(MonetizationStatus::ActionRequired->value, $module['status']);
    }

    public function test_prebid_and_master_controls_fail_closed_independently(): void
    {
        $prebidOff = $this->site(ServingMode::HorusDirect, PrebidConfiguredMode::Standalone, true);
        $placement = $this->placement($prebidOff, 'prebid_control');
        $this->mapPrebid($prebidOff, $placement, standalone: true);
        app(PlatformControlService::class)->set('SITE', $prebidOff->id, 'PREBID', true, 'Pause Prebid.', $this->admin);
        $this->assertFalse(app(SiteEngineStateResolver::class)->resolve($prebidOff->refresh())->prebidEnabled);

        $masterOff = $this->site(ServingMode::HorusDirect, PrebidConfiguredMode::Standalone, true);
        $placement = $this->placement($masterOff, 'master_control');
        $this->mapPrebid($masterOff, $placement, standalone: true);
        app(PlatformControlService::class)->set('SITE', $masterOff->id, 'AD_SERVING', true, 'Pause all serving.', $this->admin);
        $state = app(SiteEngineStateResolver::class)->resolve($masterOff->refresh());
        $this->assertFalse($state->masterServingEnabled);
        $this->assertFalse($state->prebidEnabled);
    }

    public function test_admin_diagnostics_are_detailed_but_publisher_health_is_safe(): void
    {
        $site = $this->site(ServingMode::HorusDirect, PrebidConfiguredMode::Standalone, true);
        $placement = $this->placement($site, 'safe_health');
        $this->mapPrebid($site, $placement, standalone: true);

        $service = app(SiteMonetizationReadinessService::class);
        $admin = $service->admin($site->refresh());
        $publisher = $service->publisher($site->refresh());
        $adminJson = json_encode($admin, JSON_THROW_ON_ERROR);
        $publisherJson = json_encode($publisher, JSON_THROW_ON_ERROR);

        $this->assertSame('STANDALONE', data_get($admin, 'diagnostics.prebid_control.resolved_mode'));
        $this->assertSame('STANDALONE', data_get($admin, 'diagnostics.prebid_control.configured_mode'));
        $this->assertArrayHasKey('production_config_status', $admin['diagnostics']);
        $this->assertStringContainsString('Header Bidding', $publisherJson);
        $this->assertStringNotContainsString('gam_connection_id', $publisherJson);
        $this->assertStringNotContainsString('network_code', strtolower($publisherJson));
        $this->assertStringNotContainsString('bidder_account', strtolower($publisherJson));
        $this->assertStringNotContainsString('credential', strtolower($publisherJson));
        $this->assertStringContainsString('configured_mode', $adminJson);
    }

    public function test_static_config_exposes_resolved_not_ambiguous_auto_and_contains_no_secrets(): void
    {
        $site = $this->site(ServingMode::HorusDirect, PrebidConfiguredMode::Auto, true);
        $placement = $this->placement($site, 'static_safe');
        $this->mapPrebid($site, $placement, standalone: true);

        $config = $this->config($site, 1605);
        $json = json_encode($config, JSON_THROW_ON_ERROR);

        $this->assertSame('AUTO', data_get($config, 'engines.prebid.configuredMode'));
        $this->assertSame('STANDALONE', data_get($config, 'engines.prebid.deliveryMode'));
        $this->assertSame('STANDALONE', data_get($config, 'prebid.deliveryMode'));
        $this->assertStringNotContainsString('SITE_STANDALONE', $json);
        $this->assertStringNotContainsString('gam_connection_id', $json);
        $this->assertStringNotContainsString('credential', strtolower($json));
        $this->assertStringNotContainsString('client_secret', strtolower($json));
    }

    private function site(ServingMode $mode, PrebidConfiguredMode $configuredMode, bool $prebid): Site
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Task 16 '.fake()->unique()->numerify('###'),
            'primary_domain' => fake()->unique()->domainName(),
            'prebid_enabled' => $prebid,
        ]);
        $site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => $mode,
            'prebid_enabled' => $prebid,
        ]);
        $site->servingSettings()->update([
            'serving_mode' => $mode,
            'prebid_enabled' => $prebid,
            'prebid_configured_mode' => $configuredMode,
        ]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        return $site->refresh()->load('servingSettings');
    }

    private function placement(Site $site, string $code)
    {
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => $code,
            'code' => $code,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);

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
        $account = $manager->addAccount($bidder, [
            'name' => 'Task16 '.$site->public_key.' '.$placement->code,
            'enabled' => true,
        ], $this->admin);
        $mapping = $manager->assignToSite($account, $site, ['enabled' => true], $this->admin);
        $manager->assignToPlacement($mapping, $placement, [
            'placement_id_value' => 'task16-'.$placement->code,
            'enabled' => true,
        ], $this->admin);

        if ($standalone) {
            $manager->updateStandaloneSettings($site, [
                'enabled' => true,
                'auction_timeout_ms' => 1200,
                'currency' => 'USD',
                'bidder_sequence' => 'fixed',
                'gam_fallback' => false,
            ], $this->admin);
        }
    }

    private function enableGamProfile(): void
    {
        app(PrebidManager::class)->updateSettings($this->gam, [
            'enabled' => true,
            'auction_timeout_ms' => 1200,
            'price_granularity' => 'medium',
            'currency' => 'USD',
            'bidder_sequence' => 'fixed',
            'gam_fallback' => true,
        ], $this->admin);
    }

    private function config(Site $site, int $version): array
    {
        return app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, $version);
    }
}
