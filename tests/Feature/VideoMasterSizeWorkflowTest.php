<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\ConfigVersion;
use App\Models\DemandWidget;
use App\Models\Placement;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\PlacementPresetBuilder;
use App\Services\Inventory\VideoMasterSize;
use App\Services\Security\PublicProviderOriginValidator;
use Database\Seeders\AdFormatSeeder;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class VideoMasterSizeWorkflowTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, DemandNetworkSeeder::class, AdFormatSeeder::class]);
        $organization = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($organization, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Video Publisher');
        $user = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($user);
        $this->site = $this->makeSiteFor($publisher, $user, ['primary_domain' => 'video.example']);
        $this->site->update(['status' => SiteStatus::Active, 'serving_mode' => ServingMode::HorusDirect]);
        $this->site->servingSettings()->update(['serving_mode' => ServingMode::HorusDirect]);
        $this->site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);
        $this->app->instance(PublicProviderOriginValidator::class, new class extends PublicProviderOriginValidator {
            protected function resolveAddresses(string $host): array { return ['93.184.216.34']; }
        });
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
    }

    #[DataProvider('masterSizes')]
    public function test_all_six_master_sizes_save_and_publish_an_honest_single_size(string $master, array $dimensions): void
    {
        $loader = $this->site->installationCode();
        $this->post(route('admin.demand.quick.store'), $this->payload(['video_master_size' => $master]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $placement = $this->video();
        $this->assertSame($master, data_get($placement->format_settings, 'videoMasterSize'));
        $this->assertSame($dimensions[0].':'.$dimensions[1], data_get($placement->format_settings, 'surface.aspectRatio'));
        $this->assertSame([$dimensions], $placement->sizes->map(fn ($size) => [$size->width, $size->height])->unique()->values()->all());
        $this->assertCount(3, $placement->sizes);
        $version = $this->published();
        $attributes = data_get($version->payload, 'directDemand.placements.quick_video_floating.candidates.0.tag.container.attributes');
        $this->assertSame((string) $dimensions[0], $attributes['data-hm-video-width']);
        $this->assertSame((string) $dimensions[1], $attributes['data-hm-video-height']);
        $this->assertSame([$dimensions], json_decode($attributes['data-hm-video-sizes'], true));
        $mappings = json_decode($attributes['data-hm-video-size-map'], true);
        $this->assertCount(2, $mappings);
        foreach ($mappings as $mapping) $this->assertSame($dimensions, [$mapping['width'], $mapping['height']]);
        $hash = substr(hash_file('sha256', public_path('assets/hm-video-direct.js')), 0, 16);
        $this->assertSame('https://cdn.horusmedia.net/runtime/video/hm-video-direct.'.$hash.'.js', data_get($version->payload, 'directDemand.placements.quick_video_floating.candidates.0.tag.scripts.0.url'));
        $this->assertStringContainsString('/production.v'.$version->version.'.', $version->file_path);
        $this->assertDatabaseHas('static_delivery_items', ['config_version_id' => $version->id, 'status' => 'PENDING']);
        $this->assertSame($loader, $this->site->fresh()->installationCode());
    }

    #[DataProvider('masterSizes')]
    public function test_simple_inventory_builder_accepts_each_master(string $master, array $dimensions): void
    {
        $this->post(route('admin.sites.inventory.placements.simple', $this->site), [
            'placement_preset' => 'video_outstream', 'name' => 'Selected video', 'code' => 'selected_video', 'video_master_size' => $master,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $placement = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->where('code', 'selected_video')->firstOrFail();
        $this->assertSame($master, data_get($placement->format_settings, 'videoMasterSize'));
        $this->assertSame([$dimensions], $placement->sizes->map(fn ($size) => [$size->width, $size->height])->unique()->values()->all());
    }

    public function test_retagging_preserves_selected_size_and_legacy_responsive_policy_when_omitted(): void
    {
        $this->post(route('admin.demand.quick.store'), $this->payload(['video_master_size' => '640x480']))->assertSessionHasNoErrors();
        $video = $this->video();
        $code = $video->installationCode();
        $sizes = $video->sizes->toArray();
        $this->post(route('admin.demand.quick.store'), $this->payload())->assertSessionHasNoErrors();
        $this->assertSame($sizes, $this->video()->sizes->toArray());
        $this->assertSame('640x480', data_get($this->video()->format_settings, 'videoMasterSize'));
        $this->assertSame($code, $this->video()->installationCode());

        $legacy = app(PlacementPresetBuilder::class)->create($this->site, 'video_outstream', $this->admin, [], false, true);
        $legacySizes = $legacy->sizes->toArray();
        $this->post(route('admin.demand.quick.store'), $this->payload(['placement_preset' => 'video_outstream']))->assertSessionHasNoErrors();
        $this->assertSame($legacySizes, $legacy->fresh()->sizes->toArray());
        $this->assertNull(data_get($legacy->fresh()->format_settings, 'videoMasterSize'));
    }

    public function test_explicit_existing_selection_changes_only_video_size_and_preserves_identity_and_settings(): void
    {
        $this->post(route('admin.demand.quick.store'), $this->payload())->assertSessionHasNoErrors();
        $video = $this->video();
        $settings = array_replace($video->format_settings, ['minimumVisibleRatio' => 0.7, 'customReviewedFlag' => true]);
        app(InventoryManager::class)->updatePlacement($video, ['format_settings' => $settings], $this->admin, false);
        $this->post(route('admin.demand.quick.store'), $this->payload([
            'placement_mode' => 'existing', 'placement_id' => $video->id, 'video_master_size' => '400x300',
        ]))->assertSessionHasNoErrors();
        $this->assertSame($video->id, $this->video()->id);
        $this->assertSame(0.7, data_get($this->video()->format_settings, 'minimumVisibleRatio'));
        $this->assertTrue(data_get($this->video()->format_settings, 'customReviewedFlag'));
        $this->assertSame('400x300', data_get($this->video()->format_settings, 'videoMasterSize'));
    }

    public function test_tag_only_edit_keeps_custom_mappings_even_with_a_saved_master(): void
    {
        $this->post(route('admin.demand.quick.store'), $this->payload(['video_master_size' => '640x480']))->assertSessionHasNoErrors();
        $video = $this->video();
        app(InventoryManager::class)->updatePlacement($video, ['sizes' => [
            ['size_type' => 'FIXED', 'width' => 640, 'height' => 480, 'device' => 'ALL'],
            ['size_type' => 'FIXED', 'width' => 300, 'height' => 225, 'device' => 'MOBILE', 'min_viewport_width' => 0, 'max_viewport_width' => 600],
            ['size_type' => 'FIXED', 'width' => 640, 'height' => 480, 'device' => 'DESKTOP', 'min_viewport_width' => 601],
        ]], $this->admin, false);
        $before = $video->fresh()->sizes->toArray();
        foreach (['existing', 'new'] as $mode) {
            $this->post(route('admin.demand.quick.store'), $this->payload([
                'placement_mode' => $mode, 'placement_id' => $mode === 'existing' ? $video->id : null,
                'video_master_size' => '', 'tag' => 'https://vast.vendor.net/tag?slot=retagged',
            ]))->assertSessionHasNoErrors();
            $this->assertSame($before, $video->fresh()->sizes->toArray());
            $this->assertSame('640x480', data_get($video->fresh()->format_settings, 'videoMasterSize'));
        }
    }

    public function test_invalid_size_and_non_video_selection_do_not_publish_or_create_demand(): void
    {
        $versions = ConfigVersion::withoutGlobalScopes()->count();
        foreach (['999x999', '640x360', '<script>'] as $invalid) {
            $this->post(route('admin.demand.quick.store'), $this->payload(['video_master_size' => $invalid]))->assertSessionHasErrors('video_master_size');
        }
        $this->post(route('admin.demand.quick.store'), $this->payload([
            'placement_preset' => 'responsive_display', 'tag' => '/123/display', 'tag_input_type' => 'GAM_AD_UNIT_PATH', 'video_master_size' => '300x250',
        ]))->assertSessionHasErrors('video_master_size');
        $this->assertSame($versions, ConfigVersion::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
        $this->assertSame(0, Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->count());
    }

    public function test_gam_video_path_generates_persists_and_reopens_linear_vast_with_selected_size(): void
    {
        $path = '/123,456/site.net/Nested_Video';
        $this->post(route('admin.demand.quick.store'), $this->payload([
            'tag_input_type' => 'GAM_AD_UNIT_PATH', 'tag' => $path, 'video_master_size' => '336x280',
        ]))->assertSessionHasNoErrors();
        $widget = DemandWidget::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('GAM_VIDEO_PATH', data_get($widget->configuration, 'input_kind'));
        $this->assertSame($path, data_get($widget->configuration, 'gam_ad_unit_path'));
        $this->assertStringStartsWith('https://pubads.g.doubleclick.net/gampad/ads?', $widget->direct_tag_template);
        parse_str(parse_url($widget->direct_tag_template, PHP_URL_QUERY), $query);
        $this->assertSame(['iu' => $path, 'env' => 'vp', 'gdfp_req' => '1', 'output' => 'vast', 'ad_type' => 'video', 'vad_type' => 'linear', 'unviewed_position_start' => '1', 'sz' => '336x280'], $query);
        foreach (['nofb', 'npa', 'gdpr', 'gdpr_consent', 'tfcd', 'url', 'description_url', 'correlator', 'vpa', 'vpmute'] as $dynamic) $this->assertArrayNotHasKey($dynamic, $query);
        $attributes = data_get($this->published()->payload, 'directDemand.placements.quick_video_floating.candidates.0.tag.container.attributes');
        $this->assertSame($widget->direct_tag_template, base64_decode($attributes['data-hm-vast-url']));
        $video = $this->video();
        $response = $this->get(route('admin.demand.quick.create', ['site' => $this->site->id, 'placement' => $video->id]))->assertOk();
        $response->assertViewHas('selectedPlacementId', $video->id);
        $response->assertViewHas('quickInputs', fn ($inputs) => $inputs[$video->id] === ['inputType' => 'GAM_AD_UNIT_PATH', 'tag' => $path, 'videoMasterSize' => '336x280']);
        $this->post(route('admin.demand.quick.store'), $this->payload([
            'placement_mode' => 'existing', 'placement_id' => $video->id, 'tag_input_type' => 'GAM_AD_UNIT_PATH', 'tag' => $path,
        ]))->assertSessionHasNoErrors();
        $this->assertSame(1, DemandWidget::withoutGlobalScopes()->count());
        $this->assertSame($widget->direct_tag_template, $widget->fresh()->direct_tag_template);
    }

    public function test_switching_from_generated_gam_path_to_full_vast_clears_path_metadata(): void
    {
        $this->post(route('admin.demand.quick.store'), $this->payload(['tag_input_type' => 'GAM_AD_UNIT_PATH', 'tag' => '/123/video']))->assertSessionHasNoErrors();
        $url = 'https://vast.vendor.net/tag?slot=video&output=vmap&custom=preserved';
        $this->post(route('admin.demand.quick.store'), $this->payload(['tag_input_type' => 'PROVIDER_TAG', 'tag' => $url]))->assertSessionHasNoErrors();
        $widget = DemandWidget::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($url, $widget->direct_tag_template);
        $this->assertSame('VAST_URL', data_get($widget->configuration, 'input_kind'));
        $this->assertNull(data_get($widget->configuration, 'gam_ad_unit_path'));
    }

    public function test_video_edit_hydration_keeps_generated_and_full_vast_inputs_without_exposing_display_tags(): void
    {
        $displayTag = <<<'HTML'
<script async src="https://securepubads.g.doubleclick.net/tag/js/gpt.js"></script>
<div id="private-display-provider-code"></div>
<script>
window.googletag = window.googletag || {cmd: []};
googletag.cmd.push(function() {
  googletag.defineSlot('/123/display', [300, 250], 'private-display-provider-code').addService(googletag.pubads());
  googletag.enableServices();
  googletag.display('private-display-provider-code');
});
</script>
HTML;
        $this->post(route('admin.demand.quick.store'), $this->payload([
            'placement_preset' => 'responsive_display', 'tag' => $displayTag,
        ]))->assertSessionHasNoErrors();
        $this->post(route('admin.demand.quick.store'), $this->payload([
            'tag_input_type' => 'GAM_AD_UNIT_PATH', 'tag' => '/123,456/site/video',
        ]))->assertSessionHasNoErrors();
        $generated = $this->video();
        $url = 'https://vast.vendor.net/tag?slot=full-video&custom=preserved';
        $this->post(route('admin.demand.quick.store'), $this->payload([
            'placement_preset' => 'video_outstream', 'tag' => $url,
        ]))->assertSessionHasNoErrors();
        $fullVast = Placement::withoutGlobalScopes()->where('site_id', $this->site->id)->where('code', 'quick_video_outstream')->firstOrFail();

        foreach ([$generated, $fullVast] as $video) {
            $response = $this->get(route('admin.demand.quick.create', ['site' => $this->site->id, 'placement' => $video->id]))->assertOk();
            $response->assertViewHas('selectedPlacementId', $video->id);
            $response->assertViewHas('quickInputs', fn ($inputs) => count($inputs) === 2
                && $inputs[$generated->id]['inputType'] === 'GAM_AD_UNIT_PATH'
                && $inputs[$generated->id]['tag'] === '/123,456/site/video'
                && $inputs[$fullVast->id]['inputType'] === 'PROVIDER_TAG'
                && $inputs[$fullVast->id]['tag'] === $url);
            $response->assertDontSee('googletag.defineSlot', false);
            $response->assertDontSee('private-display-provider-code', false);
        }
    }

    public function test_malformed_or_mismatched_video_inputs_fail_without_saved_changes(): void
    {
        $versions = ConfigVersion::withoutGlobalScopes()->count();
        foreach (['/Network/unit', '/123,456,789/unit', '/123/unit?nofb=1', '/123//unit', '/123/unit#x', 'https://vast.vendor.net/tag', '<script>alert(1)</script>'] as $invalid) {
            $this->post(route('admin.demand.quick.store'), $this->payload(['tag_input_type' => 'GAM_AD_UNIT_PATH', 'tag' => $invalid]))->assertSessionHasErrors('tag');
        }
        $this->post(route('admin.demand.quick.store'), $this->payload(['tag_input_type' => 'PROVIDER_TAG', 'tag' => '/123/video']))->assertSessionHasErrors('tag');
        $this->assertSame($versions, ConfigVersion::withoutGlobalScopes()->count());
        $this->assertSame(0, DemandWidget::withoutGlobalScopes()->count());
    }

    public function test_video_selectors_offer_exactly_six_sizes_and_retain_error_input(): void
    {
        $response = $this->withSession(['_old_input' => [
            'placement_preset' => 'video_floating', 'video_master_size' => '400x300', 'tag_input_type' => 'GAM_AD_UNIT_PATH', 'tag' => '/123/video',
        ]])->get(route('admin.demand.quick.create'))->assertOk();
        $html = $response->getContent();
        $this->assertSame(1, preg_match('/<select[^>]*name="video_master_size"[^>]*>(.*?)<\/select>/s', $html, $matches));
        $this->assertSame(7, substr_count($matches[1], '<option '));
        foreach (array_keys(VideoMasterSize::choices()) as $master) $this->assertStringContainsString('value="'.$master.'"', $matches[1]);
        $this->assertMatchesRegularExpression('/<option value="400x300"[^>]*\sselected/', $matches[1]);
        $this->assertStringContainsString("['DISPLAY', 'STICKY', 'VIDEO', 'REWARDED'].includes(type)", $html);
        $this->assertStringNotContainsString("inputType.disabled = blocked || video", $html);
        $this->assertStringContainsString('complete linear VAST URL', $html);
        $this->assertStringContainsString('actual rendered size', $html);
    }

    public static function masterSizes(): array
    {
        return array_map(fn ($master, $dimensions) => [$master, $dimensions], array_keys(VideoMasterSize::choices()), array_values(VideoMasterSize::choices()));
    }

    private function video(): Placement
    {
        return Placement::withoutGlobalScopes()->with('sizes')->where('site_id', $this->site->id)->where('code', 'quick_video_floating')->firstOrFail();
    }

    private function published(): ConfigVersion
    {
        return ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->where('environment', ConfigEnvironment::Production->value)->orderByDesc('version')->firstOrFail();
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'site_id' => $this->site->id, 'placement_mode' => 'new', 'placement_preset' => 'video_floating',
            'tag_input_type' => 'PROVIDER_TAG', 'tag' => 'https://vast.vendor.net/tag?slot=video',
        ], $overrides);
    }
}
