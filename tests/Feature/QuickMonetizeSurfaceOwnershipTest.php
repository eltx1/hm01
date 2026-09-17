<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\PlacementStatus;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\DemandNetwork;
use App\Models\DemandPlacement;
use App\Models\DemandWidget;
use App\Models\Placement;
use App\Services\Demand\QuickMonetizeService;
use Database\Seeders\AdFormatSeeder;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class QuickMonetizeSurfaceOwnershipTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_repeated_quick_activation_reuses_one_surface_instead_of_creating_overlapping_anchors(): void
    {
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, AdFormatSeeder::class, DemandNetworkSeeder::class]);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Surface Publisher');
        $publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, [
            'legal_name' => 'Surface Publisher',
            'display_name' => 'Surface Publisher',
        ]);
        $site = $this->makeSiteFor($publisher, $publisherUser, [
            'display_name' => 'Surface Site',
            'primary_domain' => 'surface.example.org',
            'default_revenue_share_percent' => 80,
            'native_demand_enabled' => false,
        ]);
        $site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => false,
        ]);
        $site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => false,
        ]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        $network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();
        $service = app(QuickMonetizeService::class);

        $first = $service->activate($site->fresh(), $network, $admin, $this->tag('/1234567/top_a'), null, 'sticky_top', 'Top A');
        $second = $service->activate($site->fresh(), $network, $admin, $this->tag('/1234567/top_b'), null, 'sticky_top', 'Top B');

        $this->assertSame($first['placement']->id, $second['placement']->id);
        $this->assertSame(1, Placement::withoutGlobalScopes()->where('site_id', $site->id)->whereNull('deleted_at')->count());
        $this->assertSame(1, DemandPlacement::withoutGlobalScopes()->where('placement_id', $first['placement']->id)->count());
        $this->assertSame(1, DemandWidget::withoutGlobalScopes()->where('is_enabled', true)->count());

        $placement = Placement::withoutGlobalScopes()->whereKey($first['placement']->id)->firstOrFail();
        $this->assertSame('sticky_top', data_get($placement->metadata, 'placement_preset'));
        $this->assertTrue((bool) data_get($placement->format_settings, 'autoMount'));

        $widget = DemandWidget::withoutGlobalScopes()->where('is_enabled', true)->firstOrFail();
        $this->assertStringContainsString('/1234567/top_b', (string) $widget->direct_tag_template);
        $this->assertStringNotContainsString('/1234567/top_a', (string) $widget->direct_tag_template);
    }

    public function test_active_quick_surface_is_reused_when_a_disabled_historical_duplicate_remains_after_repair(): void
    {
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, AdFormatSeeder::class, DemandNetworkSeeder::class]);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Repair Publisher');
        $publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, [
            'legal_name' => 'Repair Publisher',
            'display_name' => 'Repair Publisher',
        ]);
        $site = $this->makeSiteFor($publisher, $publisherUser, [
            'display_name' => 'Repair Site',
            'primary_domain' => 'repair.example.org',
            'default_revenue_share_percent' => 80,
            'native_demand_enabled' => false,
        ]);
        $site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => false,
        ]);
        $site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => false,
        ]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        $network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();
        $service = app(QuickMonetizeService::class);
        $first = $service->activate($site->fresh(), $network, $admin, $this->tag('/1234567/top_live'), null, 'sticky_top', 'Top Live');

        $live = Placement::withoutGlobalScopes()->whereKey($first['placement']->id)->firstOrFail();
        $historical = $live->replicate();
        $historical->code = 'quick_sticky_top_legacy';
        $historical->name = 'Legacy Top';
        $historical->status = PlacementStatus::Disabled;
        $historical->save();

        $again = $service->activate($site->fresh(), $network, $admin, $this->tag('/1234567/top_updated'), null, 'sticky_top', 'Top Updated');

        $this->assertSame($live->id, $again['placement']->id);
        $this->assertSame(1, Placement::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->where('status', PlacementStatus::Active->value)
            ->get()
            ->filter(fn (Placement $placement): bool => data_get($placement->metadata, 'placement_preset') === 'sticky_top')
            ->count());

        $widget = DemandWidget::withoutGlobalScopes()->where('is_enabled', true)->firstOrFail();
        $this->assertStringContainsString('/1234567/top_updated', (string) $widget->direct_tag_template);
    }

    private function tag(string $path): string
    {
        return '<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>'
            .'<div id="gpt-passback"></div>'
            .'<script>window.googletag=window.googletag||{cmd:[]};googletag.cmd.push(function(){'
            .'googletag.defineSlot('.json_encode($path).',[[300,50],[320,50],[728,90]],"gpt-passback").addService(googletag.pubads());'
            .'googletag.enableServices();googletag.display("gpt-passback");});</script>';
    }
}
