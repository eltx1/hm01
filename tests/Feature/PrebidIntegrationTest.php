<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Enums\PrebidPriceGranularity;
use App\Enums\RoleName;
use App\Models\GamRemoteObject;
use App\Models\PrebidAdapter;
use App\Models\PrebidBuild;
use App\Models\PrebidGamRemoteObject;
use App\Models\PrebidPriceBucket;
use App\Services\Gam\Contracts\GamSoapTransportInterface;
use App\Services\Gam\GamConnectionService;
use App\Services\Inventory\AdUnitSyncService;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Prebid\PrebidGamAutomationService;
use App\Services\Prebid\PrebidGamTemplateFactory;
use App\Services\Prebid\PrebidSettingsManager;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\Fakes\SequencedGamSoapTransport;
use Tests\TestCase;

class PrebidIntegrationTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private SequencedGamSoapTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new SequencedGamSoapTransport;
        $this->app->instance(GamSoapTransportInterface::class, $this->transport);
    }

    public function test_registry_and_custom_build_are_seeded_without_runtime_node_dependency(): void
    {
        $this->seed([PrebidSeeder::class]);

        $this->assertDatabaseHas('prebid_builds', [
            'version' => 'horus-11.14.0-1',
            'prebid_version' => '11.14.0',
            'is_active' => true,
        ]);
        $this->assertSame(5, PrebidAdapter::query()->where('is_enabled', true)->count());
        $this->assertContains('appnexusBidAdapter', PrebidBuild::withoutGlobalScopes()->where('is_active', true)->firstOrFail()->modules);
    }

    public function test_horus_site_static_config_contains_public_bidder_data_and_disabling_account_removes_it(): void
    {
        [$site, $admin, $connection, $adUnit, $placement] = $this->prebidReadySite();
        $manager = app(PrebidSettingsManager::class);
        $bidder = \App\Models\PrebidBidder::withoutGlobalScopes()->where('code', 'appnexus')->firstOrFail();
        $account = $manager->createAccount($bidder, [
            'organization_id' => $admin->organization_id,
            'name' => 'Horus Xandr',
            'publisher_id' => '1010',
            'public_parameters' => [],
            'is_enabled' => true,
        ], $admin);
        $siteMapping = $manager->assignAccountToSite($account, $site, [
            'publisher_id' => '2020', 'sequence' => 10, 'is_enabled' => true,
        ], $admin);
        $manager->assignToPlacement($siteMapping, $placement, [
            'placement_id_value' => '3030', 'sequence' => 10, 'is_enabled' => true,
        ], $admin);

        $config = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1);

        $this->assertTrue($config['prebidEnabled']);
        $this->assertSame($connection->network_code, $config['gamNetworkCode']);
        $this->assertSame('horus-11.14.0-1', $config['prebid']['build']['version']);
        $this->assertSame('appnexus', $config['prebid']['adUnits']['article_top']['bids'][0]['bidder']);
        $this->assertSame('3030', $config['prebid']['adUnits']['article_top']['bids'][0]['params']['placementId']);
        $encoded = json_encode($config, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('credential', strtolower($encoded));
        $this->assertStringNotContainsString('private_key', strtolower($encoded));

        $manager->setAccountEnabled($account, false, $admin);
        $disabled = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 2);

        $this->assertFalse($disabled['prebidEnabled']);
        $this->assertSame([], $disabled['prebid']['adUnits']);
        $this->assertSame($site->installationCode(), $site->refresh()->installationCode());
    }

    public function test_switching_gam_network_changes_prebid_setup_key_without_changing_loader_code(): void
    {
        [$site, $admin, $horus] = $this->prebidReadySite();
        $templates = app(PrebidGamTemplateFactory::class);
        $templates->ensureForConnection($horus);
        $beforeCode = $site->installationCode();
        $before = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1);

        $partner = $this->makeGamConnection($admin->organization, $admin, [
            'type' => GamConnectionType::McmPartnerGam,
            'is_primary' => false,
            'network_code' => '987654321',
            'configuration' => ['root_ad_unit_id' => '2222', 'trafficker_id' => '8888', 'currency' => 'USD'],
        ]);
        $templates->ensureForConnection($partner);
        app(GamConnectionService::class)->assignToSite($site, $partner, $admin, 'Use partner network for this website.');
        $after = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 2);

        $this->assertSame('123456789', $before['gamNetworkCode']);
        $this->assertSame('987654321', $after['gamNetworkCode']);
        $this->assertNotSame($before['prebid']['gamSetup']['key'], $after['prebid']['gamSetup']['key']);
        $this->assertSame($beforeCode, $site->refresh()->installationCode());
    }

    public function test_gam_setup_requires_preview_confirmation_resumes_and_never_duplicates_objects(): void
    {
        [$site, $admin, $connection, $adUnit] = $this->prebidReadySite();
        $setting = app(PrebidSettingsManager::class)->ensureForSite($site);
        $setting->update(['price_granularity' => PrebidPriceGranularity::Custom]);
        PrebidPriceBucket::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id,
            'prebid_setting_id' => $setting->id,
            'label' => 'single-test-price',
            'minimum' => 1,
            'maximum' => 1,
            'increment' => 1,
            'precision' => 2,
            'priority' => 1,
            'is_enabled' => true,
        ]);

        app(AdUnitSyncService::class)->sync($adUnit, $admin, false);
        $automation = app(PrebidGamAutomationService::class);
        $preview = $automation->preview($connection, $admin, $site);
        $run = $preview['run'];

        $this->assertSame('PREVIEW', $run->status->value);
        $this->assertTrue($run->dry_run);
        $this->assertTrue(data_get($run->plan, 'complete'));
        $this->assertSame(11, data_get($run->plan, 'estimates.totalObjects'));
        $this->assertCount(1, $this->transport->calls);

        try {
            $automation->executeBatch($run, $admin, 'WRONG-CODE', 100);
            $this->fail('Invalid confirmation code should be rejected.');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame('PREVIEW', $run->refresh()->status->value);
        }

        $completed = $automation->executeBatch($run, $admin, $preview['confirmationToken'], 100);
        $callsAfterFirstRun = count($this->transport->calls);
        $sameRun = $automation->executeBatch($completed, $admin, null, 100);

        $this->assertSame('SUCCEEDED', $completed->status->value);
        $this->assertSame(11, PrebidGamRemoteObject::withoutGlobalScopes()->where('gam_connection_id', $connection->id)->count());
        $this->assertSame($callsAfterFirstRun, count($this->transport->calls));
        $this->assertSame('SUCCEEDED', $sameRun->status->value);

        $secondPreview = $automation->preview($connection, $admin, $site);
        $this->assertSame(0, data_get($secondPreview['run']->plan, 'estimates.pendingObjects'));
        $automation->executeBatch($secondPreview['run'], $admin, $secondPreview['confirmationToken'], 100);
        $this->assertSame($callsAfterFirstRun, count($this->transport->calls));
        $this->assertSame(11, PrebidGamRemoteObject::withoutGlobalScopes()->count());
        $this->assertSame(11, GamRemoteObject::withoutGlobalScopes()->where('local_object_type', 'prebid_setup')->count());
    }

    public function test_publish_static_configuration_contains_no_backend_event_endpoint(): void
    {
        [$site] = $this->prebidReadySite();
        $config = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1);
        $encoded = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->assertStringContainsString('https://cdn.horusmedia.net/assets/prebid/horus-prebid.min.js', $encoded);
        $this->assertStringNotContainsString('/api/impression', $encoded);
        $this->assertStringNotContainsString('/api/bid', $encoded);
        $this->assertStringNotContainsString(config('app.url').'/api', $encoded);
    }

    private function prebidReadySite(): array
    {
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, PrebidSeeder::class]);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($horus, $admin, [
            'type' => GamConnectionType::HorusGam,
            'network_code' => '123456789',
            'is_primary' => true,
            'dry_run_default' => false,
            'configuration' => ['root_ad_unit_id' => '1111', 'trafficker_id' => '7777', 'currency' => 'USD'],
        ]);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser, [
            'primary_domain' => 'publisher.example',
            'prebid_enabled' => true,
        ]);
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => 'Article Top', 'code' => 'article_top', 'sizes' => [['width' => 300, 'height' => 250]],
        ], $admin);
        $placement = $inventory->createPlacement($site, [
            'name' => 'Article Top', 'code' => 'article_top', 'type' => PlacementType::Display->value,
            'status' => PlacementStatus::Active->value, 'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $admin);
        app(PrebidSettingsManager::class)->ensureForSite($site);
        app(PrebidGamTemplateFactory::class)->ensureForConnection($connection);

        return [$site, $admin, $connection, $adUnit, $placement];
    }
}
