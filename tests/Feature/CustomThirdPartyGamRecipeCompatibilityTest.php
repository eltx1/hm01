<?php

namespace Tests\Feature;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\DemandNetwork;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandConnectorManager;
use App\Services\Inventory\InventoryManager;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class CustomThirdPartyGamRecipeCompatibilityTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_legacy_gam_creative_preserves_reviewed_direct_recipe_precedence(): void
    {
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, DemandNetworkSeeder::class]);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Legacy GAM Publisher');
        $publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['display_name' => 'Legacy GAM Publisher']);
        $site = $this->makeSiteFor($publisher, $publisherUser, [
            'display_name' => 'Legacy GAM Site',
            'primary_domain' => 'legacy-gam.example',
            'native_demand_enabled' => true,
        ]);
        $site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => true,
        ]);
        $site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => true,
        ]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => 'Legacy GAM Unit',
            'code' => 'legacy_gam_unit',
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $admin);
        $placement = $inventory->createPlacement($site, [
            'name' => 'Legacy GAM Placement',
            'code' => 'legacy_gam_placement',
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $admin);

        $network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();
        $service = app(DemandAccountService::class);
        $account = $service->create([
            'organization_id' => $horus->id,
            'demand_network_id' => $network->id,
            'name' => 'Legacy reviewed GAM account',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::GamThirdPartyCreative,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 20,
            'fallback_priority' => 10,
            'account_identifier' => 'legacy-gam-public',
            'configuration' => [
                'allowed_script_origins' => ['https://cdn.taboola.com'],
                'isolation_allowed_origins' => ['https://cdn.taboola.com'],
                'direct_recipe' => [
                    'executionMode' => 'STRUCTURED',
                    'format' => 'DISPLAY',
                    'scripts' => [[
                        'url' => 'https://cdn.taboola.com/reviewed.js',
                        'async' => true,
                    ]],
                    'container' => [
                        'element' => 'div',
                        'id' => 'reviewed-zone',
                        'class' => 'reviewed-zone',
                        'attributes' => [],
                    ],
                    'initialization' => ['type' => 'NONE', 'parameters' => []],
                    'render' => [
                        'timeoutMs' => 2500,
                        'allowedFormats' => ['DISPLAY'],
                        'allowedSizes' => [[300, 250]],
                    ],
                ],
            ],
        ], $admin);
        $demandSite = $service->assignSite($account, $site, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::GamThirdPartyCreative,
        ], $admin);
        $demandPlacement = $service->assignPlacement($demandSite, $placement, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::GamThirdPartyCreative,
            'placement_code' => 'legacy-gam-zone',
        ], $admin);
        $service->upsertWidget($demandPlacement, [
            'name' => 'Legacy raw widget',
            'widget_code' => 'legacy-gam-zone',
            'integration_mode' => DemandIntegrationMode::GamThirdPartyCreative,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'direct_tag_template' => '<div id="raw-zone"></div><script src="https://cdn.taboola.com/raw.js"></script>',
            'configuration' => [],
        ], $admin);

        $creative = app(DemandConnectorManager::class)
            ->for($account->refresh())
            ->generateGamCreative($demandPlacement->refresh());

        $this->assertStringContainsString('reviewed-zone', $creative['snippet']);
        $this->assertStringContainsString('https://cdn.taboola.com/reviewed.js', $creative['snippet']);
        $this->assertStringNotContainsString('raw-zone', $creative['snippet']);
        $this->assertStringNotContainsString('https://cdn.taboola.com/raw.js', $creative['snippet']);
    }
}
