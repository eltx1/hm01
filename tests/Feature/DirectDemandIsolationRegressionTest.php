<?php

namespace Tests\Feature;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandPlacement;
use App\Models\DemandWidget;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Inventory\InventoryManager;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class DirectDemandIsolationRegressionTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $publisherUser;
    private $publisher;
    private $site;
    private $placement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, DemandNetworkSeeder::class]);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Isolation Publisher');
        $this->publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Isolation Publisher']);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Isolation Site',
            'primary_domain' => 'isolation.example',
            'native_demand_enabled' => false,
        ]);
        $this->site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => false,
        ]);
        $this->site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => false,
        ]);
        $this->site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($this->site, [
            'name' => 'Single Size',
            'code' => 'single_size',
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
        $this->placement = $inventory->createPlacement($this->site, [
            'name' => 'Single Size',
            'code' => 'single_size',
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
    }

    public function test_large_quick_generic_tag_is_chunked_without_public_attribute_truncation(): void
    {
        $padding = str_repeat('provider-public-padding-', 220);
        $tag = '<script async src="https://cdn.taboola.com/libtrc/horus-test/loader.js"></script>'
            .'<!--'.$padding.'--><div id="taboola-large-zone"></div>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), [
                'site_id' => $this->site->id,
                'placement_id' => $this->placement->id,
                'tag' => $tag,
            ])
            ->assertRedirect();

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.single_size.candidates.0');
        $attributes = (array) data_get($candidate, 'tag.container.attributes', []);
        $parts = (int) ($attributes['data-hm-isolated-html-parts'] ?? 0);

        $this->assertSame('STRUCTURED', data_get($candidate, 'tag.executionMode'));
        $this->assertGreaterThan(1, $parts);
        $this->assertArrayNotHasKey('data-hm-isolated-html', $attributes);
        $this->assertTrue(collect($attributes)->every(fn ($value) => strlen((string) $value) <= 2000));

        $encoded = '';
        for ($index = 0; $index < $parts; $index++) {
            $this->assertArrayHasKey('data-hm-isolated-html-'.$index, $attributes);
            $encoded .= $attributes['data-hm-isolated-html-'.$index];
        }
        $this->assertSame($tag, base64_decode($encoded, true));
    }

    public function test_quick_activation_reuses_renamed_managed_widget_and_publishes_the_new_tag(): void
    {
        $firstTag = '<script async src="https://cdn.taboola.com/libtrc/horus-old/loader.js"></script><div id="taboola-old-zone"></div>';
        $secondTag = '<script async src="https://cdn.taboola.com/libtrc/horus-new/loader.js"></script><div id="taboola-new-zone"></div>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), [
                'site_id' => $this->site->id,
                'placement_id' => $this->placement->id,
                'tag' => $firstTag,
            ])
            ->assertRedirect();

        $demandPlacement = DemandPlacement::withoutGlobalScopes()
            ->where('placement_id', $this->placement->id)
            ->firstOrFail();
        $oldWidget = DemandWidget::withoutGlobalScopes()
            ->where('demand_placement_id', $demandPlacement->id)
            ->firstOrFail();
        $oldWidget->update([
            'name' => 'Operator Renamed Old Quick Widget',
            'updated_at' => now()->subMinutes(5),
        ]);

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), [
                'site_id' => $this->site->id,
                'placement_id' => $this->placement->id,
                'tag' => $secondTag,
            ])
            ->assertRedirect();

        $this->assertSame(
            1,
            DemandWidget::withoutGlobalScopes()->where('demand_placement_id', $demandPlacement->id)->count(),
        );

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.single_size.candidates.0');
        $encoded = (string) data_get($candidate, 'tag.container.attributes.data-hm-isolated-html');
        $published = base64_decode($encoded, true);

        $this->assertIsString($published);
        $this->assertSame($secondTag, $published);
        $this->assertNotSame($firstTag, $published);
    }

    public function test_quick_generic_tag_rejects_ambiguous_multi_size_placement_without_partial_writes(): void
    {
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($this->site, [
            'name' => 'Multi Size',
            'code' => 'multi_size',
            'sizes' => [
                ['width' => 300, 'height' => 250],
                ['width' => 728, 'height' => 90],
            ],
        ], $this->admin);
        $multi = $inventory->createPlacement($this->site, [
            'name' => 'Multi Size',
            'code' => 'multi_size',
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [
                ['width' => 300, 'height' => 250],
                ['width' => 728, 'height' => 90],
            ],
        ], $this->admin);
        $tag = '<script async src="https://cdn.taboola.com/libtrc/horus-test/loader.js"></script><div id="taboola-multi-zone"></div>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), [
                'site_id' => $this->site->id,
                'placement_id' => $multi->id,
                'tag' => $tag,
            ])
            ->assertSessionHasErrors('tag');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_quick_generic_tag_rejects_fixed_plus_fluid_active_sizes_without_partial_writes(): void
    {
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($this->site, [
            'name' => 'Mixed Size',
            'code' => 'mixed_size',
            'sizes' => [
                ['width' => 300, 'height' => 250],
                ['size_type' => 'FLUID', 'label' => 'fluid'],
            ],
        ], $this->admin);
        $mixed = $inventory->createPlacement($this->site, [
            'name' => 'Mixed Size',
            'code' => 'mixed_size',
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [
                ['width' => 300, 'height' => 250],
                ['size_type' => 'FLUID'],
            ],
        ], $this->admin);
        $tag = '<script async src="https://cdn.taboola.com/libtrc/horus-test/loader.js"></script><div id="taboola-mixed-zone"></div>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), [
                'site_id' => $this->site->id,
                'placement_id' => $mixed->id,
                'tag' => $tag,
            ])
            ->assertSessionHasErrors('tag');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_advanced_legacy_fluid_custom_tag_keeps_opaque_iframe_renderer_and_historical_image_policy(): void
    {
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($this->site, [
            'name' => 'Fluid Unit',
            'code' => 'fluid_unit',
            'sizes' => [['size_type' => 'FLUID', 'label' => 'fluid']],
        ], $this->admin);
        $fluid = $inventory->createPlacement($this->site, [
            'name' => 'Fluid Placement',
            'code' => 'fluid_placement',
            'type' => 'NATIVE',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['size_type' => 'FLUID']],
        ], $this->admin);

        $network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();
        $service = app(DemandAccountService::class);
        $account = $service->create([
            'organization_id' => $this->admin->organization_id,
            'demand_network_id' => $network->id,
            'name' => 'Advanced Legacy Custom',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 10,
            'fallback_priority' => 20,
            'account_identifier' => 'advanced-public',
            'configuration' => [
                'allowed_script_origins' => ['https://ads.example.com'],
                'isolation_allowed_origins' => ['https://ads.example.com'],
            ],
        ], $this->admin);
        $mapping = $service->assignSite($account, $this->site, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
        ], $this->admin);
        $demandPlacement = $service->assignPlacement($mapping, $fluid, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'placement_code' => 'fluid-zone',
        ], $this->admin);
        $service->upsertWidget($demandPlacement, [
            'name' => 'Legacy fluid widget',
            'widget_code' => 'fluid-zone',
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'direct_tag_template' => '<div id="fluid-zone"><img src="https://images.example-cdn.com/public.jpg"></div><script src="https://ads.example.com/public.js"></script>',
            'configuration' => [],
        ], $this->admin);
        $this->site->update(['native_demand_enabled' => true]);

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.fluid_placement.candidates.0');
        $csp = (string) data_get($candidate, 'tag.isolation.csp');

        $this->assertSame('ISOLATED_IFRAME', data_get($candidate, 'tag.executionMode'));
        $this->assertSame(['allow-scripts'], data_get($candidate, 'tag.isolation.sandbox'));
        $this->assertSame([], data_get($candidate, 'tag.render.allowedSizes'));
        $this->assertStringContainsString('connect-src https://ads.example.com;', $csp);
        $this->assertStringContainsString('img-src https: data:;', $csp);
    }

    private function adminSession(): static
    {
        $this->actingAs($this->admin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }
}
