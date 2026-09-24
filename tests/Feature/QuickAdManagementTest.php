<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\PlacementStatus;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\ConfigVersion;
use App\Models\DemandNetwork;
use App\Models\DemandPlacement;
use App\Models\DemandWidget;
use App\Models\Placement;
use App\Models\Site;
use App\Services\Demand\QuickMonetizeService;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use Database\Seeders\AdFormatSeeder;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class QuickAdManagementTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $publisherUser;
    private Site $site;
    private Site $otherSite;
    private Placement $top;
    private Placement $bottom;
    private Placement $otherTop;
    private Placement $manual;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, AdFormatSeeder::class, DemandNetworkSeeder::class]);
        $this->admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'), RoleName::SuperAdmin);
        $this->publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher'), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($this->publisherUser);
        foreach (['site', 'otherSite'] as $property) {
            $site = $this->makeSiteFor($publisher, $this->publisherUser, ['display_name' => $property, 'primary_domain' => strtolower($property).'.example.org']);
            $site->update(['status' => SiteStatus::Active, 'serving_mode' => ServingMode::HorusDirect]);
            $site->servingSettings()->update(['serving_mode' => ServingMode::HorusDirect]);
            $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);
            $this->{$property} = $site;
        }
        $network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();
        $quick = app(QuickMonetizeService::class);
        $this->top = $quick->activate($this->site->fresh(), $network, $this->admin, '/12345/top', null, 'sticky_top')['placement'];
        $this->bottom = $quick->activate($this->site->fresh(), $network, $this->admin, '/12345/bottom', null, 'sticky_bottom')['placement'];
        $this->otherTop = $quick->activate($this->otherSite->fresh(), $network, $this->admin, '/12345/other', null, 'sticky_top')['placement'];
        $this->manual = app(InventoryManager::class)->createPlacement($this->site, [
            'name' => 'Independent manual placement', 'code' => 'independent', 'type' => 'DISPLAY',
            'status' => 'ACTIVE', 'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
    }

    public function test_manage_page_is_read_only_and_shows_only_selected_website_quick_ads(): void
    {
        $versions = ConfigVersion::count();
        $this->get(route('admin.demand.quick.manage', ['site' => $this->site->id]))->assertOk()
            ->assertSee('Top of page')->assertSee('Bottom of page')->assertSee('Pause ad')
            ->assertSee('data-quick-ad="'.$this->top->id.'"', false)
            ->assertDontSee('data-quick-ad="'.$this->otherTop->id.'"', false)
            ->assertDontSee($this->manual->name)->assertSee('Latest website update');
        $this->assertSame($versions, ConfigVersion::count());
        $this->get(route('admin.demand.quick.create', ['site' => $this->site->id]))->assertOk()->assertSee('Manage website ads');
        $this->get(route('admin.sites.index'))->assertOk()->assertSee('Manage ads');
    }

    public function test_pausing_top_preserves_bottom_other_sites_tags_and_settings_and_queues_once(): void
    {
        $before = $this->payload($this->site);
        $otherVersions = $this->otherSite->configVersions()->count();
        $tags = DemandWidget::query()->orderBy('id')->get()->toArray();
        $settings = $this->top->only(['ad_unit_id', 'format_settings', 'metadata', 'code']);
        $this->action($this->top, 'pause')->assertRedirect(route('admin.demand.quick.manage', ['site' => $this->site->id]));
        $after = $this->payload($this->site);
        $this->assertFalse((bool) data_get(collect($after['placements'])->firstWhere('code', $this->top->code), 'enabled', false));
        $this->assertSame(collect($before['placements'])->firstWhere('code', $this->bottom->code), collect($after['placements'])->firstWhere('code', $this->bottom->code));
        $this->assertSame($before['directDemand']['placements'][$this->bottom->code], $after['directDemand']['placements'][$this->bottom->code]);
        $this->assertSame(PlacementStatus::Active, $this->bottom->fresh()->status);
        $this->assertSame(PlacementStatus::Active, $this->otherTop->fresh()->status);
        $this->assertSame(PlacementStatus::Active, $this->manual->fresh()->status);
        $this->assertSame($otherVersions, $this->otherSite->configVersions()->count());
        $this->assertSame($tags, DemandWidget::query()->orderBy('id')->get()->toArray());
        $this->assertSame($settings, $this->top->fresh()->only(array_keys($settings)));
        $latest = $this->site->configVersions()->with('deliveryItem')->latest('version')->first();
        $this->assertSame('URGENT', $latest->deliveryItem->priority->value);
        $versions = ConfigVersion::count();
        $this->action($this->top, 'pause')->assertSessionHasNoErrors();
        $this->assertSame($versions, ConfigVersion::count());
        $this->action($this->top, 'resume')->assertSessionHasNoErrors();
        $resumed = collect($this->payload($this->site)['placements'])->firstWhere('code', $this->top->code);
        $this->assertTrue($resumed['enabled']);
        $this->assertSame('DIRECT_JS', $resumed['renderer']);
    }

    public function test_remove_and_restore_preserve_identity_and_resume_requires_a_ready_provider(): void
    {
        $ids = DemandPlacement::query()->where('placement_id', $this->top->id)->pluck('id')->all();
        $this->action($this->top, 'remove')->assertSessionHasNoErrors();
        $this->assertSame(PlacementStatus::Disabled, $this->top->fresh()->status);
        $this->assertFalse($this->top->fresh()->trashed());
        $this->action($this->top, 'resume')->assertSessionHasErrors('action');
        $this->get(route('admin.demand.quick.manage', ['site' => $this->site->id]))->assertSee('Removed ads (1)')->assertSee('Restore paused');
        $this->action($this->top, 'restore')->assertSessionHasNoErrors();
        $this->assertSame(PlacementStatus::Paused, $this->top->fresh()->status);
        DemandPlacement::query()->whereIn('id', $ids)->update(['is_enabled' => false]);
        $versions = ConfigVersion::count();
        $this->action($this->top, 'resume')->assertSessionHasErrors('action');
        $this->assertSame(PlacementStatus::Paused, $this->top->fresh()->status);
        $this->assertSame($versions, ConfigVersion::count());
        $this->assertSame($ids, DemandPlacement::query()->where('placement_id', $this->top->id)->pluck('id')->all());
    }

    public function test_cross_site_and_non_quick_actions_are_rejected_without_publication(): void
    {
        $versions = ConfigVersion::count();
        $this->action($this->otherTop, 'pause')->assertNotFound();
        $this->action($this->manual, 'remove')->assertNotFound();
        $this->action($this->top, 'delete-everything')->assertSessionHasErrors('action');
        $this->assertSame($versions, ConfigVersion::count());
        $this->assertSame(PlacementStatus::Active, $this->otherTop->fresh()->status);
    }

    public function test_publisher_and_staff_without_permission_cannot_manage_ads(): void
    {
        $url = route('admin.demand.quick.manage', ['site' => $this->site->id]);
        $this->actingAs($this->publisherUser)->get($url)->assertForbidden();
        $this->action($this->top, 'pause')->assertForbidden();
        $staff = $this->makeUser($this->admin->organization, RoleName::PublisherAdmin);
        $this->actingAs($staff)->get($url)->assertForbidden();
        $this->action($this->top, 'remove')->assertForbidden();
        $this->assertSame(PlacementStatus::Active, $this->top->fresh()->status);
    }

    private function action(Placement $placement, string $action)
    {
        return $this->patch(route('admin.demand.quick.placements.update', [$this->site, $placement]), ['action' => $action]);
    }

    private function payload(Site $site): array
    {
        return app(SiteConfigurationBuilder::class)->build($site->fresh(), ConfigEnvironment::Production, 0);
    }
}
