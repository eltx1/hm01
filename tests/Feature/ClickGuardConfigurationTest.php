<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Models\ConfigVersion;
use App\Models\SiteConfig;
use App\Services\Inventory\SiteConfigurationBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class ClickGuardConfigurationTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        config(['static-delivery.normal_batch_interval_minutes' => 0]);
    }

    public function test_default_public_configuration_is_disabled_and_safe(): void
    {
        [$site] = $this->makeSiteAndAdmin();

        $payload = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1);

        $this->assertSame([
            'enabled' => false,
            'maxClicks' => 3,
            'windowHours' => 6,
            'blockHours' => 12,
        ], $payload['clickGuard']);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('click_guard_settings', $encoded);
        $this->assertStringNotContainsString('organization_id', $encoded);
    }

    public function test_delivery_settings_persist_and_publish_normalized_click_guard_configuration_for_active_site(): void
    {
        [$site, $admin] = $this->makeSiteAndAdmin();
        $site->update(['status' => SiteStatus::Active]);

        $response = $this->actingAs($admin)->put(route('admin.sites.config.update', $site), $this->validSettings([
            'click_guard_enabled' => '1',
            'click_guard_max_clicks' => '5',
            'click_guard_window_hours' => '8',
            'click_guard_block_hours' => '24',
        ]));

        $response->assertRedirect();
        $config = SiteConfig::withoutGlobalScopes()->where('site_id', $site->id)->firstOrFail();
        $this->assertEquals(['enabled' => true, 'maxClicks' => 5, 'windowHours' => 8, 'blockHours' => 24], $config->click_guard_settings);
        $version = ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->latest('version')->firstOrFail();
        $this->assertEquals(['enabled' => true, 'maxClicks' => 5, 'windowHours' => 8, 'blockHours' => 24], $version->payload['clickGuard']);
        $this->assertDatabaseHas('static_delivery_items', ['config_version_id' => $version->id]);
    }

    public function test_click_guard_validation_rejects_out_of_bounds_values(): void
    {
        [$site, $admin] = $this->makeSiteAndAdmin();

        $response = $this->from(route('admin.sites.inventory.index', $site))->actingAs($admin)->put(
            route('admin.sites.config.update', $site),
            $this->validSettings([
                'click_guard_max_clicks' => 51,
                'click_guard_window_hours' => 169,
                'click_guard_block_hours' => 721,
            ]),
        );

        $response->assertRedirect(route('admin.sites.inventory.index', $site));
        $response->assertSessionHasErrors(['click_guard_max_clicks', 'click_guard_window_hours', 'click_guard_block_hours']);
    }

    public function test_inactive_site_saves_click_guard_without_publishing_production(): void
    {
        [$site, $admin] = $this->makeSiteAndAdmin();
        $before = ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->count();

        $this->actingAs($admin)->put(route('admin.sites.config.update', $site), $this->validSettings([
            'click_guard_enabled' => '1',
        ]))->assertRedirect();

        $config = SiteConfig::withoutGlobalScopes()->where('site_id', $site->id)->firstOrFail();
        $this->assertTrue($config->click_guard_settings['enabled']);
        $this->assertSame($before, ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->count());
    }

    public function test_legacy_settings_request_keeps_existing_click_guard_values_when_fields_are_omitted(): void
    {
        [$site, $admin] = $this->makeSiteAndAdmin();
        SiteConfig::withoutGlobalScopes()->updateOrCreate(
            ['site_id' => $site->id],
            ['organization_id' => $site->organization_id, 'cache_ttl_seconds' => 60, 'click_guard_settings' => [
                'enabled' => true, 'maxClicks' => 4, 'windowHours' => 10, 'blockHours' => 18,
            ]],
        );

        $legacy = $this->validSettings();
        unset($legacy['click_guard_enabled'], $legacy['click_guard_max_clicks'], $legacy['click_guard_window_hours'], $legacy['click_guard_block_hours']);
        $this->actingAs($admin)->put(route('admin.sites.config.update', $site), $legacy)->assertRedirect();

        $this->assertEquals(
            ['enabled' => true, 'maxClicks' => 4, 'windowHours' => 10, 'blockHours' => 18],
            SiteConfig::withoutGlobalScopes()->where('site_id', $site->id)->firstOrFail()->click_guard_settings,
        );
    }

    public function test_cross_tenant_publisher_cannot_discover_or_change_click_guard_settings(): void
    {
        [$site] = $this->makeSiteAndAdmin();
        $otherOrganization = $this->makeOrganization(OrganizationType::Publisher);
        $otherUser = $this->makeUser($otherOrganization, RoleName::PublisherAdmin);

        $this->actingAs($otherUser)->put(route('admin.sites.config.update', $site), $this->validSettings([
            'click_guard_enabled' => '1',
        ]))->assertNotFound();
    }

    private function validSettings(array $overrides = []): array
    {
        return array_merge([
            'cache_ttl_seconds' => 60,
            'debug_enabled' => '0',
            'house_ad_testing' => '0',
            'single_request_mode' => '1',
            'click_guard_enabled' => '0',
            'click_guard_max_clicks' => 3,
            'click_guard_window_hours' => 6,
            'click_guard_block_hours' => 12,
            'privacy_settings_json' => '{}',
            'gpt_settings_json' => '{}',
            'supply_chain_settings_json' => '{}',
            'observability_settings_json' => '{}',
        ], $overrides);
    }

    private function makeSiteAndAdmin(): array
    {
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher);
        $publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $site = $this->makeSiteFor($this->makePublisherFor($publisherUser), $publisherUser, [
            'primary_domain' => 'click-guard.example',
        ]);

        return [$site, $admin];
    }
}
