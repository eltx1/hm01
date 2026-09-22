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
use App\Models\DemandNetwork;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Models\DemandWidget;
use App\Models\Placement;
use App\Enums\ConfigEnvironment;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Inventory\PlacementPresetBuilder;
use App\Services\Demand\CustomThirdPartyTagConnector;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Inventory\InventoryManager;
use App\Services\Operations\PlatformControlService;
use App\Services\Security\PublicProviderOriginValidator;
use App\Services\Settings\GlobalSettingsService;
use Database\Seeders\AdFormatSeeder;
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
            ->assertSee('VAST URL or provider-issued ad tag')
            ->assertSee('Activate Ad')
            ->assertDontSee('Revenue share %')
            ->assertDontSee('Provider account identifier')
            ->assertDontSee('Approved script origins');
    }

    public function test_valid_google_gpt_tag_is_normalized_wired_and_published_in_one_post(): void
    {
        $beforeVersions = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();

        $response = $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload(['tag_input_type' => 'PROVIDER_TAG']));

        $account = DemandAccount::withoutGlobalScopes()
            ->where('publisher_id', $this->publisher->id)
            ->latest()
            ->firstOrFail();
        $demandSite = DemandSite::withoutGlobalScopes()->where('demand_account_id', $account->id)->firstOrFail();
        $demandPlacement = DemandPlacement::withoutGlobalScopes()->where('demand_site_id', $demandSite->id)->firstOrFail();
        $widget = DemandWidget::withoutGlobalScopes()->where('demand_placement_id', $demandPlacement->id)->firstOrFail();
        $network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();

        $response->assertRedirect(route('admin.demand.quick.create', ['site' => $this->site->id]));
        $response->assertSessionHas('status');

        $this->assertSame(CustomThirdPartyTagConnector::class, $network->connector_class);
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
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'demand.site.direct_demand_enabled_changed',
        ]);

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.header_banner.candidates.0');
        $tagRecipe = (array) data_get($candidate, 'tag', []);
        $gptRuntimeHash = substr((string) hash_file('sha256', public_path('assets/hm-gpt-direct.js')), 0, 16);
        $this->assertSame('MANUAL_TAG', data_get($candidate, 'mode'));
        $this->assertSame('STRUCTURED', data_get($candidate, 'tag.executionMode'));
        $this->assertSame(
            'https://cdn.horusmedia.net/runtime/gpt/hm-gpt-direct.'.$gptRuntimeHash.'.js',
            data_get($candidate, 'tag.scripts.0.url'),
        );
        $this->assertSame('/1234567/lordai_header', data_get($candidate, 'tag.container.attributes.data-hm-gpt-ad-unit-path'));
        $this->assertSame('[[300,250]]', data_get($candidate, 'tag.container.attributes.data-hm-gpt-sizes'));
        $this->assertSame([[300, 250]], data_get($candidate, 'tag.render.allowedSizes'));
        $this->assertStringNotContainsString('googletag.defineSlot', json_encode($tagRecipe, JSON_UNESCAPED_SLASHES) ?: '');
        $this->assertStringNotContainsString('googletag.cmd.push', json_encode($tagRecipe, JSON_UNESCAPED_SLASHES) ?: '');

        $afterVersions = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();
        $this->assertGreaterThan($beforeVersions, $afterVersions);
    }

    public function test_google_gpt_size_must_match_the_selected_horus_placement(): void
    {
        $tag = str_replace('[300, 250]', '[728, 90]', $this->gptTag());

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload(['tag' => $tag, 'tag_input_type' => 'PROVIDER_TAG']))
            ->assertSessionHasErrors('tag');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandSite::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_private_or_reserved_script_origin_is_rejected_before_any_configuration_is_created(): void
    {
        $tag = '<script async src="https://127.0.0.1/ad.js"></script><div id="zone"></div>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload(['tag' => $tag]))
            ->assertSessionHasErrors('tag');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandSite::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_protocol_relative_provider_script_is_normalized_and_generic_tag_uses_sized_trusted_isolation_runtime(): void
    {
        $tag = '<script async src="//cdn.taboola.com/libtrc/horus-test/loader.js"></script><div id="taboola-zone"></div>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload(['tag' => $tag, 'tag_input_type' => 'PROVIDER_TAG']))
            ->assertRedirect();

        $account = DemandAccount::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['https://cdn.taboola.com'], data_get($account->configuration, 'allowed_script_origins'));

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.header_banner.candidates.0');
        $isolationRuntimeHash = substr((string) hash_file('sha256', public_path('assets/hm-isolated-direct.js')), 0, 16);
        $this->assertSame('STRUCTURED', data_get($candidate, 'tag.executionMode'));
        $this->assertSame(
            'https://cdn.horusmedia.net/runtime/direct/hm-isolated-direct.'.$isolationRuntimeHash.'.js',
            data_get($candidate, 'tag.scripts.0.url'),
        );
        $this->assertSame([[300, 250]], data_get($candidate, 'tag.render.allowedSizes'));
        $this->assertSame('300', data_get($candidate, 'tag.container.attributes.data-hm-isolated-width'));
        $this->assertSame('250', data_get($candidate, 'tag.container.attributes.data-hm-isolated-height'));

        $csp = base64_decode((string) data_get($candidate, 'tag.container.attributes.data-hm-isolated-csp'), true);
        $this->assertIsString($csp);
        $this->assertStringContainsString('connect-src https://cdn.taboola.com;', $csp);
        $this->assertStringNotContainsString('connect-src https:;', $csp);
        $this->assertStringNotContainsString('frame-src https:;', $csp);

        $isolatedHtml = base64_decode((string) data_get($candidate, 'tag.container.attributes.data-hm-isolated-html'), true);
        $this->assertIsString($isolatedHtml);
        $this->assertStringContainsString('//cdn.taboola.com/libtrc/horus-test/loader.js', $isolatedHtml);
    }

    public function test_plain_vast_url_creates_a_floating_video_surface_and_horus_player_recipe(): void
    {
        $this->seed(AdFormatSeeder::class);
        $this->bindPublicProviderDns();
        $vastUrl = 'https://vast.vendor.net/tag?placement=floating&v=4';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload([
                'placement_mode' => 'new',
                'placement_id' => null,
                'placement_preset' => 'responsive_display',
                'tag' => $vastUrl,
            ]))
            ->assertRedirect();

        $placement = \App\Models\Placement::withoutGlobalScopes()
            ->where('site_id', $this->site->id)
            ->where('code', 'quick_video_floating')
            ->firstOrFail();
        $widget = DemandWidget::withoutGlobalScopes()->firstOrFail();
        $account = DemandAccount::withoutGlobalScopes()->firstOrFail();

        $this->assertSame('VIDEO', $placement->type->value);
        $this->assertSame('bottom_right', data_get($placement->format_settings, 'position'));
        $this->assertTrue((bool) data_get($placement->format_settings, 'closeable'));
        $this->assertTrue((bool) data_get($placement->format_settings, 'singleActiveVideo'));
        $this->assertSame($vastUrl, $widget->direct_tag_template);
        $this->assertSame('VAST_URL', data_get($widget->configuration, 'input_kind'));
        $this->assertSame('https://vast.vendor.net', data_get($widget->configuration, 'vast_origin'));
        $this->assertContains('https://imasdk.googleapis.com', (array) data_get($account->configuration, 'allowed_script_origins'));

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.quick_video_floating.candidates.0');
        $runtimeHash = substr((string) hash_file('sha256', public_path('assets/hm-video-direct.js')), 0, 16);
        $this->assertSame('STRUCTURED', data_get($candidate, 'tag.executionMode'));
        $this->assertSame('VIDEO', data_get($candidate, 'tag.format'));
        $this->assertSame(
            'https://cdn.horusmedia.net/runtime/video/hm-video-direct.'.$runtimeHash.'.js',
            data_get($candidate, 'tag.scripts.0.url'),
        );
        $this->assertSame($vastUrl, base64_decode((string) data_get($candidate, 'tag.container.attributes.data-hm-vast-url'), true));
        $this->assertSame(['VIDEO', 'OUTSTREAM'], data_get($candidate, 'tag.render.allowedFormats'));
        $this->assertGreaterThanOrEqual(15_000, (int) data_get($candidate, 'tag.render.timeoutMs'));
        $this->assertStringContainsString('data-hm-video-status="started"', (string) data_get($candidate, 'tag.render.successSelector'));
    }

    public function test_gam_unit_path_creates_official_gpt_rewarded_recipe(): void
    {
        $this->seed(AdFormatSeeder::class);
        $this->bindPublicProviderDns();
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
            'placement_mode' => 'new', 'placement_id' => null, 'placement_preset' => 'rewarded',
            'tag_input_type' => 'GAM_AD_UNIT_PATH',
            'tag' => '/23055873217/rewarded',
        ]))->assertSessionHasNoErrors()->assertRedirect();
        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $tag = data_get($configuration, 'placements.quick_rewarded.candidates.0.tag');
        $this->assertSame('REWARDED', $tag['format']);
        $this->assertSame('1', data_get($tag, 'container.attributes.data-hm-gpt-rewarded'));
        $this->assertSame('/23055873217/rewarded', data_get($tag, 'container.attributes.data-hm-gpt-ad-unit-path'));
        $this->assertNull(data_get($tag, 'container.attributes.data-hm-vast-url'));
        $this->assertStringContainsString('/runtime/gpt/', $tag['scriptUrl']);
        $this->assertSame(30000, data_get($tag, 'render.timeoutMs'));
        $this->assertSame([], data_get($tag, 'render.allowedSizes'));
        $this->assertSame('GAM_REWARDED_PATH', data_get(DemandWidget::withoutGlobalScopes()->firstOrFail()->configuration, 'input_kind'));
    }

    public function test_gam_path_creates_six_manual_responsive_units_using_each_units_configured_sizes(): void
    {
        $this->seed(AdFormatSeeder::class);
        $path = '/1234567,7654321/news/responsive';
        $before = $this->site->configVersions()->count();

        $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload([
            'tag_input_type' => 'GAM_AD_UNIT_PATH', 'tag' => '  '.$path.'  ',
        ]))->assertSessionHasNoErrors()->assertRedirect()
            ->assertSessionHas('status', fn ($value) => str_contains((string) $value, 'Responsive Display · 6 manual placements'));

        $units = $this->responsiveUnits();
        $this->assertCount(6, $units);
        $this->assertSame($before + 1, $this->site->configVersions()->count());
        $config = $this->publishedConfiguration();
        $runtimeIds = [];
        foreach ($units as $unit) {
            $public = collect($config['placements'])->firstWhere('code', $unit->code);
            $recipe = data_get($config, 'directDemand.placements.'.$unit->code.'.candidates.0.tag');
            $this->assertFalse(data_get($public, 'format.settings.autoMount'));
            $this->assertSame('center', data_get($public, 'format.settings.contentAlignment'));
            $this->assertSame('DIRECT_JS', $public['renderer']);
            $this->assertSame('DISPLAY', data_get($recipe, 'format'));
            $this->assertSame($path, data_get($recipe, 'container.attributes.data-hm-gpt-ad-unit-path'));
            $this->assertEqualsCanonicalizing($public['sizes'], data_get($recipe, 'render.allowedSizes'));
            $this->assertEqualsCanonicalizing($public['sizes'], json_decode(data_get($recipe, 'container.attributes.data-hm-gpt-sizes'), true, flags: JSON_THROW_ON_ERROR));
            foreach ([[200, 200], [250, 250], [300, 50], [300, 100], [468, 60], [970, 90], [300, 600]] as $expandedSize) {
                $this->assertContains($expandedSize, data_get($recipe, 'render.allowedSizes'));
            }
            $mobile = collect($public['responsiveMappings'])->firstWhere('device', 'MOBILE');
            $this->assertNotContains([300, 600], $mobile['sizes']);
            $this->assertNotContains([970, 90], $mobile['sizes']);
            $this->assertNull(data_get($recipe, 'container.attributes.data-hm-gpt-rewarded'));
            $this->assertNull(data_get($recipe, 'container.attributes.data-hm-vast-url'));
            $runtimeIds[] = data_get($recipe, 'container.id');
        }
        $this->assertCount(6, array_unique($runtimeIds));
        $widgets = DemandWidget::withoutGlobalScopes()->get();
        $this->assertCount(6, $widgets);
        $this->assertSame([$path], $widgets->pluck('direct_tag_template')->unique()->values()->all());
        foreach ($widgets as $widget) {
            $this->assertSame('GAM_AD_UNIT_PATH', data_get($widget->configuration, 'input_kind'));
        }
    }

    public function test_legacy_auto_input_accepts_a_display_path_without_changing_the_existing_placement(): void
    {
        $before = $this->placement->refresh()->getAttributes();
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
            'tag' => '/1234567/header',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame($before, $this->placement->fresh()->getAttributes());
        $recipe = data_get($this->publishedConfiguration(), 'directDemand.placements.header_banner.candidates.0.tag');
        $this->assertSame('/1234567/header', data_get($recipe, 'container.attributes.data-hm-gpt-ad-unit-path'));
        $this->assertSame([[300, 250]], data_get($recipe, 'render.allowedSizes'));
        $this->assertSame(1, DemandWidget::withoutGlobalScopes()->count());
    }

    public function test_gam_path_uses_sticky_sizes_and_preserves_each_existing_sticky_setting(): void
    {
        $this->seed(AdFormatSeeder::class);
        foreach (['sticky_top', 'sticky_bottom'] as $preset) {
            $path = '/1234567/'.$preset;
            $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
                'placement_mode' => 'new', 'placement_id' => null, 'placement_preset' => $preset,
                'tag_input_type' => 'GAM_AD_UNIT_PATH', 'tag' => $path,
            ]))->assertSessionHasNoErrors();

            $placement = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->where('code', 'quick_'.$preset)->firstOrFail();
            $settings = $placement->format_settings;
            $this->assertTrue($settings['autoMount']);
            $this->assertTrue($settings['closeable']);
            $settings['closeable'] = false;
            $settings['surface']['testPublisherPreference'] = 'preserve';
            $placement->update(['format_settings' => $settings]);
            $before = $placement->refresh()->getAttributes();
            $beforeSizes = $placement->sizes()->get()->map(fn ($size) => $size->getAttributes())->all();
            $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
                'placement_id' => $placement->id,
                'tag_input_type' => 'GAM_AD_UNIT_PATH', 'tag' => $path.'_updated',
            ]))->assertSessionHasNoErrors();

            $this->assertSame($before, $placement->fresh()->getAttributes());
            $this->assertSame($beforeSizes, $placement->sizes()->get()->map(fn ($size) => $size->getAttributes())->all());
            $config = $this->publishedConfiguration();
            $public = collect($config['placements'])->firstWhere('code', $placement->code);
            $recipe = data_get($config, 'directDemand.placements.'.$placement->code.'.candidates.0.tag');
            $this->assertSame($path.'_updated', data_get($recipe, 'container.attributes.data-hm-gpt-ad-unit-path'));
            $this->assertEqualsCanonicalizing($public['sizes'], data_get($recipe, 'render.allowedSizes'));
            $this->assertContains([728, 90], data_get($recipe, 'render.allowedSizes'));
            $this->assertContains([320, 50], data_get($recipe, 'render.allowedSizes'));
            $this->assertNotContains([300, 250], data_get($recipe, 'render.allowedSizes'));
            $this->assertFalse(data_get($public, 'format.settings.closeable'));
        }
        $this->assertSame(2, DemandWidget::withoutGlobalScopes()->count());
        $this->assertCount(0, $this->responsiveUnits());
    }

    public function test_explicit_input_mode_rejects_mismatched_or_invalid_paths_without_partial_writes(): void
    {
        $this->seed(AdFormatSeeder::class);
        $before = $this->site->configVersions()->count();
        foreach ([
            ['GAM_AD_UNIT_PATH', $this->gptTag(), 'tag'],
            ['GAM_AD_UNIT_PATH', 'https://vast.vendor.net/tag', 'tag'],
            ['GAM_AD_UNIT_PATH', '//1234567/header', 'tag'],
            ['GAM_AD_UNIT_PATH', '/network/header', 'tag'],
            ['GAM_AD_UNIT_PATH', '/1234567/header?extra=1', 'tag'],
            ['GAM_AD_UNIT_PATH', '/1234567/header<script>alert(1)</script>', 'tag'],
            ['PROVIDER_TAG', '/1234567/header', 'tag'],
            ['UNRECOGNIZED', '/1234567/header', 'tag_input_type'],
        ] as [$mode, $tag, $error]) {
            $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload([
                'tag_input_type' => $mode, 'tag' => $tag,
            ]))->assertSessionHasErrors($error);
        }
        $this->assertCount(0, $this->responsiveUnits());
        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandSite::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandPlacement::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
        $this->assertSame($before, $this->site->configVersions()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_gam_path_and_provider_tag_switches_reuse_the_bundle_and_preserve_live_other_formats_and_protection(): void
    {
        $this->seed(AdFormatSeeder::class);
        $this->bindPublicProviderDns();
        config(['traffic_gate.origin' => 'https://verify.horusmedia.net']);
        $settings = app(GlobalSettingsService::class);
        $settings->set($this->admin, 'traffic_gate.site_key', '0x4AAAAA_quick_path_test', 'Enable protected path-mode test.');
        $settings->set($this->admin, 'traffic_gate.enabled', true, 'Enable protected path-mode test.');
        $this->site->siteConfig()->update(['click_guard_settings' => [
            'inheritGlobal' => false, 'enabled' => true, 'maxClicks' => 5, 'windowHours' => 8, 'blockHours' => 24,
        ]]);
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload())->assertSessionHasNoErrors();
        foreach (['video_floating' => 'https://vast.vendor.net/tag?slot=floating', 'rewarded' => '/1234567/rewarded'] as $preset => $tag) {
            $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
                'placement_mode' => 'new', 'placement_id' => null, 'placement_preset' => $preset, 'tag' => $tag,
            ]))->assertSessionHasNoErrors();
        }
        $before = $this->publishedConfiguration();
        $this->assertTrue($before['trafficGate']['enabled']);
        $this->assertTrue($before['clickGuard']['enabled']);
        $others = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->get()
            ->mapWithKeys(fn ($placement) => [$placement->id => $placement->getAttributes()])->all();
        $otherWidgets = DemandWidget::withoutGlobalScopes()->get()
            ->mapWithKeys(fn ($widget) => [$widget->id => $widget->getAttributes()])->all();

        $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload([
            'tag_input_type' => 'PROVIDER_TAG',
        ]))->assertSessionHasNoErrors();
        $ids = $this->responsiveUnits()->pluck('id')->all();
        $widgets = DemandWidget::withoutGlobalScopes()->whereNotIn('id', array_keys($otherWidgets))->orderBy('id')->pluck('id')->all();
        foreach (['GAM_AD_UNIT_PATH', 'PROVIDER_TAG', 'GAM_AD_UNIT_PATH'] as $mode) {
            $tag = $mode === 'GAM_AD_UNIT_PATH' ? '/1234567/updated_responsive' : $this->gptTag();
            $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
                'placement_id' => $ids[2], 'tag_input_type' => $mode, 'tag' => $tag,
            ]))->assertSessionHasNoErrors();
            $this->assertSame($ids, $this->responsiveUnits()->pluck('id')->all());
            $this->assertSame($widgets, DemandWidget::withoutGlobalScopes()->whereNotIn('id', array_keys($otherWidgets))->orderBy('id')->pluck('id')->all());
            $after = $this->publishedConfiguration();
            foreach ($this->responsiveUnits() as $unit) {
                $recipe = data_get($after, 'directDemand.placements.'.$unit->code.'.candidates.0.tag');
                $public = collect($after['placements'])->firstWhere('code', $unit->code);
                $expectedPath = $mode === 'GAM_AD_UNIT_PATH' ? $tag : '/1234567/lordai_header';
                $expectedSizes = $mode === 'GAM_AD_UNIT_PATH' ? $public['sizes'] : [[300, 250]];
                $this->assertSame($expectedPath, data_get($recipe, 'container.attributes.data-hm-gpt-ad-unit-path'));
                $this->assertEqualsCanonicalizing($expectedSizes, data_get($recipe, 'render.allowedSizes'));
            }
            foreach (DemandWidget::withoutGlobalScopes()->whereIn('id', $widgets)->get() as $widget) {
                $this->assertSame($mode, data_get($widget->configuration, 'input_kind'));
                $this->assertSame($tag, $widget->direct_tag_template);
                $this->assertArrayNotHasKey('vast_origin', $widget->configuration);
            }
            foreach (['trafficGate', 'clickGuard', 'controls', 'privacy'] as $key) {
                $this->assertSame($before[$key], $after[$key], $key);
            }
            foreach (['header_banner', 'quick_video_floating', 'quick_rewarded'] as $code) {
                $this->assertSame(data_get($before, 'directDemand.placements.'.$code), data_get($after, 'directDemand.placements.'.$code), $code);
                $this->assertSame(collect($before['placements'])->firstWhere('code', $code), collect($after['placements'])->firstWhere('code', $code));
            }
        }
        foreach ($others as $id => $attributes) {
            $this->assertSame($attributes, Placement::withoutGlobalScopes()->findOrFail($id)->getAttributes());
        }
        foreach ($otherWidgets as $id => $attributes) {
            $this->assertSame($attributes, DemandWidget::withoutGlobalScopes()->findOrFail($id)->getAttributes());
        }
    }

    public function test_gam_rewarded_path_cannot_be_saved_as_floating_video(): void
    {
        $this->seed(AdFormatSeeder::class);
        foreach (['AUTO', 'GAM_AD_UNIT_PATH'] as $mode) {
            foreach (['video_floating', 'video_outstream'] as $preset) {
                $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
                    'placement_mode' => 'new', 'placement_id' => null, 'placement_preset' => $preset,
                    'tag_input_type' => $mode, 'tag' => '/23055873217/rewarded',
                ]))->assertSessionHasErrors('placement_preset');
            }
        }
        $this->assertDatabaseMissing('placements', ['site_id' => $this->site->id, 'code' => 'quick_video_floating']);
        $this->assertDatabaseMissing('placements', ['site_id' => $this->site->id, 'code' => 'quick_video_outstream']);
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
    }

    public function test_a_gam_path_cannot_replace_a_working_vast_video_or_publish_partial_changes(): void
    {
        $this->seed(AdFormatSeeder::class);
        $this->bindPublicProviderDns();
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
            'placement_mode' => 'new', 'placement_id' => null, 'placement_preset' => 'video_floating',
            'tag' => 'https://vast.vendor.net/tag?slot=floating',
        ]))->assertSessionHasNoErrors();
        $video = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->where('code', 'quick_video_floating')->firstOrFail();
        $before = $this->publishedConfiguration();
        $beforeVersions = $this->site->configVersions()->count();
        $widget = DemandWidget::withoutGlobalScopes()->firstOrFail();
        $beforeWidget = $widget->getAttributes();

        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
            'placement_id' => $video->id, 'tag_input_type' => 'GAM_AD_UNIT_PATH', 'tag' => '/1234567/video',
        ]))->assertSessionHasErrors('placement_id');

        $this->assertSame($beforeWidget, $widget->fresh()->getAttributes());
        $this->assertSame($before, $this->publishedConfiguration());
        $this->assertSame($beforeVersions, $this->site->configVersions()->count());
    }

    public function test_runtime_refresh_is_dry_run_by_default_and_idempotent_when_applied(): void
    {
        $this->seed(AdFormatSeeder::class);
        $this->bindPublicProviderDns();
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
            'placement_mode' => 'new', 'placement_id' => null, 'placement_preset' => 'rewarded',
            'tag' => '/23055873217/rewarded',
        ]))->assertSessionHasNoErrors();
        $version = $this->site->configVersions()->orderByDesc('version')->firstOrFail();
        $payload = $version->payload;
        data_set($payload, 'directDemand.placements.quick_rewarded.candidates.0.tag.scripts.0.url', 'https://cdn.horusmedia.net/runtime/gpt/hm-gpt-direct.0000000000000000.js');
        $version->update(['payload' => $payload, 'checksum' => hash('sha256', app(\App\Services\StaticDelivery\CanonicalJson::class)->encode($payload))]);
        $count = $this->site->configVersions()->count();
        $this->artisan('demand:refresh-quick-runtimes')->assertSuccessful();
        $this->assertSame($count, $this->site->configVersions()->count());
        $this->artisan('demand:refresh-quick-runtimes', ['--apply' => true])->assertSuccessful();
        $this->assertSame($count + 1, $this->site->configVersions()->count());
        $this->artisan('demand:refresh-quick-runtimes', ['--apply' => true])->assertSuccessful();
        $this->assertSame($count + 1, $this->site->configVersions()->count());
    }

    public function test_rewarded_cooldown_upgrade_preserves_custom_settings_and_is_idempotent(): void
    {
        $this->seed(AdFormatSeeder::class);
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
            'placement_mode' => 'new', 'placement_id' => null, 'placement_preset' => 'rewarded',
            'tag' => '/23055873217/rewarded',
        ]))->assertSessionHasNoErrors();
        $placement = \App\Models\Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->where('code', 'quick_rewarded')->firstOrFail();
        $settings = $placement->format_settings;
        $settings['rewardCooldownSeconds'] = 900;
        $placement->update(['format_settings' => $settings]);
        $migration = require database_path('migrations/2026_09_20_010000_reduce_default_rewarded_cooldown.php');
        $migration->up();
        $migration->up();
        $this->assertSame(60, $placement->fresh()->format_settings['rewardCooldownSeconds']);
        $settings['rewardCooldownSeconds'] = 120;
        $placement->update(['format_settings' => $settings]);
        $migration->up();
        $this->assertSame(120, $placement->fresh()->format_settings['rewardCooldownSeconds']);
        $settings['rewardCooldownSeconds'] = 900;
        $placement->update(['format_settings' => $settings, 'metadata' => []]);
        $migration->up();
        $this->assertSame(900, $placement->fresh()->format_settings['rewardCooldownSeconds']);
    }

    public function test_plain_vast_url_can_create_a_user_initiated_rewarded_surface(): void
    {
        $this->seed(AdFormatSeeder::class);
        $this->bindPublicProviderDns();
        $vastUrl = 'https://vast.vendor.net/tag?placement=rewarded&v=4';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload([
                'placement_mode' => 'new',
                'placement_id' => null,
                'placement_preset' => 'rewarded',
                'tag' => $vastUrl,
            ]))
            ->assertRedirect();

        $placement = \App\Models\Placement::withoutGlobalScopes()
            ->where('site_id', $this->site->id)
            ->where('code', 'quick_rewarded')
            ->firstOrFail();

        $this->assertSame('REWARDED', $placement->type->value);
        $this->assertTrue((bool) data_get($placement->format_settings, 'rewarded'));
        $this->assertTrue((bool) data_get($placement->format_settings, 'requireUserActivation'));
        $this->assertSame(60, data_get($placement->format_settings, 'rewardCooldownSeconds'));

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.quick_rewarded.candidates.0');
        $this->assertSame('STRUCTURED', data_get($candidate, 'tag.executionMode'));
        $this->assertSame('REWARDED', data_get($candidate, 'tag.format'));
        $this->assertSame(['REWARDED'], data_get($candidate, 'tag.render.allowedFormats'));
        $this->assertSame('1', data_get($candidate, 'tag.container.attributes.data-hm-video-rewarded'));
        $this->assertSame('continue-reading', data_get($candidate, 'tag.container.attributes.data-hm-reward-experience'));
        $this->assertSame('0', data_get($candidate, 'tag.container.attributes.data-hm-video-autoplay'));
        $this->assertSame('0', data_get($candidate, 'tag.container.attributes.data-hm-video-muted'));
        $this->assertSame('60', data_get($candidate, 'tag.container.attributes.data-hm-reward-cooldown-seconds'));
        $this->assertStringContainsString('data-hm-video-status="reward-ready"', (string) data_get($candidate, 'tag.render.successSelector'));
        $this->assertStringContainsString('data-hm-video-status="reward-capped"', (string) data_get($candidate, 'tag.render.successSelector'));
    }

    public function test_complete_provider_tag_on_rewarded_surface_runs_through_trusted_isolation_instead_of_the_horus_vast_player(): void
    {
        $this->seed(AdFormatSeeder::class);
        $tag = '<script async src="//cdn.taboola.com/libtrc/horus-rewarded/loader.js"></script><div id="rewarded-zone"></div>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload([
                'placement_mode' => 'new',
                'placement_id' => null,
                'placement_preset' => 'rewarded',
                'tag' => $tag,
            ]))
            ->assertRedirect();

        $placement = \App\Models\Placement::withoutGlobalScopes()
            ->where('site_id', $this->site->id)
            ->where('code', 'quick_rewarded')
            ->firstOrFail();
        $widget = DemandWidget::withoutGlobalScopes()->firstOrFail();
        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.quick_rewarded.candidates.0');
        $isolatedHtml = base64_decode((string) data_get($candidate, 'tag.container.attributes.data-hm-isolated-html'), true);

        $this->assertSame('REWARDED', $placement->type->value);
        $this->assertSame('PROVIDER_TAG', data_get($widget->configuration, 'input_kind'));
        $this->assertSame($tag, $widget->direct_tag_template);
        $this->assertSame('STRUCTURED', data_get($candidate, 'tag.executionMode'));
        $this->assertSame('REWARDED', data_get($candidate, 'tag.format'));
        $this->assertSame(['REWARDED'], data_get($candidate, 'tag.render.allowedFormats'));
        $this->assertSame('1', data_get($candidate, 'tag.container.attributes.data-hm-isolated-direct'));
        $this->assertNull(data_get($candidate, 'tag.container.attributes.data-hm-video-direct'));
        $this->assertSame($tag, $isolatedHtml);
    }

    public function test_vast_url_is_rejected_for_a_non_video_placement_without_partial_demand_writes(): void
    {
        $this->bindPublicProviderDns();

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload([
                'tag' => 'https://vast.vendor.net/tag?placement=wrong-surface',
            ]))
            ->assertSessionHasErrors('tag');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandSite::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_non_https_or_private_vast_url_is_rejected_before_configuration_is_created(): void
    {
        foreach (['http://vast.vendor.net/tag', 'https://127.0.0.1/vast'] as $vastUrl) {
            $this->adminSession()
                ->post(route('admin.demand.quick.store'), $this->payload(['tag' => $vastUrl]))
                ->assertSessionHasErrors('tag');
        }

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
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

    public function test_existing_gam_renderer_is_rejected_without_partial_quick_monetize_writes(): void
    {
        $beforeVersions = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();
        $this->site->update([
            'serving_mode' => ServingMode::HorusGam,
            'current_gam_network_code' => '1234567',
            'native_demand_enabled' => false,
        ]);
        $this->site->servingSettings()->update(['serving_mode' => ServingMode::HorusGam]);

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload())
            ->assertSessionHasErrors('placement_id');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandSite::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
        $this->assertSame(
            $beforeVersions,
            ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count(),
        );
        $this->assertDatabaseMissing('audit_logs', [
            'event' => 'demand.site.direct_demand_enabled_changed',
        ]);
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

    public function test_soft_deleted_site_is_hidden_and_cannot_be_quick_monetized(): void
    {
        $siteId = $this->site->id;
        $this->site->delete();

        $this->adminSession()
            ->get(route('admin.demand.quick.create'))
            ->assertOk()
            ->assertDontSee('lordai.net');

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload(['site_id' => $siteId]))
            ->assertNotFound();

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
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

    public function test_dynamic_function_constructor_is_rejected_but_normal_gpt_function_callback_is_allowed(): void
    {
        $unsafe = <<<'HTML'
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>
<div id="div-gpt-ad-lordai-header"></div>
<script>
const execute = Function('return 1');
execute();
</script>
HTML;

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload(['tag' => $unsafe]))
            ->assertSessionHasErrors('tag');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandSite::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload())
            ->assertRedirect();
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

    public function test_site_page_one_click_expand_reuses_existing_tag_preserves_four_ids_and_publishes_six(): void
    {
        $firstFour = $this->legacyFourMemberResponsiveBundle();
        $firstFourIds = $firstFour->pluck('id')->all();
        $firstFourCodes = $firstFour->pluck('code')->all();
        $tag = DemandWidget::withoutGlobalScopes()->firstOrFail()->direct_tag_template;
        $beforeVersions = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();

        $this->adminSession()
            ->get(route('admin.sites.show', $this->site))
            ->assertOk()
            ->assertSee('Responsive Display · 4 placement codes')
            ->assertSee('Expand to 6 placements')
            ->assertSee('existing codes remain unchanged');

        $this->adminSession()
            ->post(route('admin.sites.demand.quick-responsive.expand', $this->site))
            ->assertRedirect(route('admin.sites.show', $this->site))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($value) => str_contains((string) $value, 'expanded from 4 to 6 placements'));

        $units = $this->responsiveUnits()
            ->sortBy(fn ($unit) => (int) data_get($unit->metadata, 'responsive_bundle_index'))
            ->values();
        $this->assertCount(6, $units);
        $this->assertSame($firstFourIds, $units->take(4)->pluck('id')->all());
        $this->assertSame($firstFourCodes, $units->take(4)->pluck('code')->all());
        $this->assertSame('quick_responsive_display_5', $units[4]->code);
        $this->assertSame('quick_responsive_display_6', $units[5]->code);
        $this->assertSame(6, DemandWidget::withoutGlobalScopes()->where('is_enabled', true)->count());
        $this->assertSame([$tag], DemandWidget::withoutGlobalScopes()->pluck('direct_tag_template')->unique()->values()->all());
        $this->assertSame($beforeVersions + 1, ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count());

        $config = $this->publishedConfiguration();
        foreach ($units as $unit) {
            $this->assertNotEmpty(data_get($config, 'directDemand.placements.'.$unit->code.'.candidates', []));
            $this->assertFalse((bool) data_get(
                collect($config['placements'])->firstWhere('code', $unit->code),
                'format.settings.autoMount',
            ));
        }
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'demand.quick.responsive_bundle.expanded',
            'auditable_id' => $this->site->id,
        ]);

        $this->adminSession()
            ->get(route('admin.sites.show', $this->site))
            ->assertOk()
            ->assertSee('Responsive Display · 6 placement codes')
            ->assertDontSee('Expand to 6 placements');
    }

    public function test_one_click_expand_fails_closed_when_legacy_bundle_tags_disagree(): void
    {
        $this->legacyFourMemberResponsiveBundle();
        $widget = DemandWidget::withoutGlobalScopes()->orderByDesc('id')->firstOrFail();
        $widget->update(['direct_tag_template' => str_replace('lordai_header', 'conflicting_unit', $widget->direct_tag_template)]);
        $beforeVersions = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();
        $beforeIds = $this->responsiveUnits()->pluck('id')->all();

        $this->adminSession()
            ->post(route('admin.sites.demand.quick-responsive.expand', $this->site))
            ->assertSessionHasErrors('quick');

        $this->assertCount(4, $this->responsiveUnits());
        $this->assertSame($beforeIds, $this->responsiveUnits()->pluck('id')->all());
        $this->assertSame($beforeVersions, ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count());
        $this->assertDatabaseMissing('placements', ['site_id' => $this->site->id, 'code' => 'quick_responsive_display_5']);
        $this->assertDatabaseMissing('placements', ['site_id' => $this->site->id, 'code' => 'quick_responsive_display_6']);
    }

    public function test_publisher_cannot_use_or_see_admin_responsive_expansion_action(): void
    {
        $this->legacyFourMemberResponsiveBundle();

        $this->actingAs($this->publisherUser)
            ->get(route('publisher.sites.show', $this->site))
            ->assertOk()
            ->assertDontSee('Expand to 6 placements');

        $this->actingAs($this->publisherUser)
            ->post(route('admin.sites.demand.quick-responsive.expand', $this->site))
            ->assertForbidden();

        $this->assertCount(4, $this->responsiveUnits());
    }

    public function test_existing_four_member_responsive_bundle_expands_to_six_without_changing_the_first_four_ids(): void
    {
        $this->seed(AdFormatSeeder::class);
        $builder = app(PlacementPresetBuilder::class);
        $created = collect($builder->responsiveBundle($this->site, $this->admin));
        $this->assertCount(6, $created);

        $firstFourIds = $created->take(4)->pluck('id')->all();
        foreach ($created->slice(4) as $unit) {
            $unit->sizes()->delete();
            $unit->targeting()->delete();
            $unit->forceDelete();
        }
        $this->assertCount(4, $this->responsiveUnits());

        $expanded = collect($builder->responsiveBundle($this->site->fresh(), $this->admin));
        $this->assertCount(6, $expanded);
        $this->assertSame($firstFourIds, $expanded->take(4)->pluck('id')->all());
        $this->assertSame(
            [1, 2, 3, 4, 5, 6],
            $expanded->map(fn ($unit) => (int) data_get($unit->metadata, 'responsive_bundle_index'))->all(),
        );
        $this->assertSame(
            ['quick_responsive_display', 'quick_responsive_display_2', 'quick_responsive_display_3', 'quick_responsive_display_4', 'quick_responsive_display_5', 'quick_responsive_display_6'],
            $expanded->pluck('code')->all(),
        );
    }

    public function test_responsive_activation_publishes_six_manual_centered_slots_with_one_tag_and_unique_runtime_ids(): void
    {
        $this->seed(AdFormatSeeder::class);
        $before = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload())->assertSessionHasNoErrors();
        $units = $this->responsiveUnits();
        $this->assertCount(6, $units);
        $this->assertSame($before + 1, ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count());
        $config = app(SiteConfigurationBuilder::class)->build($this->site->fresh(), ConfigEnvironment::Production, 1);
        $ids = [];
        foreach ($units as $unit) {
            $public = collect($config['placements'])->firstWhere('code', $unit->code);
            $this->assertFalse(data_get($public, 'format.settings.autoMount'));
            $this->assertSame('center', data_get($public, 'format.settings.contentAlignment'));
            $this->assertSame('DIRECT_JS', $public['renderer']);
            $this->assertTrue($public['enabled']);
            $recipe = data_get($config, 'directDemand.placements.'.$unit->code.'.candidates.0.tag');
            $ids[] = data_get($recipe, 'container.id');
            $this->assertSame('/1234567/lordai_header', data_get($recipe, 'container.attributes.data-hm-gpt-ad-unit-path'));
        }
        $this->assertCount(6, array_unique($ids));
        $widgets = DemandWidget::withoutGlobalScopes()->get();
        $this->assertCount(6, $widgets);
        $this->assertSame([$this->gptTag()], $widgets->pluck('direct_tag_template')->unique()->values()->all());
    }

    public function test_responsive_retry_and_editing_a_member_update_the_same_six_without_changing_other_formats_or_protection(): void
    {
        $this->seed(AdFormatSeeder::class);
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload())->assertSessionHasNoErrors();
        $existingWidget = DemandWidget::withoutGlobalScopes()->firstOrFail();
        $existingWidgetAttributes = $existingWidget->getAttributes();
        foreach (['sticky_bottom', 'sticky_top', 'video_floating', 'rewarded', 'in_article_display', 'high_impact_display'] as $preset) {
            app(PlacementPresetBuilder::class)->create($this->site, $preset, $this->admin, [], false, true);
        }
        $other = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->get()->mapWithKeys(fn ($p) => [$p->id => $p->getAttributes()])->all();
        $before = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->where('environment', ConfigEnvironment::Production->value)->orderByDesc('version')->firstOrFail()->payload;
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload())->assertSessionHasNoErrors();
        $ids = $this->responsiveUnits()->pluck('id')->all();
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload())->assertSessionHasNoErrors();
        $tag = str_replace('lordai_header', 'updated_unit', $this->gptTag());
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->payload([
            'placement_id' => $ids[2], 'tag' => $tag,
        ]))->assertSessionHasNoErrors();
        $this->assertSame($ids, $this->responsiveUnits()->pluck('id')->all());
        $this->assertSame(7, DemandWidget::withoutGlobalScopes()->count());
        $this->assertSame([$tag], DemandWidget::withoutGlobalScopes()->where('id', '!=', $existingWidget->id)->get()->pluck('direct_tag_template')->unique()->values()->all());
        $this->assertSame($existingWidgetAttributes, $existingWidget->fresh()->getAttributes());
        foreach ($other as $id => $attributes) $this->assertSame($attributes, Placement::withoutGlobalScopes()->findOrFail($id)->getAttributes());
        $after = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->where('environment', ConfigEnvironment::Production->value)->orderByDesc('version')->firstOrFail()->payload;
        foreach (['trafficGate', 'clickGuard', 'controls', 'privacy'] as $key) $this->assertSame($before[$key], $after[$key], $key);
        $this->assertSame(data_get($before, 'directDemand.placements.header_banner'), data_get($after, 'directDemand.placements.header_banner'));
    }

    public function test_one_blocked_bundle_member_rolls_back_all_tag_updates_and_does_not_publish(): void
    {
        $this->seed(AdFormatSeeder::class);
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload())->assertSessionHasNoErrors();
        $member = $this->responsiveUnits()->last();
        app(PlatformControlService::class)->set('PLACEMENT', $member->id, 'DIRECT_JS', true, 'Blocked member', $this->admin);
        $before = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();
        foreach (['PROVIDER_TAG' => str_replace('lordai_header', 'must_not_publish', $this->gptTag()), 'GAM_AD_UNIT_PATH' => '/1234567/must_not_publish'] as $mode => $tag) {
            $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload([
                'tag_input_type' => $mode, 'tag' => $tag,
            ]))->assertSessionHasErrors('quick');
        }
        $this->assertSame($before, ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count());
        $this->assertCount(6, $this->responsiveUnits());
        $this->assertSame([$this->gptTag()], DemandWidget::withoutGlobalScopes()->get()->pluck('direct_tag_template')->unique()->values()->all());
    }

    public function test_admin_and_owner_can_copy_each_code_but_another_publisher_cannot_access_them(): void
    {
        $this->seed(AdFormatSeeder::class);
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload())->assertSessionHasNoErrors();
        foreach (['admin.demand.quick.create', 'admin.sites.inventory.index', 'publisher.sites.show'] as $route) {
            if ($route === 'publisher.sites.show') $this->actingAs($this->publisherUser);
            $response = $this->get(route($route, ['site' => $this->site->id]))->assertOk();
            foreach ($this->responsiveUnits() as $unit) $response->assertSee($unit->installationCode());
            $response->assertDontSee('googletag.defineSlot');
        }
        $org = $this->makeOrganization(OrganizationType::Publisher, 'Another publisher');
        $outsider = $this->makeUser($org, RoleName::PublisherAdmin);
        $this->makePublisherFor($outsider);
        $this->actingAs($outsider)->get(route('publisher.sites.show', $this->site))->assertNotFound();
    }

    public function test_legacy_responsive_identity_is_reused_and_incompatible_tag_rolls_back_entire_bundle(): void
    {
        $this->seed(AdFormatSeeder::class);
        $legacy = app(PlacementPresetBuilder::class)->create($this->site, 'responsive_display', $this->admin, [], false, true);
        $before = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload([
            'tag' => str_replace('[300, 250]', '[999, 999]', $this->gptTag()),
        ]))->assertSessionHasErrors('tag');
        $this->assertCount(0, $this->responsiveUnits());
        $this->assertTrue(data_get($legacy->fresh()->format_settings, 'autoMount'));
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
        $this->assertSame($before, ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count());
        $this->adminSession()->post(route('admin.demand.quick.store'), $this->responsivePayload())->assertSessionHasNoErrors();
        $this->assertSame($legacy->id, $this->responsiveUnits()->first()->id);
        $this->assertCount(6, $this->responsiveUnits());
    }

    private function legacyFourMemberResponsiveBundle()
    {
        $this->seed(AdFormatSeeder::class);
        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->responsivePayload())
            ->assertSessionHasNoErrors();

        $units = $this->responsiveUnits()
            ->sortBy(fn ($unit) => (int) data_get($unit->metadata, 'responsive_bundle_index'))
            ->values();
        $this->assertCount(6, $units);

        foreach ($units->slice(4) as $unit) {
            $demandPlacements = DemandPlacement::withoutGlobalScopes()
                ->where('placement_id', $unit->id)
                ->get();
            DemandWidget::withoutGlobalScopes()
                ->whereIn('demand_placement_id', $demandPlacements->pluck('id'))
                ->delete();
            DemandPlacement::withoutGlobalScopes()
                ->whereIn('id', $demandPlacements->pluck('id'))
                ->delete();
            $unit->sizes()->delete();
            $unit->targeting()->delete();
            $unit->forceDelete();
        }

        $legacy = $this->responsiveUnits()
            ->sortBy(fn ($unit) => (int) data_get($unit->metadata, 'responsive_bundle_index'))
            ->values();
        $this->assertCount(4, $legacy);

        return $legacy;
    }

    private function responsiveUnits()
    {
        return Placement::withoutGlobalScopes()->where('site_id', $this->site->id)
            ->where('metadata->responsive_bundle', 'v1')->orderBy('code')->get();
    }

    private function publishedConfiguration(): array
    {
        return ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)
            ->where('environment', ConfigEnvironment::Production->value)->orderByDesc('version')->firstOrFail()->payload;
    }

    private function responsivePayload(array $overrides = []): array
    {
        return $this->payload(array_replace([
            'placement_mode' => 'new', 'placement_id' => null, 'placement_preset' => 'responsive_display',
        ], $overrides));
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

    private function bindPublicProviderDns(): void
    {
        $this->app->instance(PublicProviderOriginValidator::class, new class extends PublicProviderOriginValidator
        {
            protected function resolveAddresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }

    private function adminSession(): static
    {
        $this->actingAs($this->admin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }
}
