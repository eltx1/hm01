<?php

namespace Tests\Feature;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\ConfigVersion;
use App\Models\DemandAccount;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Models\DemandWidget;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Inventory\InventoryManager;
use App\Services\Operations\PlatformControlService;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class DirectDemandQuickMonetizeTest extends TestCase
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
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Lordai Publisher');
        $this->publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, [
            'legal_name' => 'Lordai',
            'display_name' => 'Lordai',
        ]);

        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Lordai',
            'primary_domain' => 'lordai.net',
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

        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($this->site, [
            'name' => 'Header Banner',
            'code' => 'header_banner',
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
        $this->placement = $inventory->createPlacement($this->site, [
            'name' => 'Header Banner',
            'code' => 'header_banner',
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
    }

    public function test_quick_workspace_reduces_normal_flow_to_website_placement_and_tag(): void
    {
        $this->adminSession()
            ->get(route('admin.demand.quick.create'))
            ->assertOk()
            ->assertSee('Paste the tag. Horus handles the wiring.')
            ->assertSee('Website')
            ->assertSee('Placement')
            ->assertSee('Provider-issued ad tag')
            ->assertSee('Activate Ad')
            ->assertDontSee('Revenue share %')
            ->assertDontSee('Provider account identifier')
            ->assertDontSee('Approved script origins');
    }

    public function test_valid_google_gpt_tag_is_fully_wired_and_published_in_one_post(): void
    {
        $beforeVersions = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();

        $response = $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload());

        $account = DemandAccount::withoutGlobalScopes()
            ->where('publisher_id', $this->publisher->id)
            ->latest()
            ->firstOrFail();
        $demandSite = DemandSite::withoutGlobalScopes()->where('demand_account_id', $account->id)->firstOrFail();
        $demandPlacement = DemandPlacement::withoutGlobalScopes()->where('demand_site_id', $demandSite->id)->firstOrFail();
        $widget = DemandWidget::withoutGlobalScopes()->where('demand_placement_id', $demandPlacement->id)->firstOrFail();

        $response->assertRedirect(route('admin.demand.quick.create', ['site' => $this->site->id]));
        $response->assertSessionHas('status');

        $this->assertSame(DemandAccountScope::Publisher, $account->scope);
        $this->assertSame(DemandIntegrationMode::ManualTag, $account->integration_mode);
        $this->assertSame(DemandApprovalStatus::Approved, $account->approval_status);
        $this->assertTrue($account->is_enabled);
        $this->assertSame('80.000', $account->revenue_share_percent);
        $this->assertTrue((bool) data_get($account->configuration, 'quick_monetize_managed'));
        $this->assertSame(
            ['https://securepubads.g.doubleclick.net'],
            data_get($account->configuration, 'allowed_script_origins'),
        );

        $this->assertSame(DemandApprovalStatus::Approved, $demandSite->approval_status);
        $this->assertSame(DemandIntegrationMode::ManualTag, $demandSite->integration_mode);
        $this->assertTrue($demandSite->is_enabled);
        $this->assertSame(DemandApprovalStatus::Approved, $demandPlacement->approval_status);
        $this->assertSame(DemandIntegrationMode::ManualTag, $demandPlacement->integration_mode);
        $this->assertTrue($demandPlacement->is_enabled);
        $this->assertSame($this->gptTag(), $widget->direct_tag_template);
        $this->assertSame(DemandApprovalStatus::Approved, $widget->approval_status);
        $this->assertTrue($widget->is_enabled);
        $this->assertSame(
            ['https://securepubads.g.doubleclick.net'],
            data_get($widget->configuration, 'isolation_allowed_origins'),
        );
        $this->assertTrue($this->site->fresh()->native_demand_enabled);

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.header_banner.candidates.0');
        $this->assertSame('MANUAL_TAG', data_get($candidate, 'mode'));
        $this->assertSame('ISOLATED_IFRAME', data_get($candidate, 'tag.executionMode'));

        $afterVersions = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();
        $this->assertGreaterThan($beforeVersions, $afterVersions);
    }

    public function test_repeating_quick_activation_reuses_account_and_mappings_instead_of_duplicating_them(): void
    {
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload())->assertRedirect();
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload())->assertRedirect();

        $this->assertSame(1, DemandAccount::withoutGlobalScopes()->where('publisher_id', $this->publisher->id)->count());
        $this->assertSame(1, DemandSite::withoutGlobalScopes()->where('site_id', $this->site->id)->count());
        $this->assertSame(1, DemandPlacement::withoutGlobalScopes()->where('placement_id', $this->placement->id)->count());
        $this->assertSame(1, DemandWidget::withoutGlobalScopes()->count());
    }

    public function test_cross_site_placement_is_rejected_without_partial_demand_writes(): void
    {
        $other = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Other',
            'primary_domain' => 'other.example',
        ]);
        $other->update(['status' => SiteStatus::Active, 'serving_mode' => ServingMode::HorusDirect]);
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($other, [
            'name' => 'Other Unit', 'code' => 'other_unit', 'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
        $otherPlacement = $inventory->createPlacement($other, [
            'name' => 'Other Placement', 'code' => 'other_placement', 'type' => 'DISPLAY', 'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload(['placement_id' => $otherPlacement->id]))
            ->assertSessionHasErrors('placement_id');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_unsafe_or_secret_like_tag_is_rejected_before_any_configuration_is_created(): void
    {
        $unsafe = '<div id="ad"></div><script src="http://evil.example/ad.js"></script><script>const api_key="secret";</script>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload(['tag' => $unsafe]))
            ->assertSessionHasErrors('tag');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandSite::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_platform_kill_switch_blocks_quick_activation_and_is_not_overridden(): void
    {
        app(PlatformControlService::class)->set(
            'PLATFORM',
            null,
            'DIRECT_JS',
            true,
            'Quick flow safety test',
            $this->admin,
        );

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload())
            ->assertSessionHasErrors('quick');

        $this->assertDatabaseHas('platform_controls', [
            'scope_type' => 'PLATFORM',
            'scope_id' => 'GLOBAL',
            'control_key' => 'DIRECT_JS',
            'is_disabled' => true,
        ]);
        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'site_id' => $this->site->id,
            'placement_id' => $this->placement->id,
            'tag' => $this->gptTag(),
        ], $overrides);
    }

    private function gptTag(): string
    {
        return <<<'HTML'
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>
<div id="div-gpt-ad-lordai-header"></div>
<script>
window.googletag = window.googletag || {cmd: []};
googletag.cmd.push(function() {
  googletag.defineSlot('/1234567/lordai_header', [300, 250], 'div-gpt-ad-lordai-header').addService(googletag.pubads());
  googletag.enableServices();
  googletag.display('div-gpt-ad-lordai-header');
});
</script>
HTML;
    }

    private function adminSession(): static
    {
        $this->actingAs($this->admin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }
}
