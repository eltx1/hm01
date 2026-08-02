<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\PlacementStatus;
use App\Enums\PlacementType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Models\GamRemoteObject;
use App\Models\AdFormat;
use Database\Seeders\AdFormatSeeder;
use App\Services\Gam\GamConnectionService;
use App\Services\Inventory\AdUnitSyncService;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\StaticDelivery\StaticDeliveryManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class InventoryConfigurationTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private string $staticRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staticRoot = storage_path('framework/testing/horus-configs-'.bin2hex(random_bytes(4)));
        config([
            'horus.cdn_url' => 'https://cdn.horusmedia.net',
            'static-delivery.local_root' => $this->staticRoot,
            'static-delivery.batch_delay_seconds' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->staticRoot);
        parent::tearDown();
    }

    public function test_new_site_defaults_to_horus_gam_and_generated_configuration_uses_primary_network(): void
    {
        [$site, $admin, $connection] = $this->siteWithPrimaryHorus();
        $this->seed(AdFormatSeeder::class);
        $format = AdFormat::query()->where('code', 'display_banner')->firstOrFail();
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => 'Article Top', 'code' => 'article_top',
            'sizes' => [['width' => 300, 'height' => 250], ['width' => 728, 'height' => 90]],
        ], $admin);
        $inventory->createPlacement($site, [
            'name' => 'Article Top', 'code' => 'article_top', 'type' => PlacementType::Display->value,
            'status' => PlacementStatus::Active->value, 'ad_unit_id' => $adUnit->id,
            'ad_format_id' => $format->id, 'format_settings' => ['reserveSpace' => true],
            'sizes' => [['width' => 300, 'height' => 250], ['width' => 728, 'height' => 90]],
        ], $admin);

        $config = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1);

        $this->assertSame(ServingMode::HorusGam, $site->serving_mode);
        $this->assertSame($connection->network_code, $config['gamNetworkCode']);
        $this->assertSame(2, $config['schemaVersion']);
        $this->assertSame('HORUS_GAM', $config['servingMode']);
        $this->assertSame('display_banner', $config['placements'][0]['format']['code']);
        $this->assertTrue($config['placements'][0]['format']['settings']['reserveSpace']);
        $this->assertSame('/'.$connection->network_code.'/article_top', $config['placements'][0]['adUnitPath']);
        $this->assertContains($site->primary_domain, $config['allowedHostnames']);
        $this->assertStringContainsString('hm-loader.js', $site->installationCode());
        $this->assertStringContainsString('data-site-key="'.$site->public_key.'"', $site->installationCode());
    }

    public function test_switching_selected_gam_changes_static_configuration_without_changing_installation_code(): void
    {
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $beforeCode = $site->installationCode();
        $partner = $this->makeGamConnection($admin->organization, $admin, [
            'type' => GamConnectionType::McmPartnerGam,
            'is_primary' => false,
            'network_code' => '987654321',
            'configuration' => ['root_ad_unit_id' => '2222'],
        ]);

        app(GamConnectionService::class)->assignToSite($site, $partner, $admin, 'Switch this site only.');
        $config = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 2);

        $this->assertSame('MCM_PARTNER_GAM', $config['servingMode']);
        $this->assertSame('987654321', $config['gamNetworkCode']);
        $this->assertSame($beforeCode, $site->refresh()->installationCode());
    }

    public function test_publish_and_rollback_create_versioned_and_current_static_files(): void
    {
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, ['name' => 'Inline', 'code' => 'inline', 'sizes' => [['width' => 300, 'height' => 250]]], $admin);
        $placement = $inventory->createPlacement($site, [
            'name' => 'Inline', 'code' => 'inline', 'type' => 'DISPLAY', 'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 300, 'height' => 250]],
        ], $admin);
        $publisher = app(SiteConfigPublisher::class);
        $versionOne = $publisher->publish($site, ConfigEnvironment::Production, $admin);
        $inventory->updatePlacement($placement, ['status' => 'PAUSED'], $admin);
        $versionTwo = $publisher->publish($site->refresh(), ConfigEnvironment::Production, $admin);
        $rollback = $publisher->rollback($site->refresh(), ConfigEnvironment::Production, $versionOne, $admin);
        app(StaticDeliveryManager::class)->processPending();

        $this->assertSame(1, $versionOne->version);
        $this->assertSame(2, $versionTwo->version);
        $this->assertSame(3, $rollback->version);
        $this->assertSame($versionOne->id, $rollback->source_version_id);
        $this->assertSame('active', $rollback->payload['placements'][0]['status']);
        $this->assertNotEmpty(glob($this->staticRoot.'/configs/'.$site->public_key.'/production.v1.*.json'));
        $this->assertNotEmpty(glob($this->staticRoot.'/configs/'.$site->public_key.'/production.v3.*.json'));
        $this->assertFileExists($this->staticRoot.'/configs/'.$site->public_key.'/production.json');
        $this->assertFileExists($this->staticRoot.'/configs/'.$site->public_key.'/manifest.json');
        $current = json_decode((string) file_get_contents($this->staticRoot.'/configs/'.$site->public_key.'/production.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(3, $current['configVersion']);
        $this->assertSame(1, $current['rollbackSourceVersion']);
    }

    public function test_pause_and_disabled_placement_are_reflected_without_private_values(): void
    {
        [$site, $admin] = $this->siteWithPrimaryHorus();
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, ['name' => 'Sidebar', 'code' => 'sidebar', 'sizes' => [['width' => 300, 'height' => 250]]], $admin);
        $inventory->createPlacement($site, [
            'name' => 'Sidebar', 'code' => 'sidebar', 'type' => 'DISPLAY', 'status' => 'DISABLED',
            'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 300, 'height' => 250]],
        ], $admin);
        $version = app(SiteConfigPublisher::class)->pauseImmediately($site, $admin);
        $encoded = json_encode($version->payload, JSON_THROW_ON_ERROR);

        $this->assertSame('paused', $version->payload['status']);
        $this->assertTrue($version->payload['immediatePause']);
        $this->assertFalse($version->payload['placements'][0]['enabled']);
        $this->assertStringNotContainsString('private_key', $encoded);
        $this->assertStringNotContainsString('credential', strtolower($encoded));
    }

    public function test_ad_unit_sync_is_dry_run_safe_idempotent_and_detects_changes(): void
    {
        [$site, $admin, $connection] = $this->siteWithPrimaryHorus();
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, ['name' => 'Hero', 'code' => 'hero', 'sizes' => [['width' => 970, 'height' => 250]]], $admin);
        $sync = app(AdUnitSyncService::class);

        $dryRun = $sync->sync($adUnit, $admin, true);
        $created = $sync->sync($adUnit, $admin, false);
        $duplicate = $sync->sync($adUnit->refresh(), $admin, false);
        $adUnit->sizes()->create(['size_type' => 'FIXED', 'width' => 728, 'height' => 90, 'is_active' => true]);
        $difference = $sync->difference($adUnit->refresh());
        $updated = $sync->sync($adUnit->refresh(), $admin, false, true);

        $this->assertTrue($dryRun->dryRun);
        $this->assertTrue($created->success);
        $this->assertTrue($duplicate->duplicate);
        $this->assertTrue($difference['different']);
        $this->assertTrue($updated->success);
        $this->assertDatabaseHas('gam_remote_objects', [
            'gam_connection_id' => $connection->id,
            'local_object_type' => 'ad_unit',
            'local_object_id' => $adUnit->id,
            'remote_object_id' => '1001',
        ]);
        $this->assertSame(1, GamRemoteObject::withoutGlobalScopes()->where('local_object_id', $adUnit->id)->count());
    }

    public function test_bulk_creation_and_layout_duplication_copy_local_configuration_only(): void
    {
        [$source, $admin] = $this->siteWithPrimaryHorus();
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $target = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser);
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($source, ['name' => 'Top', 'code' => 'top', 'sizes' => [['width' => 728, 'height' => 90]]], $admin);
        $inventory->bulkCreatePlacements($source, [[
            'name' => 'Top', 'code' => 'top', 'type' => 'DISPLAY', 'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 728, 'height' => 90]],
            'targeting' => ['position' => ['top']],
        ]], $admin);
        $profile = $inventory->duplicateLayout($source->refresh(), $target, $admin);

        $this->assertSame(1, $target->adUnits()->count());
        $this->assertSame(1, $target->placements()->count());
        $this->assertSame('top', $target->placements()->first()->code);
        $this->assertSame($source->id, $profile->source_site_id);
        $this->assertDatabaseMissing('gam_remote_objects', ['local_object_id' => $target->adUnits()->first()->id]);
    }

    private function siteWithPrimaryHorus(): array
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $connection = $this->makeGamConnection($horus, $admin, [
            'type' => GamConnectionType::HorusGam,
            'network_code' => '123456789',
            'driver' => 'MOCK',
            'is_primary' => true,
            'configuration' => ['root_ad_unit_id' => '1111'],
        ]);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser, ['primary_domain' => 'publisher.example']);

        return [$site, $admin, $connection];
    }
}
