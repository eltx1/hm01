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


    public function test_current_and_future_sites_use_fixed_instream_and_audible_defaults(): void
    {
        GlobalSetting::query()->create(['key' => 'video_player.autoplay_audio', 'value' => 'muted']);
        Cache::put(GlobalSettingsService::CACHE_KEY, ['video_player.autoplay_audio' => 'muted'], 300);
        app(GlobalSettingsService::class)->applyRuntimeOverrides();
        foreach ([null, 'accompanying', 'instream'] as $oldValue) {
            $this->site->siteConfig()->update(['video_inventory_type' => $oldValue]);
            $this->assertSame('instream', VideoAdFormat::inventoryType($this->site->fresh()));
            $this->assertTrue(VideoAdFormat::prefersAudibleAutoplay());
        }
        $future = $this->makeSiteFor($this->site->publisher, $this->publisherUser);
        $this->assertSame('instream', VideoAdFormat::inventoryType($future));
        $this->assertSame('instream', VideoAdFormat::inventoryType());
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.sites.config.video-inventory'));
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put('/admin/sites/'.$this->site->id.'/configuration/video-inventory', ['video_inventory_type' => 'accompanying'])->assertNotFound();
    }

    public function test_published_recipe_applies_to_existing_sites_without_publisher_code_changes(): void
    {
        $this->seed([AdFormatSeeder::class, DemandNetworkSeeder::class]);
        $this->app->instance(PublicProviderOriginValidator::class, new class extends PublicProviderOriginValidator {
            protected function resolveAddresses(string $host): array { return ['93.184.216.34']; }
        });
        config(['horus.video_content_url' => 'https://cdn.horusmedia.net/content.mp4']);
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        foreach ([$this->site, $this->other] as $site) {
            $site->siteConfig()->update(['video_inventory_type' => 'accompanying']);
            $site->update(['status' => SiteStatus::Active, 'serving_mode' => ServingMode::HorusDirect]);
            $site->servingSettings()->update(['serving_mode' => ServingMode::HorusDirect]);
            $this->post(route('admin.demand.quick.store'), [
                'site_id' => $site->id, 'placement_mode' => 'new', 'placement_preset' => 'video_floating',
                'tag' => 'https://vast.vendor.net/tag?placement=floating',
            ])->assertRedirect()->assertSessionHasNoErrors();
            $version = $site->configVersions()->latest('version')->firstOrFail();
            $a = data_get($version->payload, 'directDemand.placements.quick_video_floating.candidates.0.tag.container.attributes');
            $this->assertSame('instream', $a['data-hm-video-content-mode']);
            $this->assertSame('1', $a['data-hm-video-fixed-instream']);
            $this->assertSame('0', $a['data-hm-video-muted']);
            $this->assertSame('1', $a['data-hm-video-autoplay']);
            $this->assertSame('interval', $a['data-hm-video-break-schedule']);
            $this->assertSame('5', $a['data-hm-video-mid-roll-interval-seconds']);
        }
    }

    public function test_real_routes_no_longer_offer_the_retired_inventory_selector(): void
    {
        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
        foreach (['admin-default' => route('admin.sites.inventory.index', $this->site),
            'admin-overview' => route('admin.sites.show', $this->site)] as $name => $route) {
            $this->fixture($name, $this->get($route)->assertOk()->assertDontSee('name="video_inventory_type"', false));
        }
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
}
