<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\PrebidBidder;
use App\Models\PrebidGamRemoteObject;
use App\Models\PrebidPriceBucket;
use App\Services\Gam\GamConnectionService;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Prebid\PrebidGamSetupService;
use App\Services\Prebid\PrebidManager;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PrebidIntegrationTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_selected_gam_network_controls_prebid_configuration_and_bidder_disable_is_static(): void
    {
        [$site, $admin, $horus] = $this->siteWithPrimaryHorus();
        $this->seed(PrebidSeeder::class);
        $site->update(['prebid_enabled' => true]);

        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, ['name' => 'Top', 'code' => 'top', 'sizes' => [['width' => 300, 'height' => 250]]], $admin);
        $placement = $inventory->createPlacement($site, [
            'name' => 'Top', 'code' => 'top', 'type' => 'DISPLAY', 'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 300, 'height' => 250]],
        ], $admin);

        $manager = app(PrebidManager::class);
        $manager->updateSettings($horus, [
            'enabled' => true, 'auction_timeout_ms' => 900, 'price_granularity' => 'medium',
            'currency' => 'USD', 'bidder_sequence' => 'fixed', 'gam_fallback' => true,
        ], $admin);
        $bidder = PrebidBidder::withoutGlobalScopes()->where('code', 'msft')->firstOrFail();
        $account = $manager->addAccount($bidder, ['name' => 'Primary', 'enabled' => true], $admin);
        $siteMapping = $manager->assignToSite($account, $site, ['enabled' => true], $admin);
        $placementMapping = $manager->assignToPlacement($siteMapping, $placement, ['placement_id_value' => '42', 'enabled' => true], $admin);

        $central = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1);
        $this->assertTrue($central['prebidEnabled']);
        $this->assertSame('HORUS_GAM', $central['servingMode']);
        $this->assertSame('msft', $central['prebid']['adUnits'][0]['bids'][0]['bidder']);
        $this->assertSame('42', $central['prebid']['adUnits'][0]['bids'][0]['params']['placement_id']);

        $manager->toggle($placementMapping, false, $admin);
        $disabled = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 2);
        $this->assertFalse($disabled['prebidEnabled']);
        $this->assertSame([], $disabled['prebid']['adUnits']);

        $manager->toggle($placementMapping, true, $admin);
        $partner = $this->makeGamConnection($admin->organization, $admin, [
            'type' => GamConnectionType::McmPartnerGam,
            'is_primary' => false,
            'network_code' => '987654321',
            'configuration' => ['root_ad_unit_id' => '2222'],
        ]);
        app(GamConnectionService::class)->assignToSite($site, $partner, $admin, 'Use partner network.');
        $separate = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 3);
        $this->assertSame('MCM_PARTNER_GAM', $separate['servingMode']);
        $this->assertFalse($separate['prebidEnabled']);

        $manager->updateSettings($partner, [
            'enabled' => true, 'auction_timeout_ms' => 1100, 'price_granularity' => 'custom',
            'currency' => 'EUR', 'bidder_sequence' => 'random', 'gam_fallback' => true,
        ], $admin);
        $partnerEnabled = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 4);
        $this->assertTrue($partnerEnabled['prebidEnabled']);
        $this->assertSame('EUR', $partnerEnabled['prebid']['auction']['currency']);
        $this->assertSame('987654321', $partnerEnabled['gamNetworkCode']);
    }

    public function test_gam_setup_is_previewable_confirmed_resumable_and_idempotent(): void
    {
        [, $admin, $connection] = $this->siteWithPrimaryHorus();
        $this->seed(PrebidSeeder::class);
        $manager = app(PrebidManager::class);
        $manager->settingsFor($connection);

        PrebidPriceBucket::withoutGlobalScopes()->where('gam_connection_id', $connection->id)->delete();
        PrebidPriceBucket::withoutGlobalScopes()->create([
            'organization_id' => $connection->organization_id,
            'gam_connection_id' => $connection->id,
            'code' => 'test',
            'minimum' => 0,
            'maximum' => .10,
            'increment' => .05,
            'precision' => 2,
            'enabled' => true,
        ]);

        $service = app(PrebidGamSetupService::class);
        $preview = $service->preview($connection);
        $this->assertSame(14, $preview['estimatedObjects']);
        $this->assertSame(14, $preview['pendingObjects']);

        $dryRun = $service->start($connection, $admin, true, false);
        $this->assertSame('DRY_RUN', $dryRun->status);

        $completed = $service->start($connection, $admin, false, true);
        $this->assertSame('SUCCEEDED', $completed->status);
        $this->assertSame(14, $completed->completed_objects);
        $this->assertSame(14, PrebidGamRemoteObject::withoutGlobalScopes()->where('gam_connection_id', $connection->id)->count());

        $rerun = $service->start($connection, $admin, false, true);
        $this->assertSame('SUCCEEDED', $rerun->status);
        $this->assertSame(0, $service->preview($connection)['pendingObjects']);
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
