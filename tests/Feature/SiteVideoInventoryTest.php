<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\ConfigVersion;
use App\Models\GlobalSetting;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Inventory\VideoAdFormat;
use App\Services\Security\PublicProviderOriginValidator;
use App\Services\Settings\GlobalSettingsService;
use Database\Seeders\AdFormatSeeder;
use Database\Seeders\DemandNetworkSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class SiteVideoInventoryTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private User $admin;
    private User $publisherUser;
    private Site $site;
    private Site $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $this->admin = $this->makeUser($horus, RoleName::AdOpsAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher);
        $this->publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($this->publisherUser);
        $this->site = $this->makeSiteFor($publisher, $this->publisherUser);
        $this->other = $this->makeSiteFor($publisher, $this->publisherUser);
    }

    public function test_existing_and_future_sites_default_to_two_and_ignore_retired_global_values(): void
    {
        GlobalSetting::query()->create(['key' => 'video_player.inventory_type', 'value' => 'instream']);
        Cache::put(GlobalSettingsService::CACHE_KEY, ['video_player.inventory_type' => 'instream'], 300);
        config(['horus.video_inventory_type' => 'instream']);
        app(GlobalSettingsService::class)->applyRuntimeOverrides();

        $future = $this->makeSiteFor($this->site->publisher, $this->publisherUser);
        foreach ([$this->site, $this->other, $future] as $site) {
            $this->assertNull($site->siteConfig->video_inventory_type);
            $this->assertSame('accompanying', VideoAdFormat::inventoryType($site));
        }
        $this->assertSame('accompanying', VideoAdFormat::inventoryType());
        $this->assertSame('accompanying', VideoAdFormat::inventoryType(new Site));
        $this->assertDatabaseHas('global_settings', ['key' => 'video_player.inventory_type']);
        $this->adminSession()->put(route('admin.settings.update', ['key' => 'video_player.inventory_type']), ['value' => 'instream'])
            ->assertSessionHasErrors('key');
    }

    public function test_admin_can_switch_each_site_independently_without_changing_global_audio(): void
    {
        config(['horus.video_autoplay_audio' => 'prefer_audible']);
        foreach (['instream', 'accompanying', 'instream'] as $type) {
            $this->adminSession()->put($this->route($this->site), ['video_inventory_type' => $type])
                ->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame($type, VideoAdFormat::inventoryType($this->site->fresh()));
            $this->assertSame('accompanying', VideoAdFormat::inventoryType($this->other->fresh()));
            $this->assertTrue(VideoAdFormat::prefersAudibleAutoplay());
        }
        $this->adminSession()->put($this->route($this->other), ['video_inventory_type' => 'instream'])->assertSessionHasNoErrors();
        $this->adminSession()->put($this->route($this->site), ['video_inventory_type' => 'accompanying'])->assertSessionHasNoErrors();
        $this->assertSame('instream', VideoAdFormat::inventoryType($this->other->fresh()));
        $this->assertSame('accompanying', VideoAdFormat::inventoryType($this->site->fresh()));
    }

    public function test_active_save_is_audited_and_queues_only_the_target_site_once(): void
    {
        foreach ([$this->site, $this->other] as $site) $site->update(['status' => SiteStatus::Active]);
        $this->adminSession()->put($this->route($this->site), ['video_inventory_type' => 'instream'])
            ->assertRedirect()->assertSessionHas('status', 'Video ad type saved for this website. Production configuration v1 was queued automatically.');

        $version = ConfigVersion::withoutGlobalScopes()->sole();
        $this->assertSame($this->site->id, $version->site_id);
        $this->assertSame('PRODUCTION', $version->environment->value);
        $this->assertDatabaseHas('static_delivery_items', ['config_version_id' => $version->id, 'status' => 'PENDING']);
        $audit = AuditLog::query()->where('event', 'site.config.video_inventory.updated')->sole();
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame($this->site->organization_id, $audit->organization_id);
        $this->assertSame($this->site->siteConfig->id, $audit->auditable_id);
        $this->assertSame(['video_inventory_type' => null], $audit->old_values);
        $this->assertSame(['video_inventory_type' => 'instream'], $audit->new_values);

        $this->adminSession()->put($this->route($this->site), ['video_inventory_type' => 'instream'])
            ->assertSessionHas('status', 'Video ad type is already saved for this website.');
        $this->assertDatabaseCount('config_versions', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'site.config.video_inventory.updated')->count());
        $this->assertSame('accompanying', VideoAdFormat::inventoryType($this->other->fresh()));
    }

    public function test_inactive_save_waits_for_activation_and_does_not_enable_inventory(): void
    {
        $before = $this->site->only(['status', 'serving_mode', 'native_demand_enabled']);
        $this->adminSession()->put($this->route($this->site), ['video_inventory_type' => 'instream'])
            ->assertSessionHas('status', 'Video ad type saved for this website. It will publish automatically when the website is activated.');

        $this->assertSame($before, $this->site->fresh()->only(array_keys($before)));
        $this->assertSame(0, $this->site->placements()->count());
        $this->assertDatabaseCount('config_versions', 0);
        $this->assertDatabaseCount('static_delivery_items', 0);
        $this->assertSame('instream', VideoAdFormat::inventoryType($this->site->fresh()));
        $this->site->update(['status' => SiteStatus::Active]);
        $this->assertNotNull(app(SiteConfigPublisher::class)->publishActiveProduction($this->site, $this->admin));
        $this->assertSame('instream', VideoAdFormat::inventoryType($this->site->fresh()));
    }

    public function test_publisher_cannot_view_or_save_the_admin_control(): void
    {
        $this->actingAs($this->publisherUser)->put($this->route($this->site), ['video_inventory_type' => 'instream'])->assertForbidden();
        $this->actingAs($this->publisherUser)->get(route('publisher.sites.show', $this->site))
            ->assertOk()->assertDontSee('name="video_inventory_type"', false);
        $this->assertNull($this->site->fresh()->siteConfig->video_inventory_type);
        $this->assertDatabaseCount('config_versions', 0);
    }

    public function test_inventory_management_alone_cannot_change_video_classification(): void
    {
        $permission = Permission::query()->where('name', 'video_player.manage')->firstOrFail();
        $this->admin->roles()->firstOrFail()->permissions()->detach($permission->id);
        $this->admin->unsetRelation('roles');
        $this->assertTrue($this->admin->hasPermission('inventory.manage'));
        $this->assertFalse($this->admin->hasPermission('video_player.manage'));
        $this->adminSession()->put($this->route($this->site), ['video_inventory_type' => 'instream'])->assertForbidden();
        // The general configuration endpoint must not become an alternate write path.
        $this->adminSession()->put(route('admin.sites.config.update', $this->site), [
            'cache_ttl_seconds' => 60, 'video_inventory_type' => 'instream',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->adminSession()->get(route('admin.sites.inventory.index', $this->site))
            ->assertOk()->assertDontSee('name="video_inventory_type"', false);
        $this->assertNull($this->site->fresh()->siteConfig->video_inventory_type);
    }

    public function test_invalid_values_fail_without_mutation_audit_or_publication(): void
    {
        $this->site->update(['status' => SiteStatus::Active]);
        foreach ([null, '', 'invented', '1', '2', ['instream']] as $invalid) {
            $this->adminSession()->put($this->route($this->site), ['video_inventory_type' => $invalid])
                ->assertSessionHasErrors('video_inventory_type');
        }
        $this->assertNull($this->site->fresh()->siteConfig->video_inventory_type);
        $this->assertDatabaseCount('config_versions', 0);
        $this->assertSame(0, AuditLog::query()->where('event', 'site.config.video_inventory.updated')->count());
    }

    public function test_dedicated_save_leaves_unrelated_configuration_unchanged(): void
    {
        $this->site->siteConfig()->update(['debug_enabled' => true, 'cache_ttl_seconds' => 120, 'gpt_settings' => ['sentinel' => 'keep']]);
        $this->adminSession()->put($this->route($this->site), [
            'video_inventory_type' => 'instream', 'cache_ttl_seconds' => 0,
            'debug_enabled' => false, 'video_autoplay_audio' => 'prefer_audible',
        ])->assertSessionHasNoErrors();
        $config = $this->site->fresh()->siteConfig;
        $this->assertTrue($config->debug_enabled);
        $this->assertSame(120, $config->cache_ttl_seconds);
        $this->assertSame(['sentinel' => 'keep'], $config->gpt_settings);
        $this->assertDatabaseMissing('global_settings', ['key' => 'video_player.autoplay_audio']);
    }

    public function test_admin_ui_exposes_two_named_choices_and_preserves_saved_selection(): void
    {
        $this->adminSession()->get(route('admin.sites.inventory.index', $this->site))
            ->assertOk()->assertSee('Video ad type /')->assertSee('نوع إعلان الفيديو')
            ->assertSee('Accompanying content (2)')->assertSee('Instream (1)')
            ->assertSee('aria-describedby="video-inventory-help"', false)
            ->assertSee('value="accompanying" selected', false);
        $this->adminSession()->put($this->route($this->site), ['video_inventory_type' => 'instream'])->assertSessionHasNoErrors();
        $this->adminSession()->get(route('admin.sites.inventory.index', $this->site))
            ->assertOk()->assertSee('value="instream" selected', false);
        $this->adminSession()->get(route('admin.sites.show', $this->site))
            ->assertOk()->assertSee('name="video_inventory_type"', false);
    }

    public function test_published_vast_recipe_uses_site_type_and_independent_global_audio(): void
    {
        $this->seed([AdFormatSeeder::class, DemandNetworkSeeder::class]);
        $this->app->instance(PublicProviderOriginValidator::class, new class extends PublicProviderOriginValidator
        {
            protected function resolveAddresses(string $host): array { return ['93.184.216.34']; }
        });
        config(['horus.video_content_url' => 'https://cdn.horusmedia.net/content.mp4', 'horus.video_autoplay_audio' => 'prefer_audible']);
        $vast = 'https://vast.vendor.net/tag?placement=floating&plcmt=2';
        foreach ([$this->site, $this->other] as $site) {
            $site->update(['status' => SiteStatus::Active, 'serving_mode' => ServingMode::HorusDirect]);
            $site->servingSettings()->update(['serving_mode' => ServingMode::HorusDirect]);
            $this->adminSession()->post(route('admin.demand.quick.store'), [
                'site_id' => $site->id, 'placement_mode' => 'new', 'placement_preset' => 'video_floating', 'tag' => $vast,
            ])->assertRedirect()->assertSessionHasNoErrors();
        }
        foreach (['instream', 'accompanying'] as $type) {
            $otherVersions = $this->other->configVersions()->count();
            $this->adminSession()->put($this->route($this->site), ['video_inventory_type' => $type])->assertSessionHasNoErrors();
            $version = $this->site->configVersions()->latest('version')->firstOrFail();
            $attributes = data_get($version->payload, 'directDemand.placements.quick_video_floating.candidates.0.tag.container.attributes');
            $this->assertIsArray($attributes);
            $this->assertSame($type, $attributes['data-hm-video-content-mode']);
            $this->assertSame('0', $attributes['data-hm-video-muted']);
            $this->assertSame('pre,mid,post', $attributes['data-hm-video-breaks']);
            $this->assertSame('60', $attributes['data-hm-video-mid-roll-interval-seconds']);
            $this->assertSame($vast, base64_decode($attributes['data-hm-vast-url'], true));
            $otherVersion = $this->other->configVersions()->latest('version')->firstOrFail();
            $this->assertSame('accompanying', data_get($otherVersion->payload, 'directDemand.placements.quick_video_floating.candidates.0.tag.container.attributes.data-hm-video-content-mode'));
            $this->assertSame($otherVersions, $this->other->configVersions()->count());
        }
    }

    public function test_global_interval_change_republishes_active_sites_without_changing_their_inventory_types(): void
    {
        $this->site->siteConfig()->update(['video_inventory_type' => 'instream']);
        foreach ([$this->site, $this->other] as $site) $site->update(['status' => SiteStatus::Active]);
        $this->adminSession()->put(route('admin.settings.update', ['key' => 'video_player.mid_roll_interval_seconds']), ['value' => 120])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(120, config('horus.video_mid_roll_interval_seconds'));
        $this->assertSame('instream', VideoAdFormat::inventoryType($this->site->fresh()));
        $this->assertSame('accompanying', VideoAdFormat::inventoryType($this->other->fresh()));
        $this->assertSame(1, $this->site->configVersions()->count());
        $this->assertSame(1, $this->other->configVersions()->count());
    }

    public function test_real_routes_emit_browser_review_fixtures(): void
    {
        $inventory = route('admin.sites.inventory.index', $this->site);
        $this->fixture('admin-default', $this->adminSession()->get($inventory)->assertOk());
        $this->adminSession()->from($inventory)->put($this->route($this->site), ['video_inventory_type' => 'instream'])
            ->assertRedirect($inventory)->assertSessionHasNoErrors();
        $this->fixture('admin-saved', $this->get($inventory)->assertOk()->assertSee('Video ad type saved for this website.'));
        $this->adminSession()->from($inventory)->put($this->route($this->site), ['video_inventory_type' => 'invalid'])
            ->assertRedirect($inventory)->assertSessionHasErrors('video_inventory_type');
        $this->fixture('admin-error', $this->get($inventory)->assertOk()->assertSee('aria-invalid="true"', false));
        $this->assertSame('instream', VideoAdFormat::inventoryType($this->site->fresh()));
        session()->forget(['errors', '_old_input']);
        $this->fixture('other-site', $this->get(route('admin.sites.inventory.index', $this->other))->assertOk());
        $this->fixture('admin-overview', $this->get(route('admin.sites.show', $this->site))->assertOk());
        $this->fixture('publisher', $this->actingAs($this->publisherUser)->get(route('publisher.sites.show', $this->site))
            ->assertOk()->assertDontSee('name="video_inventory_type"', false));
    }

    private function fixture(string $name, \Illuminate\Testing\TestResponse $response): void
    {
        if (getenv('HORUS_UI_FIXTURES') !== '1') return;
        $directory = storage_path('framework/testing/site-video-inventory');
        if (! is_dir($directory)) mkdir($directory, 0755, true);
        $html = str_replace('</head>', '<link rel="stylesheet" href="/fixture.css"></head>', $response->getContent());
        $html = str_replace('</body>', '<script type="module" src="/fixture.js"></script></body>', $html);
        file_put_contents($directory.'/'.$name.'.html', $html);
    }

    private function route(Site $site): string
    {
        return route('admin.sites.config.video-inventory', $site);
    }

    private function adminSession(): static
    {
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }
}
