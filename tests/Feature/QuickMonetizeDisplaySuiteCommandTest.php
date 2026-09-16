<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\ConfigVersion;
use App\Models\DemandNetwork;
use App\Models\DemandWidget;
use App\Models\Placement;
use App\Services\Demand\QuickMonetizeService;
use App\Services\Inventory\SiteConfigurationBuilder;
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

    public function test_command_creates_every_size_compatible_display_edge_surface_and_is_idempotent(): void
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
        ];

        $placements = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->whereNull('deleted_at')->get();
        $this->assertEqualsCanonicalizing($expected, $placements->pluck('code')->all());
        $this->assertSame(6, $placements->count());
        $this->assertSame(6, DemandWidget::withoutGlobalScopes()->where('is_enabled', true)->count());
        $this->assertDatabaseMissing('placements', ['site_id' => $this->site->id, 'code' => 'quick_side_rail_right']);
        $this->assertDatabaseMissing('placements', ['site_id' => $this->site->id, 'code' => 'quick_side_rail_left']);
        $this->assertStringContainsString('2 incompatible with the source GPT sizes', Artisan::output());

        foreach (array_slice($expected, 1) as $code) {
            $placement = $placements->firstWhere('code', $code);
            $this->assertNotNull($placement);
            $this->assertTrue((bool) data_get($placement->metadata, 'quick_monetize_generated'));
            $this->assertTrue((bool) data_get($placement->format_settings, 'autoMount'));
        }

        $public = app(SiteConfigurationBuilder::class)->build($this->site->fresh(), ConfigEnvironment::Production, 0);
        foreach (array_slice($expected, 1) as $code) {
            $placement = collect((array) ($public['placements'] ?? []))->firstWhere('code', $code);
            $this->assertIsArray($placement);
            $this->assertTrue((bool) ($placement['enabled'] ?? false), $code.' must be public and enabled.');
            $this->assertSame('DIRECT_JS', $placement['renderer'] ?? null, $code.' must be owned by Direct JS.');
            $this->assertTrue((bool) data_get($placement, 'format.settings.autoMount'), $code.' must auto-mount from the permanent loader.');
            $this->assertNotEmpty(data_get($public, 'directDemand.placements.'.$code.'.candidates', []), $code.' must expose a public Direct Demand candidate.');
        }

        $this->assertGreaterThan($beforeVersions, ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count());

        $versionCount = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();
        $exit = Artisan::call('quick-monetize:clone-display-suite', [
            'siteKey' => $this->site->public_key,
        ]);
        $this->assertSame(0, $exit, Artisan::output());
        $this->assertSame(6, Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->whereNull('deleted_at')->count());
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

    public function test_command_never_invents_sizes_missing_from_the_source_gpt_tag(): void
    {
        $exit = Artisan::call('quick-monetize:clone-display-suite', [
            'siteKey' => $this->site->public_key,
            '--preset' => ['responsive_display'],
        ]);
        $this->assertSame(0, $exit, Artisan::output());

        $placement = Placement::withoutGlobalScopes()
            ->where('site_id', $this->site->id)
            ->where('code', 'quick_responsive_display')
            ->firstOrFail();
        $widget = DemandWidget::withoutGlobalScopes()
            ->whereHas('demandPlacement', fn ($query) => $query->where('placement_id', $placement->id))
            ->where('is_enabled', true)
            ->firstOrFail();
        $tag = preg_replace('/\s+/', '', (string) $widget->direct_tag_template) ?: '';

        $this->assertStringContainsString('[[728,90],[320,100],[320,50]]', $tag);
        $this->assertStringNotContainsString('[300,250]', $tag);
        $this->assertStringNotContainsString('[336,280]', $tag);
        $this->assertStringNotContainsString('[970,250]', $tag);
        $this->assertStringContainsString('/1234567/lordai_display', $tag);
    }

    public function test_incompatible_side_rail_is_skipped_instead_of_fabricating_vertical_gpt_sizes(): void
    {
        $exit = Artisan::call('quick-monetize:clone-display-suite', [
            'siteKey' => $this->site->public_key,
            '--preset' => ['side_rail_right'],
        ]);

        $this->assertSame(0, $exit, Artisan::output());
        $this->assertStringContainsString('no size supported by this preset', Artisan::output());
        $this->assertDatabaseMissing('placements', ['site_id' => $this->site->id, 'code' => 'quick_side_rail_right']);
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
