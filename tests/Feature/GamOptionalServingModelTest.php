<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\GamConnectionType;
use App\Enums\MonetizationStatus;
use App\Enums\OrganizationType;
use App\Enums\PrebidDeliveryMode;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\DemandNetwork;
use App\Models\PrebidBidder;
use App\Models\PrebidSetting;
use App\Models\Site;
use App\Services\Demand\DemandAccountService;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Monetization\SiteMonetizationReadinessService;
use App\Services\Operations\PlatformControlService;
use App\Services\Prebid\PrebidManager;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class GamOptionalServingModelTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $horus;
    private $gam;
    private $publisherUser;
    private $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, PrebidSeeder::class, DemandNetworkSeeder::class]);

        $this->horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($this->horus, RoleName::SuperAdmin);
        $this->gam = $this->makeGamConnection($this->horus, $this->admin, [
            'type' => GamConnectionType::HorusGam,
            'driver' => 'MOCK',
            'network_code' => '123456789',
            'is_primary' => true,
            'configuration' => ['root_ad_unit_id' => '1111'],
        ]);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Publisher']);
    }

    public function test_existing_horus_gam_with_and_without_prebid_remains_backward_compatible(): void
    {
        $site = $this->site(ServingMode::HorusGam);
        $placement = $this->placement($site, 'gam_top');

        $withoutPrebid = $this->config($site, 1);
        $this->assertSame(3, $withoutPrebid['schemaVersion']);
        $this->assertSame('HORUS_GAM', $withoutPrebid['servingMode']);
        $this->assertSame('123456789', $withoutPrebid['gamNetworkCode']);
        $this->assertTrue($withoutPrebid['engines']['gam']['enabled']);
        $this->assertFalse($withoutPrebid['engines']['prebid']['enabled']);
        $this->assertFalse($withoutPrebid['prebidEnabled']);
        $this->assertSame('GAM', data_get($withoutPrebid, 'placements.0.renderer'));
        $this->assertSame('/123456789/gam_top', data_get($withoutPrebid, 'placements.0.adUnitPath'));

        $site->update(['prebid_enabled' => true]);
        $site->servingSettings()->update(['prebid_enabled' => true]);
        $manager = app(PrebidManager::class);
        $manager->updateSettings($this->gam, [
            'enabled' => true,
            'auction_timeout_ms' => 900,
            'price_granularity' => 'medium',
            'currency' => 'USD',
            'bidder_sequence' => 'fixed',
            'gam_fallback' => true,
        ], $this->admin);
        $this->mapPrebid($site, $placement);

        $withPrebid = $this->config($site->refresh(), 2);
        $this->assertTrue($withPrebid['engines']['gam']['enabled']);
        $this->assertTrue($withPrebid['engines']['prebid']['enabled']);
        $this->assertSame(PrebidDeliveryMode::GamBridge->value, $withPrebid['engines']['prebid']['deliveryMode']);
        $this->assertTrue($withPrebid['prebidEnabled']);
        $this->assertSame(PrebidDeliveryMode::GamBridge->value, $withPrebid['prebid']['deliveryMode']);
        $this->assertSame('GAM', data_get($withPrebid, 'placements.0.renderer'));
    }

    public function test_horus_direct_standalone_prebid_needs_no_gam_or_fake_network_code(): void
    {
        $site = $this->site(ServingMode::HorusDirect, prebid: true);
        $placement = $this->placement($site, 'standalone_top');
        $this->mapPrebid($site, $placement);

        $payload = $this->config($site->refresh(), 3);

        $this->assertNull($payload['gamNetworkCode']);
        $this->assertFalse($payload['engines']['gam']['enabled']);
        $this->assertSame('NOT_REQUIRED_BY_SERVING_MODE', $payload['engines']['gam']['reason']);
        $this->assertTrue($payload['engines']['prebid']['enabled']);
        $this->assertSame(PrebidDeliveryMode::Standalone->value, $payload['engines']['prebid']['deliveryMode']);
        $this->assertFalse($payload['prebidEnabled']);
        $this->assertSame(PrebidDeliveryMode::Standalone->value, $payload['prebid']['deliveryMode']);
        $this->assertSame('PREBID_STANDALONE', data_get($payload, 'placements.0.renderer'));
        $this->assertTrue(data_get($payload, 'placements.0.enabled'));
        $this->assertNull(data_get($payload, 'placements.0.adUnitPath'));
        $this->assertSame(1, PrebidSetting::withoutGlobalScopes()
            ->where('scope', PrebidSetting::SCOPE_SITE_STANDALONE)
            ->where('site_id', $site->id)
            ->whereNull('gam_connection_id')
            ->count());
        $this->assertSame(0, PrebidSetting::withoutGlobalScopes()
            ->where('scope', PrebidSetting::SCOPE_GAM_CONNECTION)
            ->whereNotNull('gam_connection_id')
            ->count());

        $readiness = app(SiteMonetizationReadinessService::class)->admin($site->refresh());
        $display = collect($readiness['modules'])->firstWhere('key', 'display');
        $prebid = collect($readiness['modules'])->firstWhere('key', 'prebid');
        $this->assertSame(MonetizationStatus::NotConfigured->value, $display['status']);
        $this->assertSame('OPTIONAL', $display['dependency']);
        $this->assertSame(MonetizationStatus::Active->value, $prebid['status']);
        $this->assertSame(PrebidDeliveryMode::Standalone->value, data_get($prebid, 'diagnostics.resolved_mode'));
    }

    public function test_horus_direct_direct_js_and_standalone_prebid_can_use_independent_placements(): void
    {
        $site = $this->site(ServingMode::HorusDirect, prebid: true, directJs: true);
        $prebidPlacement = $this->placement($site, 'prebid_slot');
        $directPlacement = $this->placement($site, 'direct_slot');
        $this->mapPrebid($site, $prebidPlacement);
        $this->mapDirectJs($site, $directPlacement);

        $payload = $this->config($site->refresh(), 4);
        $placements = collect($payload['placements'])->keyBy('code');

        $this->assertNull($payload['gamNetworkCode']);
        $this->assertFalse($payload['engines']['gam']['enabled']);
        $this->assertTrue($payload['engines']['prebid']['enabled']);
        $this->assertTrue($payload['engines']['directJs']['enabled']);
        $this->assertSame('PREBID_STANDALONE', $placements['prebid_slot']['renderer']);
        $this->assertTrue($placements['prebid_slot']['enabled']);
        $this->assertSame('DIRECT_JS', $placements['direct_slot']['renderer']);
        $this->assertTrue($placements['direct_slot']['enabled']);
        $this->assertFalse($placements['prebid_slot']['rendererConflict']);
        $this->assertFalse($placements['direct_slot']['rendererConflict']);

        $readiness = app(SiteMonetizationReadinessService::class)->admin($site->refresh());
        $native = collect($readiness['modules'])->firstWhere('key', 'native');
        $this->assertSame(MonetizationStatus::Active->value, $native['status']);
        $this->assertGreaterThan(0, data_get($native, 'diagnostics.eligible_direct_placements'));
    }

    public function test_same_horus_direct_placement_never_double_renders_prebid_and_direct_js(): void
    {
        $site = $this->site(ServingMode::HorusDirect, prebid: true, directJs: true);
        $placement = $this->placement($site, 'conflict_slot');
        $this->mapPrebid($site, $placement);
        $this->mapDirectJs($site, $placement);

        $payload = $this->config($site->refresh(), 5);

        $this->assertSame('CONFLICT', data_get($payload, 'placements.0.renderer'));
        $this->assertTrue(data_get($payload, 'placements.0.rendererConflict'));
        $this->assertFalse(data_get($payload, 'placements.0.enabled'));
        $this->assertNull(data_get($payload, 'placements.0.adUnitPath'));
    }

    public function test_paused_and_master_ad_serving_controls_disable_every_engine(): void
    {
        $paused = $this->site(ServingMode::Paused, prebid: true, directJs: true);
        $pausedPayload = $this->config($paused, 6);
        $this->assertSame('paused', $pausedPayload['status']);
        $this->assertFalse($pausedPayload['engines']['gam']['enabled']);
        $this->assertFalse($pausedPayload['engines']['prebid']['enabled']);
        $this->assertFalse($pausedPayload['engines']['directJs']['enabled']);

        $site = $this->site(ServingMode::HorusDirect, prebid: true, directJs: true);
        $prebidPlacement = $this->placement($site, 'master_prebid');
        $directPlacement = $this->placement($site, 'master_direct');
        $this->mapPrebid($site, $prebidPlacement);
        $this->mapDirectJs($site, $directPlacement);
        app(PlatformControlService::class)->set('SITE', $site->id, 'AD_SERVING', true, 'Master pause regression.', $this->admin);

        $master = $this->config($site->refresh(), 7);
        $this->assertSame('paused', $master['status']);
        $this->assertFalse($master['engines']['gam']['enabled']);
        $this->assertFalse($master['engines']['prebid']['enabled']);
        $this->assertFalse($master['engines']['directJs']['enabled']);
        $this->assertTrue(collect($master['placements'])->every(fn ($placement) => $placement['enabled'] === false));
    }

    public function test_engine_specific_controls_do_not_disable_unrelated_engines(): void
    {
        $gamSite = $this->site(ServingMode::HorusGam, directJs: true);
        $directPlacement = $this->placement($gamSite, 'gam_off_direct');
        $this->mapDirectJs($gamSite, $directPlacement);
        app(PlatformControlService::class)->set('SITE', $gamSite->id, 'GAM', true, 'Pause GAM only.', $this->admin);
        $gamOff = $this->config($gamSite->refresh(), 8);
        $this->assertFalse($gamOff['engines']['gam']['enabled']);
        $this->assertTrue($gamOff['engines']['directJs']['enabled']);
        $this->assertSame('DIRECT_JS', data_get($gamOff, 'placements.0.renderer'));
        $this->assertNull(data_get($gamOff, 'placements.0.adUnitPath'));

        $prebidOffSite = $this->site(ServingMode::HorusDirect, prebid: true, directJs: true);
        $prebidPlacement = $this->placement($prebidOffSite, 'prebid_off');
        $directPlacement = $this->placement($prebidOffSite, 'direct_survives');
        $this->mapPrebid($prebidOffSite, $prebidPlacement);
        $this->mapDirectJs($prebidOffSite, $directPlacement);
        app(PlatformControlService::class)->set('SITE', $prebidOffSite->id, 'PREBID', true, 'Pause Prebid only.', $this->admin);
        $prebidOff = $this->config($prebidOffSite->refresh(), 9);
        $this->assertFalse($prebidOff['engines']['prebid']['enabled']);
        $this->assertTrue($prebidOff['engines']['directJs']['enabled']);
        $this->assertSame('DIRECT_JS', collect($prebidOff['placements'])->firstWhere('code', 'direct_survives')['renderer']);

        $directOffSite = $this->site(ServingMode::HorusDirect, prebid: true, directJs: true);
        $prebidPlacement = $this->placement($directOffSite, 'prebid_survives');
        $directPlacement = $this->placement($directOffSite, 'direct_off');
        $this->mapPrebid($directOffSite, $prebidPlacement);
        $this->mapDirectJs($directOffSite, $directPlacement);
        app(PlatformControlService::class)->set('SITE', $directOffSite->id, 'DIRECT_JS', true, 'Pause Direct JS only.', $this->admin);
        $directOff = $this->config($directOffSite->refresh(), 10);
        $this->assertTrue($directOff['engines']['prebid']['enabled']);
        $this->assertFalse($directOff['engines']['directJs']['enabled']);
        $this->assertSame('PREBID_STANDALONE', collect($directOff['placements'])->firstWhere('code', 'prebid_survives')['renderer']);
        $this->assertSame([], data_get($directOff, 'nativeDemand.placements.direct_off.candidates', []));
    }

    public function test_schema_v3_is_deterministic_additive_and_never_exposes_credentials(): void
    {
        $site = $this->site(ServingMode::HorusDirect, directJs: true);
        $placement = $this->placement($site, 'secret_safe');
        $account = $this->mapDirectJs($site, $placement);
        app(DemandAccountService::class)->upsertCredential($account, [
            'credential_key' => 'api_token',
            'reference' => 'env:MGID_API_TOKEN',
            'hint' => 'server-side only',
        ], $this->admin);

        $this->travelTo('2026-08-12 20:00:00');
        $first = $this->config($site->refresh(), 11);
        $second = $this->config($site->refresh(), 11);
        $encoded = json_encode($first, JSON_THROW_ON_ERROR);

        $this->assertSame($first, $second);
        $this->assertSame(3, $first['schemaVersion']);
        $this->assertArrayHasKey('engines', $first);
        $this->assertArrayHasKey('gamNetworkCode', $first);
        $this->assertArrayHasKey('prebidEnabled', $first);
        $this->assertArrayHasKey('nativeDemandEnabled', $first);
        $this->assertArrayHasKey('gpt', $first);
        $this->assertStringNotContainsString('MGID_API_TOKEN', $encoded);
        $this->assertStringNotContainsString('credential', strtolower($encoded));
        $this->assertStringNotContainsString('api_token', strtolower($encoded));
    }

    private function site(ServingMode $mode, bool $prebid = false, bool $directJs = false): Site
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Site '.$mode->value.' '.fake()->unique()->numerify('###'),
            'primary_domain' => fake()->unique()->domainName(),
            'prebid_enabled' => $prebid,
            'native_demand_enabled' => $directJs,
        ]);
        $site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => $mode,
            'prebid_enabled' => $prebid,
            'native_demand_enabled' => $directJs,
        ]);
        $site->servingSettings()->update([
            'serving_mode' => $mode,
            'prebid_enabled' => $prebid,
            'native_demand_enabled' => $directJs,
        ]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        return $site->refresh();
    }

    private function placement(Site $site, string $code)
    {
        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => ucfirst(str_replace('_', ' ', $code)),
            'code' => $code,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);

        return $inventory->createPlacement($site, [
            'name' => ucfirst(str_replace('_', ' ', $code)),
            'code' => $code,
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
    }

    private function mapPrebid(Site $site, $placement): void
    {
        $manager = app(PrebidManager::class);
        $bidder = PrebidBidder::withoutGlobalScopes()->where('code', 'msft')->firstOrFail();
        $account = $manager->addAccount($bidder, ['name' => 'Account '.$site->public_key.' '.$placement->code, 'enabled' => true], $this->admin);
        $siteMapping = $manager->assignToSite($account, $site, ['enabled' => true], $this->admin);
        $manager->assignToPlacement($siteMapping, $placement, [
            'placement_id_value' => 'placement-'.$placement->code,
            'enabled' => true,
        ], $this->admin);

        if ($site->serving_mode === ServingMode::HorusDirect) {
            $manager->updateStandaloneSettings($site, [
                'enabled' => true,
                'auction_timeout_ms' => 1200,
                'price_granularity' => 'medium',
                'currency' => 'USD',
                'bidder_sequence' => 'fixed',
                'gam_fallback' => false,
            ], $this->admin);
        }
    }

    private function mapDirectJs(Site $site, $placement)
    {
        $network = DemandNetwork::query()->where('code', 'MGID')->firstOrFail();
        $service = app(DemandAccountService::class);
        $account = $service->create([
            'organization_id' => $this->horus->id,
            'demand_network_id' => $network->id,
            'name' => 'Direct '.$site->public_key.' '.$placement->code,
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 20,
            'fallback_priority' => 10,
            'account_identifier' => 'public-'.$placement->code,
            'configuration' => [
                'script_url' => 'https://jsc.mgid.com/'.$site->primary_domain.'/'.$placement->code.'.js',
                'container_id' => 'widget-'.$placement->code,
                'render_timeout_ms' => 500,
                'currency' => 'USD',
            ],
        ], $this->admin);
        $demandSite = $service->assignSite($account, $site, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'fallback_priority' => 10,
            'remote_site_id' => 'remote-'.$site->public_key,
        ], $this->admin);
        $demandPlacement = $service->assignPlacement($demandSite, $placement, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'fallback_priority' => 10,
            'remote_placement_id' => 'remote-'.$placement->code,
            'placement_code' => 'widget-'.$placement->code,
        ], $this->admin);
        $service->upsertWidget($demandPlacement, [
            'name' => 'Widget '.$placement->code,
            'remote_widget_id' => 'widget-'.$placement->code,
            'widget_code' => 'widget-'.$placement->code,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'configuration' => [],
        ], $this->admin);

        return $account->refresh();
    }

    private function config(Site $site, int $version): array
    {
        return app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, $version);
    }
}
