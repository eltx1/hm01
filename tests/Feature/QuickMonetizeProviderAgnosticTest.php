<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\DemandAccount;
use App\Models\DemandPlacement;
use App\Models\DemandWidget;
use App\Models\Placement;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Inventory\SiteConfigurationBuilder;
use Database\Seeders\AdFormatSeeder;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class QuickMonetizeProviderAgnosticTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $publisherUser;
    private $publisher;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, AdFormatSeeder::class, DemandNetworkSeeder::class]);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Quick Publisher');
        $this->publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, [
            'legal_name' => 'Quick Publisher',
            'display_name' => 'Quick Publisher',
        ]);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Quick Site',
            'primary_domain' => 'quick.example.org',
            'default_revenue_share_percent' => 80,
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
    }

    public function test_quick_workspace_can_create_provider_agnostic_surface_without_existing_inventory(): void
    {
        $this->adminSession()
            ->get(route('admin.demand.quick.create'))
            ->assertOk()
            ->assertSee('Ad format / surface')
            ->assertSee('Responsive Display')
            ->assertSee('Use an existing placement instead')
            ->assertSee('script-only tag')
            ->assertDontSee('Add and activate a publisher website with at least one active placement first.');
    }

    public function test_script_only_provider_tag_auto_creates_responsive_placement_and_multi_size_recipe(): void
    {
        $tag = '<script async src="//cdn.taboola.com/libtrc/horus-test/loader.js"></script>';

        $response = $this->adminSession()->post(route('admin.demand.quick.store'), [
            'site_id' => $this->site->id,
            'placement_mode' => 'new',
            'placement_preset' => 'responsive_display',
            'tag' => $tag,
        ]);

        $response->assertRedirect(route('admin.demand.quick.create', ['site' => $this->site->id]));
        $response->assertSessionHas('quick_placement_id');

        $placement = Placement::withoutGlobalScopes()
            ->where('site_id', $this->site->id)
            ->with(['sizes', 'adFormat'])
            ->firstOrFail();

        $this->assertTrue((bool) data_get($placement->metadata, 'quick_monetize_generated'));
        $this->assertSame('display_banner', $placement->adFormat?->code);
        $this->assertTrue((bool) data_get($placement->format_settings, 'autoMount'));
        $this->assertSame('article_end', data_get($placement->format_settings, 'autoMountTarget'));
        $this->assertGreaterThan(1, $placement->sizes->where('is_active', true)->count());

        $account = DemandAccount::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['https://cdn.taboola.com'], data_get($account->configuration, 'allowed_script_origins'));
        $demandPlacement = DemandPlacement::withoutGlobalScopes()->where('placement_id', $placement->id)->firstOrFail();
        $this->assertTrue((bool) data_get($demandPlacement->configuration, 'quick_monetize_managed'));

        $direct = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($direct, 'placements.'.$placement->code.'.candidates.0');
        $sizes = data_get($candidate, 'tag.render.allowedSizes', []);
        $this->assertContains([300, 250], $sizes);
        $this->assertContains([970, 250], $sizes);
        $this->assertNotEmpty(json_decode((string) data_get($candidate, 'tag.container.attributes.data-hm-isolated-size-map'), true));
        $this->assertSame($sizes, json_decode((string) data_get($candidate, 'tag.container.attributes.data-hm-isolated-sizes'), true));

        $public = app(SiteConfigurationBuilder::class)->build($this->site->fresh(), ConfigEnvironment::Production, 1);
        $publicPlacement = collect($public['placements'])->firstWhere('code', $placement->code);
        $this->assertSame('DIRECT_JS', $publicPlacement['renderer']);
        $this->assertTrue($publicPlacement['enabled']);
    }

    public function test_iframe_only_provider_tag_is_supported_without_fake_script_requirement(): void
    {
        $tag = '<iframe src="https://securepubads.g.doubleclick.net/gampad/ads?output=html" width="300" height="250"></iframe>';

        $this->adminSession()->post(route('admin.demand.quick.store'), [
            'site_id' => $this->site->id,
            'placement_mode' => 'new',
            'placement_preset' => 'mobile_display',
            'tag' => $tag,
        ])->assertRedirect();

        $account = DemandAccount::withoutGlobalScopes()->firstOrFail();
        $this->assertSame([], data_get($account->configuration, 'allowed_script_origins'));
        $widget = DemandWidget::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(
            ['https://securepubads.g.doubleclick.net'],
            data_get($widget->configuration, 'isolation_frame_origins'),
        );

        $placement = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->firstOrFail();
        $direct = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($direct, 'placements.'.$placement->code.'.candidates.0');
        $csp = base64_decode((string) data_get($candidate, 'tag.container.attributes.data-hm-isolated-csp'), true);
        $this->assertIsString($csp);
        $this->assertStringContainsString('frame-src https://securepubads.g.doubleclick.net;', $csp);
        $this->assertSame('STRUCTURED', data_get($candidate, 'tag.executionMode'));
    }

    public function test_failed_connector_review_rolls_back_auto_created_placement_and_all_wiring(): void
    {
        $tag = <<<'HTML'
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>
<script>const execute = Function('return 1'); execute();</script>
HTML;

        $beforePlacements = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->count();

        $this->adminSession()->post(route('admin.demand.quick.store'), [
            'site_id' => $this->site->id,
            'placement_mode' => 'new',
            'placement_preset' => 'responsive_display',
            'tag' => $tag,
        ])->assertSessionHasErrors('tag');

        $this->assertSame($beforePlacements, Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->count());
        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandPlacement::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_provider_managed_surface_is_not_exposed_as_one_click_quick_preset(): void
    {
        $this->adminSession()->post(route('admin.demand.quick.store'), [
            'site_id' => $this->site->id,
            'placement_mode' => 'new',
            'placement_preset' => 'page_skin',
            'tag' => '<script async src="https://cdn.taboola.com/libtrc/horus-test/loader.js"></script>',
        ])->assertSessionHasErrors('placement_preset');

        $this->assertSame(0, Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->count());
    }

    private function adminSession(): static
    {
        $this->actingAs($this->admin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }
}
