<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\PrebidDeliveryMode;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\PrebidBidder;
use App\Models\PrebidPriceBucket;
use App\Models\PrebidSetting;
use App\Models\Site;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Prebid\PrebidManager;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class StandalonePrebidRuntimeTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $horus;
    private $publisherUser;
    private $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, PrebidSeeder::class]);

        $this->horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($this->horus, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Standalone Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Standalone Publisher']);
    }

    public function test_site_owned_standalone_profile_resolves_without_gam_connection(): void
    {
        $site = $this->site('standalone-profile');
        $placement = $this->placement($site, 'standalone_top');
        $this->mapPrebid($site, $placement, 'standalone-a');

        $settings = app(PrebidManager::class)->updateStandaloneSettings($site, [
            'enabled' => true,
            'auction_timeout_ms' => 875,
            'price_granularity' => 'medium',
            'currency' => 'USD',
            'bidder_sequence' => 'fixed',
            'consent_behavior' => ['gdpr' => ['cmpApi' => 'iab', 'timeout' => 650]],
            'lazy_loading' => ['enabled' => true],
            'refresh_behavior' => ['enabled' => true, 'minimumIntervalSeconds' => 30],
            'bidder_timeout_reporting' => true,
            'gam_fallback' => true,
        ], $this->admin);

        $payload = $this->config($site, 15);

        $this->assertSame(PrebidSetting::SCOPE_SITE_STANDALONE, $settings->scope);
        $this->assertSame($site->id, $settings->site_id);
        $this->assertNull($settings->gam_connection_id);
        $this->assertFalse($settings->gam_fallback);
        $this->assertNull($payload['gamNetworkCode']);
        $this->assertFalse($payload['engines']['gam']['enabled']);
        $this->assertTrue($payload['engines']['prebid']['enabled']);
        $this->assertFalse($payload['prebidEnabled'], 'The schema-v2 bridge flag remains false for standalone delivery.');
        $this->assertTrue($payload['prebid']['enabled']);
        $this->assertSame(PrebidDeliveryMode::Standalone->value, $payload['prebid']['deliveryMode']);
        $this->assertSame(875, data_get($payload, 'prebid.auction.timeoutMs'));
        $this->assertFalse(data_get($payload, 'prebid.delivery.gamFallback'));
        $this->assertTrue(data_get($payload, 'prebid.directRender.implemented'));
        $this->assertSame(['banner'], data_get($payload, 'prebid.directRender.supportedMediaTypes'));
        $this->assertSame('PREBID_STANDALONE', data_get($payload, 'placements.0.renderer'));
        $this->assertNull(data_get($payload, 'placements.0.adUnitPath'));
    }

    public function test_standalone_static_config_contains_public_runtime_data_only(): void
    {
        $site = $this->site('public-safe');
        $placement = $this->placement($site, 'public_safe');
        $this->mapPrebid($site, $placement, 'public-safe-placement');
        app(PrebidManager::class)->updateStandaloneSettings($site, [
            'enabled' => true,
            'auction_timeout_ms' => 1000,
            'currency' => 'USD',
            'bidder_sequence' => 'fixed',
        ], $this->admin);

        $payload = $this->config($site, 16);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertNull($payload['gamNetworkCode']);
        $this->assertNotEmpty(data_get($payload, 'prebid.build.url'));
        $this->assertNotEmpty(data_get($payload, 'prebid.build.version'));
        $this->assertStringContainsString('public-safe-placement', $json, 'Public bidder placement parameters belong in the browser config.');
        foreach (['gam_connection_id', 'credential', 'api_token', 'client_secret', 'private_key', 'refresh_token', 'remote_object_id'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, strtolower($json));
        }
    }

    public function test_existing_gam_profile_and_buckets_remain_connection_owned(): void
    {
        $gam = $this->makeGamConnection($this->horus, $this->admin, [
            'type' => GamConnectionType::HorusGam,
            'driver' => 'MOCK',
            'network_code' => '987654321',
            'is_primary' => true,
            'configuration' => ['root_ad_unit_id' => '1111'],
        ]);
        $manager = app(PrebidManager::class);
        $settings = $manager->updateSettings($gam, [
            'enabled' => true,
            'auction_timeout_ms' => 930,
            'price_granularity' => 'medium',
            'currency' => 'USD',
            'bidder_sequence' => 'fixed',
            'gam_fallback' => true,
        ], $this->admin);
        $originalId = $settings->id;
        $bucketIds = PrebidPriceBucket::withoutGlobalScopes()
            ->where('gam_connection_id', $gam->id)
            ->orderBy('sort_order')
            ->pluck('id')
            ->all();

        $resolved = $manager->settingsFor($gam);

        $this->assertSame($originalId, $resolved->id);
        $this->assertSame(PrebidSetting::SCOPE_GAM_CONNECTION, $resolved->scope);
        $this->assertSame($gam->id, $resolved->gam_connection_id);
        $this->assertNull($resolved->site_id);
        $this->assertSame(930, $resolved->auction_timeout_ms);
        $this->assertSame($bucketIds, PrebidPriceBucket::withoutGlobalScopes()
            ->where('gam_connection_id', $gam->id)
            ->orderBy('sort_order')
            ->pluck('id')
            ->all());
        $this->assertCount(3, $bucketIds);
    }

    public function test_site_mapping_queries_are_tenant_isolated_while_horus_accounts_remain_reusable(): void
    {
        $siteA = $this->site('tenant-a');
        $siteB = $this->site('tenant-b');
        $placementA = $this->placement($siteA, 'slot_a');
        $placementB = $this->placement($siteB, 'slot_b');
        $this->mapPrebid($siteA, $placementA, 'tenant-a-placement');
        $this->mapPrebid($siteB, $placementB, 'tenant-b-placement');
        $manager = app(PrebidManager::class);
        foreach ([$siteA, $siteB] as $site) {
            $manager->updateStandaloneSettings($site, [
                'enabled' => true,
                'auction_timeout_ms' => 1000,
                'currency' => 'USD',
                'bidder_sequence' => 'fixed',
            ], $this->admin);
        }

        $jsonA = json_encode($this->config($siteA, 17), JSON_THROW_ON_ERROR);
        $jsonB = json_encode($this->config($siteB, 18), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('tenant-a-placement', $jsonA);
        $this->assertStringNotContainsString('tenant-b-placement', $jsonA);
        $this->assertStringContainsString('tenant-b-placement', $jsonB);
        $this->assertStringNotContainsString('tenant-a-placement', $jsonB);
    }

    public function test_unsupported_standalone_video_is_not_exposed_as_renderable(): void
    {
        $site = $this->site('video-unsupported');
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => 'Standalone Video',
            'code' => 'standalone_video',
            'sizes' => [['width' => 640, 'height' => 360]],
        ], $this->admin);
        $placement = $inventory->createPlacement($site, [
            'name' => 'Standalone Video',
            'code' => 'standalone_video',
            'type' => 'VIDEO',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 640, 'height' => 360]],
        ], $this->admin);
        $this->mapPrebid($site, $placement, 'unsupported-video');
        app(PrebidManager::class)->updateStandaloneSettings($site, ['enabled' => true], $this->admin);

        $payload = $this->config($site, 19);

        $this->assertFalse($payload['prebid']['enabled']);
        $this->assertSame([], $payload['prebid']['adUnits']);
        $this->assertNotSame('PREBID_STANDALONE', data_get($payload, 'placements.0.renderer'));
    }

    private function site(string $suffix): Site
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Standalone '.$suffix,
            'primary_domain' => $suffix.'.example',
            'prebid_enabled' => true,
        ]);
        $site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => ServingMode::HorusDirect,
            'prebid_enabled' => true,
        ]);
        $site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusDirect,
            'prebid_enabled' => true,
        ]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        return $site->refresh();
    }

    private function placement(Site $site, string $code)
    {
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => ucfirst(str_replace('_', ' ', $code)),
            'code' => $code,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);

        return $inventory->createPlacement($site, [
            'name' => ucfirst(str_replace('_', ' ', $code)),
            'code' => $code,
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
    }

    private function mapPrebid(Site $site, $placement, string $remotePlacement): void
    {
        $manager = app(PrebidManager::class);
        $bidder = PrebidBidder::withoutGlobalScopes()->where('code', 'msft')->firstOrFail();
        $account = $manager->addAccount($bidder, [
            'name' => 'Account '.$site->public_key.' '.$placement->code,
            'enabled' => true,
        ], $this->admin);
        $mapping = $manager->assignToSite($account, $site, ['enabled' => true], $this->admin);
        $manager->assignToPlacement($mapping, $placement, [
            'placement_id_value' => $remotePlacement,
            'enabled' => true,
        ], $this->admin);
    }

    private function config(Site $site, int $version): array
    {
        return app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, $version);
    }
}
