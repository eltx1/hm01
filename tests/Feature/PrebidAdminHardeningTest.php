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
use App\Models\PrebidSetting;
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

class PrebidAdminHardeningTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $horus;
    private $admin;
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
            'network_code' => '123456789',
            'is_primary' => true,
            'is_enabled' => true,
            'configuration' => ['root_ad_unit_id' => '1111'],
        ]);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Publisher');
        $this->publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser);
    }

    public function test_mode_matrix_preserves_gam_bridge_and_standalone_behavior(): void
    {
        // 1. HORUS_GAM + explicit GAM_BRIDGE.
        $gamSite = $this->site(ServingMode::HorusGam, true, PrebidConfiguredMode::GamBridge);
        $gamPlacement = $this->placement($gamSite, 'gam_bridge');
        $this->mapPrebid($gamSite, $gamPlacement, standalone: false);
        $gamPayload = $this->config($gamSite, 1);
        $this->assertTrue($gamPayload['prebidEnabled']);
        $this->assertSame('GAM_BRIDGE', data_get($gamPayload, 'engines.prebid.configuredMode'));
        $this->assertSame('GAM_BRIDGE', data_get($gamPayload, 'engines.prebid.deliveryMode'));
        $this->assertSame('GAM', data_get($gamPayload, 'placements.0.renderer'));
        $this->assertNotNull(data_get($gamPayload, 'placements.0.adUnitPath'));

        // 2. HORUS_GAM + Prebid disabled.
        $gamSite->update(['prebid_enabled' => false]);
        $disabledGam = $this->config($gamSite->refresh(), 2);
        $this->assertFalse(data_get($disabledGam, 'engines.prebid.enabled'));
        $this->assertFalse($disabledGam['prebidEnabled']);

        // 3. HORUS_DIRECT + standalone Prebid.
        $directSite = $this->site(ServingMode::HorusDirect, true, PrebidConfiguredMode::Standalone);
        $directPlacement = $this->placement($directSite, 'standalone');
        $this->mapPrebid($directSite, $directPlacement, standalone: true);
        $directPayload = $this->config($directSite, 3);
        $this->assertNull($directPayload['gamNetworkCode']);
        $this->assertTrue(data_get($directPayload, 'engines.prebid.enabled'));
        $this->assertSame('STANDALONE', data_get($directPayload, 'engines.prebid.configuredMode'));
        $this->assertSame('STANDALONE', data_get($directPayload, 'engines.prebid.deliveryMode'));
        $this->assertSame('PREBID_STANDALONE', data_get($directPayload, 'placements.0.renderer'));
        $this->assertNull(data_get($directPayload, 'placements.0.adUnitPath'));

        // 4. HORUS_DIRECT + Prebid disabled.
        $directSite->update(['prebid_enabled' => false]);
        $disabledDirect = $this->config($directSite->refresh(), 4);
        $this->assertFalse(data_get($disabledDirect, 'engines.prebid.enabled'));
        $this->assertFalse($disabledDirect['prebid']['enabled']);
    }

    public function test_auto_resolves_standalone_when_gam_is_disabled_but_explicit_bridge_fails_closed(): void
    {
        // 5. GAM disabled + AUTO resolves STANDALONE and can keep Prebid alive.
        $auto = $this->site(ServingMode::HorusGam, true, PrebidConfiguredMode::Auto);
        $autoPlacement = $this->placement($auto, 'auto_standalone');
        $this->mapPrebid($auto, $autoPlacement, standalone: true);
        app(PlatformControlService::class)->set('SITE', $auto->id, 'GAM', true, 'Task 16 AUTO regression.', $this->admin);
        $state = app(SiteEngineStateResolver::class)->resolve($auto->refresh());
        $this->assertSame(PrebidConfiguredMode::Auto, $state->prebidConfiguredMode);
        $this->assertSame(PrebidDeliveryMode::Standalone, $state->prebidDeliveryMode);
        $this->assertTrue($state->prebidEnabled);
        $autoPayload = $this->config($auto->refresh(), 5);
        $this->assertFalse(data_get($autoPayload, 'engines.gam.enabled'));
        $this->assertTrue(data_get($autoPayload, 'engines.prebid.enabled'));
        $this->assertSame('STANDALONE', data_get($autoPayload, 'prebid.deliveryMode'));

        // 6. Explicit GAM_BRIDGE does not silently switch when GAM is unavailable.
        $explicitBridge = $this->site(ServingMode::HorusDirect, true, PrebidConfiguredMode::GamBridge);
        $bridgeState = app(SiteEngineStateResolver::class)->resolve($explicitBridge);
        $this->assertSame(PrebidDeliveryMode::GamBridge, $bridgeState->prebidDeliveryMode);
        $this->assertFalse($bridgeState->prebidEnabled);
        $this->assertSame('GAM_BRIDGE_CONNECTION_REQUIRED', $bridgeState->prebidReason);
        $bridgePayload = $this->config($explicitBridge, 6);
        $this->assertFalse(data_get($bridgePayload, 'engines.prebid.enabled'));
        $this->assertSame('GAM_BRIDGE', data_get($bridgePayload, 'engines.prebid.deliveryMode'));
        $this->assertNull($bridgePayload['gamNetworkCode']);

        $readiness = app(SiteMonetizationReadinessService::class)->admin($explicitBridge->refresh());
        $prebid = collect($readiness['modules'])->firstWhere('key', 'prebid');
        $this->assertSame(MonetizationStatus::ActionRequired->value, $prebid['status']);
        $this->assertSame('GAM_BRIDGE', data_get($prebid, 'diagnostics.configured_mode'));
        $this->assertSame('GAM_BRIDGE', data_get($prebid, 'diagnostics.resolved_mode'));
    }

    public function test_admin_can_activate_standalone_without_gam_and_reuses_existing_prebid_screen(): void
    {
        $site = $this->site(ServingMode::HorusDirect, false, PrebidConfiguredMode::Auto);
        $placement = $this->placement($site, 'admin_standalone');
        $this->mapBidderOnly($site, $placement);

        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.prebid.index', $site))
            ->assertOk()
            ->assertSee('Delivery mode')
            ->assertSee('AUTO')
            ->assertSee('GAM_BRIDGE')
            ->assertSee('STANDALONE')
            ->assertSee('Resolved STANDALONE');

        $build = \App\Models\PrebidBuild::query()->where('is_active', true)->latest('built_at')->firstOrFail();
        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.sites.prebid.settings', $site), [
                'delivery_mode' => 'STANDALONE',
                'enabled' => '1',
                'prebid_build_id' => $build->id,
                'auction_timeout_ms' => 950,
                'price_granularity' => 'medium',
                'currency' => 'USD',
                'bidder_sequence' => 'fixed',
                'consent_json' => '{}',
                'lazy_loading' => '1',
                'refresh_enabled' => '1',
                'refresh_minimum_seconds' => 30,
                'bidder_timeout_reporting' => '1',
                'gam_fallback' => '0',
            ])
            ->assertRedirect();

        $site = $site->refresh();
        $this->assertTrue($site->prebid_enabled);
        $this->assertSame(PrebidConfiguredMode::Standalone, $site->prebid_delivery_mode);
        $this->assertDatabaseHas('prebid_settings', [
            'scope' => PrebidSetting::SCOPE_SITE_STANDALONE,
            'site_id' => $site->id,
            'gam_connection_id' => null,
            'enabled' => true,
            'auction_timeout_ms' => 950,
        ]);
        $payload = $this->config($site, 7);
        $this->assertTrue($payload['prebid']['enabled']);
        $this->assertSame('STANDALONE', $payload['prebid']['deliveryMode']);
    }

    public function test_publisher_health_and_static_config_do_not_expose_sensitive_bidder_or_gam_data(): void
    {
        // 11/12. Publisher-safe readiness and public CDN config remain bounded.
        $site = $this->site(ServingMode::HorusDirect, true, PrebidConfiguredMode::Standalone);
        $placement = $this->placement($site, 'safe_output');
        $manager = app(PrebidManager::class);
        $bidder = PrebidBidder::withoutGlobalScopes()->where('code', 'msft')->firstOrFail();
        $account = $manager->addAccount($bidder, [
            'name' => 'SECRET INTERNAL BIDDER ACCOUNT',
            'publisher_id' => 'PUBLIC-BIDDER-ID-7788',
            'enabled' => true,
        ], $this->admin);
        $siteMapping = $manager->assignToSite($account, $site, ['enabled' => true], $this->admin);
        $manager->assignToPlacement($siteMapping, $placement, ['placement_id_value' => 'placement-public-42', 'enabled' => true], $this->admin);
        $manager->updateStandaloneSettings($site, ['enabled' => true], $this->admin);

        $publisher = json_encode(app(SiteMonetizationReadinessService::class)->publisher($site->refresh()), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Header Bidding', $publisher);
        $this->assertStringContainsString('ACTIVE', $publisher);
        $this->assertStringNotContainsString('SECRET INTERNAL BIDDER ACCOUNT', $publisher);
        $this->assertStringNotContainsString('PUBLIC-BIDDER-ID-7788', $publisher);
        $this->assertStringNotContainsString('123456789', $publisher);
        $this->assertStringNotContainsString('gam_connection_id', $publisher);

        $payload = $this->config($site->refresh(), 8);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertNull($payload['gamNetworkCode']);
        $this->assertStringNotContainsString('SECRET INTERNAL BIDDER ACCOUNT', $encoded);
        $this->assertStringNotContainsString('123456789', $encoded);
        $this->assertStringNotContainsString('credential', strtolower($encoded));
        $this->assertStringNotContainsString('secret', strtolower($encoded));
    }

    public function test_prebid_off_and_master_ad_serving_off_stop_the_resolved_engine(): void
    {
        $site = $this->site(ServingMode::HorusDirect, true, PrebidConfiguredMode::Standalone);
        $placement = $this->placement($site, 'controls');
        $this->mapPrebid($site, $placement, standalone: true);

        app(PlatformControlService::class)->set('SITE', $site->id, 'PREBID', true, 'Task 16 PREBID control.', $this->admin);
        $prebidOff = app(SiteEngineStateResolver::class)->resolve($site->refresh());
        $this->assertFalse($prebidOff->prebidEnabled);
        $this->assertSame('PREBID_CONTROL_DISABLED', $prebidOff->prebidReason);

        app(PlatformControlService::class)->set('SITE', $site->id, 'PREBID', false, 'Resume Prebid.', $this->admin);
        app(PlatformControlService::class)->set('SITE', $site->id, 'AD_SERVING', true, 'Master stop.', $this->admin);
        $masterOff = app(SiteEngineStateResolver::class)->resolve($site->refresh());
        $this->assertFalse($masterOff->prebidEnabled);
        $this->assertSame('MASTER_SERVING_DISABLED', $masterOff->prebidReason);
        $this->assertSame(PrebidDeliveryMode::Standalone, $masterOff->prebidDeliveryMode);
    }

    private function site(ServingMode $servingMode, bool $prebid, PrebidConfiguredMode $configuredMode): Site
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Task16 '.fake()->unique()->numerify('####'),
            'primary_domain' => fake()->unique()->domainName(),
            'prebid_enabled' => $prebid,
            'prebid_delivery_mode' => $configuredMode,
        ]);
        $site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => $servingMode,
            'prebid_enabled' => $prebid,
            'prebid_delivery_mode' => $configuredMode,
        ]);
        $site->servingSettings()->update(['serving_mode' => $servingMode, 'prebid_enabled' => $prebid]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        return $site->refresh();
    }

    private function placement(Site $site, string $code)
    {
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => ucfirst($code), 'code' => $code,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);

        return $inventory->createPlacement($site, [
            'name' => ucfirst($code), 'code' => $code, 'type' => 'DISPLAY', 'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
    }

    private function mapBidderOnly(Site $site, $placement): void
    {
        $manager = app(PrebidManager::class);
        $bidder = PrebidBidder::withoutGlobalScopes()->where('code', 'msft')->firstOrFail();
        $account = $manager->addAccount($bidder, ['name' => 'Task16 '.$site->public_key.' '.$placement->code, 'enabled' => true], $this->admin);
        $mapping = $manager->assignToSite($account, $site, ['enabled' => true], $this->admin);
        $manager->assignToPlacement($mapping, $placement, ['placement_id_value' => '42-'.$placement->code, 'enabled' => true], $this->admin);
    }

    private function mapPrebid(Site $site, $placement, bool $standalone): void
    {
        $this->mapBidderOnly($site, $placement);
        $manager = app(PrebidManager::class);
        if ($standalone) {
            $manager->updateStandaloneSettings($site, [
                'enabled' => true, 'auction_timeout_ms' => 1000,
                'price_granularity' => 'medium', 'currency' => 'USD', 'bidder_sequence' => 'fixed',
            ], $this->admin);
        } else {
            $manager->updateSettings($this->gam, [
                'enabled' => true, 'auction_timeout_ms' => 1000,
                'price_granularity' => 'medium', 'currency' => 'USD', 'bidder_sequence' => 'fixed', 'gam_fallback' => true,
            ], $this->admin);
        }
    }

    private function config(Site $site, int $version): array
    {
        return app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, $version);
    }
}
