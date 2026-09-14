<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\DemandAccount;
use App\Models\DemandWidget;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Inventory\InventoryManager;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class DirectDemandQuickResourceOriginTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $site;
    private $placement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, DemandNetworkSeeder::class]);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Resource Publisher');
        $publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['display_name' => 'Resource Publisher']);
        $this->site = $this->makeSiteFor($publisher, $publisherUser, [
            'display_name' => 'Resource Site',
            'primary_domain' => 'resource-origin.example',
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
            'name' => 'Resource Placement',
            'code' => 'resource_placement',
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
        $this->placement = $inventory->createPlacement($this->site, [
            'name' => 'Resource Placement',
            'code' => 'resource_placement',
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
    }

    public function test_quick_generic_tag_includes_non_script_resource_origin_in_isolated_csp_without_widening_script_account_allowlist(): void
    {
        $tag = '<script async src="https://cdn.taboola.com/libtrc/horus-resource/loader.js"></script>'
            .'<iframe id="provider-frame" src="https://securepubads.g.doubleclick.net/provider-frame"></iframe>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), [
                'site_id' => $this->site->id,
                'placement_id' => $this->placement->id,
                'tag' => $tag,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $account = DemandAccount::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(
            ['https://cdn.taboola.com'],
            data_get($account->configuration, 'allowed_script_origins'),
        );

        $widget = DemandWidget::withoutGlobalScopes()->firstOrFail();
        $this->assertEqualsCanonicalizing(
            ['https://cdn.taboola.com', 'https://securepubads.g.doubleclick.net'],
            data_get($widget->configuration, 'isolation_allowed_origins'),
        );

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.resource_placement.candidates.0');
        $attributes = (array) data_get($candidate, 'tag.container.attributes', []);
        $csp = $this->decodedPayload($attributes, 'data-hm-isolated-csp');

        $this->assertStringContainsString('frame-src https://cdn.taboola.com https://securepubads.g.doubleclick.net;', $csp);
        $this->assertStringContainsString("script-src 'unsafe-inline' https://cdn.taboola.com;", $csp);
        $this->assertStringNotContainsString(
            "script-src 'unsafe-inline' https://cdn.taboola.com https://securepubads.g.doubleclick.net;",
            $csp,
        );
    }

    public function test_quick_generic_tag_rejects_private_non_script_resource_origin_before_any_write(): void
    {
        $tag = '<script async src="https://cdn.taboola.com/libtrc/horus-resource/loader.js"></script>'
            .'<iframe id="provider-frame" src="https://127.0.0.1/private-ad"></iframe>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), [
                'site_id' => $this->site->id,
                'placement_id' => $this->placement->id,
                'tag' => $tag,
            ])
            ->assertSessionHasErrors('tag');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    /** @param array<string, mixed> $attributes */
    private function decodedPayload(array $attributes, string $baseAttribute): string
    {
        $encoded = (string) ($attributes[$baseAttribute] ?? '');
        if ($encoded === '') {
            $parts = (int) ($attributes[$baseAttribute.'-parts'] ?? 0);
            $this->assertGreaterThan(0, $parts, 'Expected a direct or chunked encoded payload.');

            for ($index = 0; $index < $parts; $index++) {
                $key = $baseAttribute.'-'.$index;
                $this->assertArrayHasKey($key, $attributes);
                $encoded .= (string) $attributes[$key];
            }
        }

        $decoded = base64_decode($encoded, true);
        $this->assertIsString($decoded);

        return $decoded;
    }

    private function adminSession(): static
    {
        $this->actingAs($this->admin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }
}
