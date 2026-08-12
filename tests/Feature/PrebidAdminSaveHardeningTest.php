<?php

namespace Tests\Feature;

use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\PrebidConfiguredMode;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Models\AuditLog;
use App\Models\PrebidBuild;
use App\Services\Operations\PlatformControlService;
use App\Services\Prebid\PrebidManager;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PrebidAdminSaveHardeningTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $horus;
    private $admin;
    private $publisherUser;
    private $publisher;
    private $gam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, PrebidSeeder::class]);

        $this->horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($this->horus, RoleName::SuperAdmin);
        $this->gam = $this->makeGamConnection($this->horus, $this->admin, [
            'type' => GamConnectionType::HorusGam,
            'driver' => 'MOCK',
            'network_code' => '1357911',
            'is_primary' => true,
            'is_enabled' => true,
            'configuration' => ['root_ad_unit_id' => '1111'],
        ]);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Hardening Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Hardening Publisher']);
    }

    public function test_prebid_enablement_and_configured_mode_changes_are_audited(): void
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Audited Prebid',
            'primary_domain' => 'audited-prebid.example',
            'prebid_enabled' => false,
        ]);
        $site->update(['serving_mode' => ServingMode::HorusGam, 'prebid_enabled' => false]);
        $site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusGam,
            'prebid_enabled' => false,
            'prebid_configured_mode' => PrebidConfiguredMode::Auto,
        ]);

        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.sites.prebid.settings', $site), $this->settingsPayload([
                'enabled' => '1',
                'prebid_configured_mode' => 'GAM_BRIDGE',
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = $site->refresh()->load('servingSettings');
        $this->assertTrue($site->prebid_enabled);
        $this->assertSame(PrebidConfiguredMode::GamBridge, $site->servingSettings->prebid_configured_mode);

        $audit = AuditLog::query()->where('event', 'prebid.site_configuration.updated')->latest('created_at')->firstOrFail();
        $this->assertSame($site->servingSettings->id, $audit->auditable_id);
        $this->assertFalse($audit->old_values['prebid_enabled']);
        $this->assertSame('AUTO', $audit->old_values['prebid_configured_mode']);
        $this->assertTrue($audit->new_values['prebid_enabled']);
        $this->assertSame('GAM_BRIDGE', $audit->new_values['prebid_configured_mode']);
        $this->assertSame($site->id, $audit->metadata['site_id']);
    }

    public function test_explicit_gam_bridge_without_eligible_gam_rejects_the_entire_save(): void
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'No Bridge',
            'primary_domain' => 'no-bridge.example',
            'prebid_enabled' => false,
        ]);
        $site->update(['serving_mode' => ServingMode::HorusDirect, 'prebid_enabled' => false]);
        $site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusDirect,
            'prebid_enabled' => false,
            'prebid_configured_mode' => PrebidConfiguredMode::Standalone,
        ]);
        $beforeSettingsId = $site->servingSettings->id;

        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->from(route('admin.sites.prebid.index', $site))
            ->put(route('admin.sites.prebid.settings', $site), $this->settingsPayload([
                'enabled' => '1',
                'prebid_configured_mode' => 'GAM_BRIDGE',
                'auction_timeout_ms' => 1777,
            ]))
            ->assertRedirect(route('admin.sites.prebid.index', $site))
            ->assertSessionHasErrors('prebid_configured_mode');

        $site = $site->refresh()->load('servingSettings');
        $this->assertFalse($site->prebid_enabled);
        $this->assertSame($beforeSettingsId, $site->servingSettings->id);
        $this->assertSame(PrebidConfiguredMode::Standalone, $site->servingSettings->prebid_configured_mode);
        $this->assertDatabaseMissing('prebid_settings', [
            'site_id' => $site->id,
            'auction_timeout_ms' => 1777,
        ]);
        $this->assertSame(0, AuditLog::query()->where('event', 'prebid.site_configuration.updated')->count());
    }

    public function test_gam_operational_disable_also_rejects_new_explicit_bridge_configuration(): void
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'GAM Paused Bridge',
            'primary_domain' => 'gam-paused-bridge.example',
            'prebid_enabled' => false,
        ]);
        $site->update(['serving_mode' => ServingMode::HorusGam, 'prebid_enabled' => false]);
        $site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusGam,
            'prebid_enabled' => false,
            'prebid_configured_mode' => PrebidConfiguredMode::Auto,
        ]);
        app(PlatformControlService::class)->set('SITE', $site->id, 'GAM', true, 'Hardening bridge configuration test.', $this->admin);

        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.sites.prebid.settings', $site), $this->settingsPayload([
                'enabled' => '1',
                'prebid_configured_mode' => 'GAM_BRIDGE',
            ]))
            ->assertSessionHasErrors('prebid_configured_mode');

        $site = $site->refresh()->load('servingSettings');
        $this->assertFalse($site->prebid_enabled);
        $this->assertSame(PrebidConfiguredMode::Auto, $site->servingSettings->prebid_configured_mode);
    }

    public function test_existing_gam_bridge_remains_manageable_during_operational_gam_pause(): void
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Existing Paused Bridge',
            'primary_domain' => 'existing-paused-bridge.example',
            'prebid_enabled' => true,
        ]);
        $site->update(['serving_mode' => ServingMode::HorusGam, 'prebid_enabled' => true]);
        $site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusGam,
            'prebid_enabled' => true,
            'prebid_configured_mode' => PrebidConfiguredMode::GamBridge,
        ]);
        app(PrebidManager::class)->updateSettings($this->gam, [
            'enabled' => true,
            'auction_timeout_ms' => 1200,
            'price_granularity' => 'medium',
            'currency' => 'USD',
            'bidder_sequence' => 'fixed',
            'consent_behavior' => [],
            'lazy_loading' => ['enabled' => true],
            'refresh_behavior' => ['enabled' => true, 'minimumIntervalSeconds' => 30],
            'bidder_timeout_reporting' => true,
            'gam_fallback' => true,
        ], $this->admin);
        app(PlatformControlService::class)->set('SITE', $site->id, 'GAM', true, 'Temporary GAM outage.', $this->admin);

        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.sites.prebid.settings', $site), $this->settingsPayload([
                'enabled' => '0',
                'prebid_configured_mode' => 'GAM_BRIDGE',
                'auction_timeout_ms' => 1666,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $site = $site->refresh()->load('servingSettings');
        $this->assertFalse($site->prebid_enabled);
        $this->assertSame(PrebidConfiguredMode::GamBridge, $site->servingSettings->prebid_configured_mode);
        $this->assertDatabaseHas('prebid_settings', [
            'gam_connection_id' => $this->gam->id,
            'enabled' => false,
            'auction_timeout_ms' => 1666,
        ]);
    }

    public function test_invalid_consent_json_is_reported_on_the_consent_field(): void
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Consent Validation',
            'primary_domain' => 'consent-validation.example',
            'prebid_enabled' => false,
        ]);
        $site->update(['serving_mode' => ServingMode::HorusDirect, 'prebid_enabled' => false]);
        $site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusDirect,
            'prebid_enabled' => false,
            'prebid_configured_mode' => PrebidConfiguredMode::Standalone,
        ]);

        $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->put(route('admin.sites.prebid.settings', $site), $this->settingsPayload([
                'prebid_configured_mode' => 'STANDALONE',
                'consent_json' => '{invalid',
            ]))
            ->assertSessionHasErrors('consent_json')
            ->assertSessionDoesntHaveErrors('public_parameters_json');
    }

    private function settingsPayload(array $overrides = []): array
    {
        $build = PrebidBuild::query()->where('is_active', true)->latest('built_at')->firstOrFail();

        return array_replace([
            'prebid_build_id' => $build->id,
            'enabled' => '0',
            'prebid_configured_mode' => 'AUTO',
            'auction_timeout_ms' => 1200,
            'price_granularity' => 'medium',
            'currency' => 'USD',
            'bidder_sequence' => 'fixed',
            'consent_json' => '{}',
            'lazy_loading' => '1',
            'refresh_enabled' => '1',
            'refresh_minimum_seconds' => 30,
            'bidder_timeout_reporting' => '1',
            'gam_fallback' => '1',
        ], $overrides);
    }
}
