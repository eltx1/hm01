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
use App\Models\PrebidAdapter;
use App\Models\PrebidBidder;
use App\Models\PrebidBuild;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandConnectorManager;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Prebid\PrebidManager;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class GamLessPilotReadinessTest extends TestCase
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
        $org = $this->makeOrganization(OrganizationType::Publisher, 'Pilot Publisher');
        $this->publisherUser = $this->makeUser($org, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Pilot Publisher']);
    }

    public function test_pilot_a_standalone_prebid_only_has_no_gam_or_direct_renderer(): void
    {
        $site = $this->site('pilot-a', ServingMode::HorusDirect, true, false);
        $placement = $this->placement($site, 'pilot_a_prebid');
        $this->mapPrebid($site, $placement, 'msft', standalone: true, public: ['placement_id' => 'TEST_ONLY_PLACEMENT']);

        $payload = $this->config($site);
        $row = collect($payload['placements'])->firstWhere('code', 'pilot_a_prebid');
        $this->assertFalse(data_get($payload, 'engines.gam.enabled'));
        $this->assertTrue(data_get($payload, 'engines.prebid.enabled'));
        $this->assertSame('STANDALONE', data_get($payload, 'engines.prebid.deliveryMode'));
        $this->assertFalse(data_get($payload, 'engines.directJs.enabled'));
        $this->assertSame('PREBID_STANDALONE', $row['renderer']);
        $this->assertNull($payload['gamNetworkCode']);
    }

    public function test_pilot_b_direct_js_only_has_no_gam_or_prebid_dependency(): void
    {
        $site = $this->site('pilot-b', ServingMode::HorusDirect, false, true);
        $placement = $this->placement($site, 'pilot_b_direct');
        $this->mapDirect($site, $placement);

        $payload = $this->config($site);
        $row = collect($payload['placements'])->firstWhere('code', 'pilot_b_direct');
        $this->assertFalse(data_get($payload, 'engines.gam.enabled'));
        $this->assertFalse(data_get($payload, 'engines.prebid.enabled'));
        $this->assertTrue(data_get($payload, 'engines.directJs.enabled'));
        $this->assertSame('DIRECT_JS', $row['renderer']);
        $this->assertNull($payload['gamNetworkCode']);
    }

    public function test_pilot_c_standalone_prebid_and_direct_js_own_distinct_surfaces(): void
    {
        $site = $this->site('pilot-c', ServingMode::HorusDirect, true, true);
        $prebid = $this->placement($site, 'pilot_c_prebid');
        $direct = $this->placement($site, 'pilot_c_direct');
        $this->mapPrebid($site, $prebid, 'msft', standalone: true, public: ['placement_id' => 'TEST_ONLY_PLACEMENT']);
        $this->mapDirect($site, $direct);

        $payload = $this->config($site);
        $rows = collect($payload['placements'])->keyBy('code');
        $this->assertSame('PREBID_STANDALONE', $rows['pilot_c_prebid']['renderer']);
        $this->assertSame('DIRECT_JS', $rows['pilot_c_direct']['renderer']);
        $this->assertFalse($rows['pilot_c_prebid']['rendererConflict']);
        $this->assertFalse($rows['pilot_c_direct']['rendererConflict']);
    }

    public function test_pilot_d_existing_gam_and_prebid_bridge_remain_gam_owned(): void
    {
        $site = $this->site('pilot-d', ServingMode::HorusGam, true, false);
        $placement = $this->placement($site, 'pilot_d_bridge');
        $this->mapPrebid($site, $placement, 'msft', standalone: false, public: ['placement_id' => 'TEST_ONLY_PLACEMENT']);

        $payload = $this->config($site);
        $row = collect($payload['placements'])->firstWhere('code', 'pilot_d_bridge');
        $this->assertTrue(data_get($payload, 'engines.gam.enabled'));
        $this->assertTrue(data_get($payload, 'engines.prebid.enabled'));
        $this->assertSame('GAM_BRIDGE', data_get($payload, 'engines.prebid.deliveryMode'));
        $this->assertSame('GAM', $row['renderer']);
        $this->assertSame('246813579', $payload['gamNetworkCode']);
    }

    public function test_pilot_e_gam_bridge_and_direct_js_use_independent_surfaces(): void
    {
        $site = $this->site('pilot-e', ServingMode::HorusGam, true, true);
        $bridge = $this->placement($site, 'pilot_e_bridge');
        $direct = $this->placement($site, 'pilot_e_direct', gamEligible: false);
        $this->mapPrebid($site, $bridge, 'msft', standalone: false, public: ['placement_id' => 'TEST_ONLY_PLACEMENT']);
        $this->mapDirect($site, $direct);

        $payload = $this->config($site);
        $rows = collect($payload['placements'])->keyBy('code');
        $this->assertSame('GAM', $rows['pilot_e_bridge']['renderer']);
        $this->assertSame('DIRECT_JS', $rows['pilot_e_direct']['renderer']);
        $this->assertTrue(data_get($payload, 'engines.gam.enabled'));
        $this->assertTrue(data_get($payload, 'engines.prebid.enabled'));
        $this->assertTrue(data_get($payload, 'engines.directJs.enabled'));
    }

    public function test_onetag_is_optional_in_pinned_build_and_requires_operator_supplied_pub_id(): void
    {
        $adapter = PrebidAdapter::query()->where('code', 'onetag')->firstOrFail();
        $bidder = PrebidBidder::query()->where('code', 'onetag')->firstOrFail();
        $build = PrebidBuild::query()->where('is_active', true)->latest('built_at')->firstOrFail();
        $this->assertSame('onetagBidAdapter', $adapter->module_code);
        $this->assertSame(['pubId'], $adapter->required_public_parameters);
        $this->assertContains('onetagBidAdapter', $build->modules);
        $this->assertSame([], $bidder->default_public_parameters ?? []);

        $site = $this->site('onetag-pilot', ServingMode::HorusDirect, true, false);
        $placement = $this->placement($site, 'onetag_slot');
        $this->mapPrebid($site, $placement, 'onetag', standalone: true, public: []);
        $this->assertFalse(data_get($this->config($site), 'engines.prebid.enabled'), 'Missing required OneTag pubId must fail closed.');

        $site2 = $this->site('onetag-pilot-ok', ServingMode::HorusDirect, true, false);
        $placement2 = $this->placement($site2, 'onetag_slot_ok');
        $this->mapPrebid($site2, $placement2, 'onetag', standalone: true, public: ['pubId' => 'TEST_ONLY_OPERATOR_VALUE']);
        $payload = $this->config($site2);
        $bid = data_get($payload, 'prebid.adUnits.0.bids.0');
        $this->assertTrue(data_get($payload, 'engines.prebid.enabled'));
        $this->assertSame('onetag', data_get($bid, 'bidder'));
        $this->assertSame('TEST_ONLY_OPERATOR_VALUE', data_get($bid, 'params.pubId'));
    }

    public function test_exoclick_official_async_banner_pattern_parses_to_trusted_recipe_and_rogue_code_fails(): void
    {
        $network = DemandNetwork::query()->where('code', 'EXOCLICK')->firstOrFail();
        $service = app(DemandAccountService::class);
        $account = $service->create([
            'organization_id' => $this->horus->id,
            'demand_network_id' => $network->id,
            'name' => 'ExoClick pilot parser',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 20,
            'fallback_priority' => 10,
            'account_identifier' => 'TEST_ONLY_PUBLIC_ACCOUNT',
            'configuration' => [],
        ], $this->admin);
        $connector = app(DemandConnectorManager::class)->for($account);

        $safe = $connector->parseDirectTag(<<<'HTML'
<script async type="application/javascript" src="https://a.magsrv.com/ad-provider.js"></script>
<ins class="TEST_PROVIDER_CLASS" data-zoneid="TEST_ZONE_ID"></ins>
<script>(AdProvider = window.AdProvider || []).push({"serve": {}});</script>
HTML);
        $this->assertTrue($safe['safe']);
        $this->assertSame('EXOCLICK_SERVE', data_get($safe, 'recipe.initialization.type'));
        $this->assertSame('ins', data_get($safe, 'recipe.container.element'));
        $this->assertSame('TEST_ZONE_ID', data_get($safe, 'recipe.container.attributes.data-zoneid'));
        $this->assertSame('TEST_ZONE_ID', data_get($safe, 'recipe.publicPlacementId'));

        $rogueOrigin = $connector->parseDirectTag('<script async src="https://evil.example/ad-provider.js"></script><ins class="x" data-zoneid="z"></ins><script>(AdProvider=window.AdProvider||[]).push({serve:{}});</script>');
        $this->assertFalse($rogueOrigin['safe']);
        $this->assertNotEmpty($rogueOrigin['securityWarnings']);

        $rogueInline = $connector->parseDirectTag('<script async src="https://a.magsrv.com/ad-provider.js"></script><ins class="x" data-zoneid="z"></ins><script>window.top.location="https://evil.example";</script>');
        $this->assertFalse($rogueInline['safe']);
    }

    public function test_adsterra_is_generic_reviewed_only_without_invented_provider_tag_assumptions(): void
    {
        $custom = DemandNetwork::query()->where('code', 'CUSTOM_DISPLAY')->firstOrFail();
        $this->assertSame([], $custom->script_origins ?? []);
        $this->assertNotSame('ADSTERRA', $custom->code);
        $this->assertFalse(DemandNetwork::query()->where('code', 'ADSTERRA')->exists());
    }

    private function site(string $key, ServingMode $mode, bool $prebid, bool $direct)
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => strtoupper($key), 'primary_domain' => $key.'.pilot.test',
            'prebid_enabled' => $prebid, 'native_demand_enabled' => $direct,
        ]);
        $site->update(['status' => SiteStatus::Active, 'serving_mode' => $mode, 'prebid_enabled' => $prebid, 'native_demand_enabled' => $direct]);
        $site->servingSettings()->update(['serving_mode' => $mode, 'prebid_enabled' => $prebid, 'native_demand_enabled' => $direct]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);
        return $site->refresh();
    }

    private function placement($site, string $code, bool $gamEligible = true)
    {
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, ['name' => $code, 'code' => $code, 'sizes' => [['width' => 300, 'height' => 250]]], $this->admin);
        if (! $gamEligible) $adUnit->update(['is_enabled' => false]);
        return $inventory->createPlacement($site, ['name' => $code, 'code' => $code, 'type' => 'DISPLAY', 'status' => 'ACTIVE', 'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 300, 'height' => 250]]], $this->admin);
    }

    private function mapPrebid($site, $placement, string $bidderCode, bool $standalone, array $public): void
    {
        $manager = app(PrebidManager::class);
        $bidder = PrebidBidder::query()->where('code', $bidderCode)->firstOrFail();
        $account = $manager->addAccount($bidder, ['name' => 'Pilot '.$site->public_key.' '.$bidderCode, 'enabled' => true, 'public_parameters' => $public], $this->admin);
        $mapping = $manager->assignToSite($account, $site, ['enabled' => true], $this->admin);
        $manager->assignToPlacement($mapping, $placement, ['enabled' => true], $this->admin);
        $settings = ['enabled' => true, 'auction_timeout_ms' => 1200, 'price_granularity' => 'medium', 'currency' => 'USD', 'bidder_sequence' => 'fixed', 'gam_fallback' => ! $standalone];
        if ($standalone) $manager->updateStandaloneSettings($site, $settings, $this->admin);
        else $manager->updateSettings($this->gam, $settings, $this->admin);
    }

    private function mapDirect($site, $placement): void
    {
        $network = DemandNetwork::query()->where('code', 'MGID')->firstOrFail();
        $service = app(DemandAccountService::class);
        $account = $service->create([
            'organization_id' => $this->horus->id, 'demand_network_id' => $network->id,
            'name' => 'Pilot Direct '.$placement->code, 'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs, 'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true, 'is_default' => false, 'revenue_share_percent' => 20, 'fallback_priority' => 10,
            'account_identifier' => 'TEST_ONLY_PUBLIC_ACCOUNT',
            'configuration' => ['script_url' => 'https://jsc.mgid.com/pilot/'.$placement->code.'.js', 'container_id' => 'widget-'.$placement->code, 'render_timeout_ms' => 500],
        ], $this->admin);
        $mapping = $service->assignSite($account, $site, ['approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'integration_mode' => DemandIntegrationMode::DirectJs], $this->admin);
        $directPlacement = $service->assignPlacement($mapping, $placement, ['approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'integration_mode' => DemandIntegrationMode::DirectJs, 'remote_placement_id' => 'TEST_ONLY_PLACEMENT', 'placement_code' => 'widget-'.$placement->code], $this->admin);
        $service->upsertWidget($directPlacement, ['name' => 'Pilot widget', 'remote_widget_id' => 'TEST_ONLY_WIDGET', 'widget_code' => 'widget-'.$placement->code, 'integration_mode' => DemandIntegrationMode::DirectJs, 'approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'configuration' => []], $this->admin);
    }

    private function config($site): array
    {
        return app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 2000 + random_int(1, 500));
    }
}
