<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\ConfigVersion;
use App\Models\DemandNetwork;
use App\Models\DemandWidget;
use App\Models\Placement;
use App\Services\Demand\QuickMonetizeService;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class QuickMonetizeDisplaySuiteCommandTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, DemandNetworkSeeder::class]);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Lordai Publisher');
        $publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, [
            'legal_name' => 'Lordai',
            'display_name' => 'Lordai',
        ]);
        $this->site = $this->makeSiteFor($publisher, $publisherUser, [
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

        $network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();
        app(QuickMonetizeService::class)->activate(
            $this->site->fresh(),
            $network,
            $this->admin,
            $this->sourceTag(),
            null,
            'sticky_bottom',
            'Quick · Bottom Edge Anchor',
        );
    }

    public function test_command_creates_the_full_display_edge_suite_and_is_idempotent(): void
    {
        $beforeVersions = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();

        $exit = Artisan::call('quick-monetize:clone-display-suite', [
            'siteKey' => $this->site->public_key,
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $expected = [
            'quick_sticky_bottom',
            'quick_responsive_display',
            'quick_in_article_display',
            'quick_high_impact_display',
            'quick_mobile_display',
            'quick_sticky_top',
            'quick_side_rail_right',
            'quick_side_rail_left',
        ];

        $placements = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->whereNull('deleted_at')->get();
        $this->assertEqualsCanonicalizing($expected, $placements->pluck('code')->all());
        $this->assertSame(8, $placements->count());
        $this->assertSame(8, DemandWidget::withoutGlobalScopes()->where('is_enabled', true)->count());
        foreach (array_slice($expected, 1) as $code) {
            $placement = $placements->firstWhere('code', $code);
            $this->assertNotNull($placement);
            $this->assertTrue((bool) data_get($placement->metadata, 'quick_monetize_generated'));
            $this->assertTrue((bool) data_get($placement->format_settings, 'autoMount'));
        }
        $this->assertGreaterThan($beforeVersions, ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count());

        $versionCount = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();
        $exit = Artisan::call('quick-monetize:clone-display-suite', [
            'siteKey' => $this->site->public_key,
        ]);
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(8, Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->whereNull('deleted_at')->count());
        $this->assertSame($versionCount, ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count());
    }

    public function test_command_rejects_video_presets_instead_of_reusing_a_display_gpt_tag(): void
    {
        $exit = Artisan::call('quick-monetize:clone-display-suite', [
            'siteKey' => $this->site->public_key,
            '--preset' => ['video_floating'],
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unsupported display-suite preset', Artisan::output());
        $this->assertDatabaseMissing('placements', ['site_id' => $this->site->id, 'code' => 'quick_video_floating']);
    }

    public function test_command_generates_preset_specific_gpt_sizes_from_the_catalog(): void
    {
        $exit = Artisan::call('quick-monetize:clone-display-suite', [
            'siteKey' => $this->site->public_key,
            '--preset' => ['side_rail_right'],
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        $rail = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->where('code', 'quick_side_rail_right')->firstOrFail();
        $widget = DemandWidget::withoutGlobalScopes()
            ->whereHas('demandPlacement', fn ($query) => $query->where('placement_id', $rail->id))
            ->where('is_enabled', true)
            ->firstOrFail();

        $this->assertStringContainsString('[[160,600],[300,600]]', preg_replace('/\s+/', '', (string) $widget->direct_tag_template) ?: '');
        $this->assertStringContainsString('/1234567/lordai_display', (string) $widget->direct_tag_template);
    }

    private function sourceTag(): string
    {
        return <<<'HTML'
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>
<div id="gpt-passback"></div>
<script>
window.googletag = window.googletag || {cmd: []};
googletag.cmd.push(function () {
    googletag.defineSlot('/1234567/lordai_display', [[320, 50], [320, 100], [728, 90], [970, 90]], 'gpt-passback').addService(googletag.pubads());
    googletag.enableServices();
    googletag.display('gpt-passback');
});
</script>
HTML;
    }
}
