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
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Demand\QuickMonetizeService;
use Database\Seeders\AdFormatSeeder;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class QuickMonetizeDisabledSurfaceRecoveryTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_disabled_canonical_in_article_surface_is_reactivated_reconciled_and_reused(): void
    {
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, AdFormatSeeder::class, DemandNetworkSeeder::class]);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'LordAI Recovery Publisher');
        $publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, [
            'legal_name' => 'LordAI Recovery Publisher',
            'display_name' => 'LordAI Recovery Publisher',
        ]);
        $site = $this->makeSiteFor($publisher, $publisherUser, [
            'display_name' => 'LordAI Recovery',
            'primary_domain' => 'lordai-recovery.example.org',
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

        $first = $service->activate(
            $site->fresh(),
            $network,
            $admin,
            $this->tag('[[300, 250]]'),
            null,
            'in_article_display',
            'Quick · In-Article Display',
        );

        $placement = Placement::withoutGlobalScopes()
            ->with('sizes')
            ->findOrFail($first['placement']->id);
        $mapping = DemandPlacement::withoutGlobalScopes()
            ->where('placement_id', $placement->id)
            ->firstOrFail();

        // Reproduce the production repair state: the generated inventory remains
        // for audit/rollback, but the surface, mapping and widget are disabled.
        // Also strip FLUID to prove reactivation reapplies the current preset
        // rather than merely flipping the old status bit.
        $placement->sizes()->where('size_type', 'FLUID')->delete();
        $placement->update(['status' => PlacementStatus::Disabled, 'updated_by' => $admin->id]);
        $mapping->update(['is_enabled' => false, 'updated_by' => $admin->id]);
        DemandWidget::withoutGlobalScopes()
            ->where('demand_placement_id', $mapping->id)
            ->update(['is_enabled' => false]);

        $second = $service->activate(
            $site->fresh(),
            $network,
            $admin,
            $this->tag('[[300, 250], "fluid"]'),
            null,
            'in_article_display',
            'Quick · In-Article Display',
        );

        $this->assertSame($placement->id, $second['placement']->id);
        $this->assertSame(1, Placement::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->get()
            ->filter(fn (Placement $candidate): bool => data_get($candidate->metadata, 'placement_preset') === 'in_article_display')
            ->count());

        $restored = Placement::withoutGlobalScopes()->with('sizes')->findOrFail($placement->id);
        $this->assertSame(PlacementStatus::Active, $restored->status);
        $this->assertTrue($restored->sizes->contains(fn ($size): bool => $size->size_type === 'FLUID'));
        $this->assertTrue((bool) data_get($restored->format_settings, 'autoMount'));
        $this->assertSame('article_mid', data_get($restored->format_settings, 'autoMountTarget'));

        $restoredMapping = DemandPlacement::withoutGlobalScopes()->where('placement_id', $placement->id)->firstOrFail();
        $this->assertTrue($restoredMapping->is_enabled);
        $widget = DemandWidget::withoutGlobalScopes()
            ->where('demand_placement_id', $restoredMapping->id)
            ->where('is_enabled', true)
            ->firstOrFail();
        $this->assertStringContainsString('"fluid"', (string) $widget->direct_tag_template);

        $direct = app(DemandConfigurationBuilder::class)->build($site->fresh());
        $candidate = data_get($direct, 'placements.'.$restored->code.'.candidates.0');
        $this->assertSame('STRUCTURED', data_get($candidate, 'tag.executionMode'));
        $this->assertContains('fluid', (array) data_get($candidate, 'tag.render.allowedSizes', []));
        $this->assertContains('NATIVE', (array) data_get($candidate, 'tag.render.allowedFormats', []));
        $this->assertStringContainsString(
            '"fluid"',
            (string) data_get($candidate, 'tag.container.attributes.data-hm-gpt-sizes'),
        );
    }

    private function tag(string $sizes): string
    {
        return <<<HTML
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>
<div id="gpt-in-article"></div>
<script>
window.googletag = window.googletag || {cmd: []};
googletag.cmd.push(function() {
  googletag.defineSlot('/1234567/lordai_in_article', {$sizes}, 'gpt-in-article').addService(googletag.pubads());
  googletag.enableServices();
  googletag.display('gpt-in-article');
});
</script>
HTML;
    }
}
