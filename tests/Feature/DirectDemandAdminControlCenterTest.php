<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\AuditLog;
use App\Models\ConfigVersion;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandWidget;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandReportService;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigPublisher;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Monetization\SiteMonetizationReadinessService;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class DirectDemandAdminControlCenterTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $horus;
    private $publisherUser;
    private $publisher;
    private $site;
    private $placement;
    private $account;
    private $demandSite;
    private $demandPlacement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, DemandNetworkSeeder::class]);

        $this->horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($this->horus, RoleName::SuperAdmin);
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Publisher Tenant');
        $this->publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Publisher Tenant']);

        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Direct Control Site',
            'primary_domain' => 'direct-control.example',
            'native_demand_enabled' => true,
        ]);
        $this->site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => true,
        ]);
        $this->site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => true,
        ]);
        $this->site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($this->site, [
            'name' => 'Header Banner', 'code' => 'header_banner',
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
        $this->placement = $inventory->createPlacement($this->site, [
            'name' => 'Header Banner', 'code' => 'header_banner', 'type' => 'DISPLAY', 'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id, 'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);

        [$this->account, $this->demandSite, $this->demandPlacement] = $this->approvedMgid($this->site, $this->placement);
    }

    public function test_admin_demand_control_center_is_horus_only(): void
    {
        $this->actingAs($this->publisherUser)->get(route('admin.demand.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('admin.demand.index'))->assertOk()->assertSee('Direct Demand');
    }

    public function test_master_network_account_site_and_placement_toggles_are_audited_and_publish_static_config(): void
    {
        $this->actingAs($this->admin);
        $before = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();

        $this->patch(route('admin.demand.master'), ['enabled' => 0, 'reason' => 'Task 18 master maintenance'])->assertRedirect();
        $this->assertDatabaseHas('platform_controls', ['scope_type' => 'GLOBAL', 'control_key' => 'DIRECT_JS', 'is_disabled' => true]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'platform.control.changed']);

        $this->patch(route('admin.demand.networks.toggle', $this->account->network), ['is_enabled' => 0])->assertRedirect();
        $this->assertFalse($this->account->network->fresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', ['event' => 'demand.network.enabled_changed']);
        $this->patch(route('admin.demand.networks.toggle', $this->account->network), ['is_enabled' => 1])->assertRedirect();

        $this->patch(route('admin.demand.accounts.enabled', $this->account), ['enabled' => 0])->assertRedirect();
        $this->assertFalse($this->account->fresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', ['event' => 'demand.account.updated']);
        $this->patch(route('admin.demand.accounts.enabled', $this->account), ['enabled' => 1])->assertRedirect();

        $this->patch(route('admin.sites.demand.mappings.enabled', [$this->site, $this->demandSite]), ['enabled' => 0])->assertRedirect();
        $this->assertFalse($this->demandSite->fresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', ['event' => 'demand.site.enabled_changed']);
        $this->patch(route('admin.sites.demand.mappings.enabled', [$this->site, $this->demandSite]), ['enabled' => 1])->assertRedirect();

        $this->patch(route('admin.sites.demand.placements.enabled', [$this->site, $this->demandPlacement]), ['enabled' => 0])->assertRedirect();
        $this->assertFalse($this->demandPlacement->fresh()->is_enabled);
        $this->assertDatabaseHas('audit_logs', ['event' => 'demand.placement.enabled_changed']);

        $after = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->count();
        $this->assertGreaterThan($before, $after);
    }

    public function test_network_policy_updates_formats_origins_and_runtime_control_without_horus_origin(): void
    {
        $this->actingAs($this->admin);
        $network = $this->account->network;
        $this->put(route('admin.demand.networks.settings', $network), [
            'supports_direct_js' => 1,
            'supported_formats' => ['DISPLAY', 'NATIVE'],
            'integration_modes' => ['DIRECT_JS'],
            'script_origins' => ['https://jsc.mgid.com'],
            'operational_health' => 'HEALTHY',
        ])->assertRedirect();

        $network = $network->fresh();
        $this->assertTrue($network->supports_direct_js);
        $this->assertSame(['DISPLAY', 'NATIVE'], data_get($network->capabilities, 'supported_formats'));
        $this->assertSame(['https://jsc.mgid.com'], $network->script_origins);
        $this->assertSame('HEALTHY', data_get($network->metadata, 'operational_health'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'demand.network.updated']);

        $this->put(route('admin.demand.networks.settings', $network), [
            'supports_direct_js' => 1,
            'supported_formats' => ['DISPLAY'], 'integration_modes' => ['DIRECT_JS'],
            'script_origins' => ['https://app.horusmedia.net'], 'operational_health' => 'HEALTHY',
        ])->assertSessionHasErrors('script_origins');

        $this->patch(route('admin.demand.networks.direct-js', $network), ['enabled' => 0, 'reason' => 'Pause provider runtime'])->assertRedirect();
        $this->assertDatabaseHas('platform_controls', ['scope_type' => 'DEMAND_NETWORK', 'scope_id' => $network->id, 'control_key' => 'DIRECT_JS', 'is_disabled' => true]);
    }

    public function test_tenant_isolation_rejects_cross_site_mapping_mutations(): void
    {
        $other = $this->makeSiteFor($this->publisher, $this->publisherUser, ['display_name' => 'Other Site', 'primary_domain' => 'other.example']);
        $this->actingAs($this->admin)
            ->patch(route('admin.sites.demand.mappings.enabled', [$other, $this->demandSite]), ['enabled' => 0])
            ->assertNotFound();
        $this->actingAs($this->admin)
            ->patch(route('admin.sites.demand.placements.enabled', [$other, $this->demandPlacement]), ['enabled' => 0])
            ->assertNotFound();
    }

    public function test_safe_tag_preview_is_escaped_and_never_exposes_credentials(): void
    {
        app(DemandAccountService::class)->upsertCredential($this->account, [
            'credential_key' => 'api_token', 'reference' => 'env:MGID_SECRET_TOKEN', 'hint' => 'server only',
        ], $this->admin);

        $tag = '<div id="mgid-zone" class="mgbox" data-type="_mgwidget" data-widget-id="101"></div>'
            .'<script async src="https://jsc.mgid.com/example/loader.js"></script>'
            .'<script>window._mgq = window._mgq || []; window._mgq.push(["_mgc.load"]);</script>';
        $response = $this->actingAs($this->admin)->post(route('admin.demand.tags.preview', $this->account), ['tag' => $tag]);
        $response->assertOk()->assertSee('STRUCTURED SAFE')->assertSee('MGID_QUEUE_LOAD')->assertDontSee('MGID_SECRET_TOKEN');
        $this->assertStringNotContainsString('<script>window._mgq', $response->getContent());
    }

    public function test_unsafe_inline_tag_cannot_be_approved_for_normal_provider(): void
    {
        $tag = '<div id="mgid-zone" class="mgbox" data-type="_mgwidget" data-widget-id="102"></div>'
            .'<script async src="https://jsc.mgid.com/example/loader.js"></script>'
            .'<script>window.top.location="https://evil.example";</script>';

        $this->actingAs($this->admin)->post(route('admin.sites.demand.widgets.store', [$this->site, $this->demandPlacement]), [
            'name' => 'Unsafe widget', 'integration_mode' => 'DIRECT_JS', 'approval_status' => 'APPROVED',
            'is_enabled' => 1, 'tag_review_approved' => 1, 'direct_tag_template' => $tag, 'configuration_json' => '{}',
        ])->assertSessionHasErrors('direct_tag_template');

        $this->assertDatabaseMissing('demand_widgets', ['demand_placement_id' => $this->demandPlacement->id, 'name' => 'Unsafe widget', 'approval_status' => 'APPROVED']);
    }

    public function test_custom_third_party_requires_isolation_origins_and_never_becomes_top_window_recipe(): void
    {
        $network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();
        $service = app(DemandAccountService::class);
        $custom = $service->create([
            'organization_id' => $this->horus->id, 'demand_network_id' => $network->id, 'name' => 'Custom Isolated',
            'scope' => DemandAccountScope::HorusMedia, 'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'is_default' => false,
            'revenue_share_percent' => 10, 'fallback_priority' => 20, 'account_identifier' => 'custom-public',
            'configuration' => ['allowed_script_origins' => ['https://ads.example.com']],
        ], $this->admin);
        $customSite = $service->assignSite($custom, $this->site, [
            'approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'integration_mode' => DemandIntegrationMode::DirectJs,
        ], $this->admin);
        $customPlacement = $service->assignPlacement($customSite, $this->placement, [
            'approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'integration_mode' => DemandIntegrationMode::DirectJs,
            'placement_code' => 'custom-zone',
        ], $this->admin);
        $tag = '<div id="custom-zone"></div><script src="https://ads.example.com/public.js"></script><script>window.providerQueue=window.providerQueue||[];</script>';

        $this->actingAs($this->admin)->post(route('admin.sites.demand.widgets.store', [$this->site, $customPlacement]), [
            'name' => 'Missing isolation', 'integration_mode' => 'DIRECT_JS', 'approval_status' => 'APPROVED', 'is_enabled' => 1,
            'tag_review_approved' => 1, 'direct_tag_template' => $tag, 'configuration_json' => '{}',
        ])->assertSessionHasErrors('configuration_json');

        $this->post(route('admin.sites.demand.widgets.store', [$this->site, $customPlacement]), [
            'name' => 'Isolated widget', 'integration_mode' => 'DIRECT_JS', 'approval_status' => 'APPROVED', 'is_enabled' => 1,
            'tag_review_approved' => 1, 'direct_tag_template' => $tag,
            'configuration_json' => json_encode(['isolation_allowed_origins' => ['https://ads.example.com']]),
        ])->assertRedirect();

        $widget = DemandWidget::withoutGlobalScopes()->where('demand_placement_id', $customPlacement->id)->where('name', 'Isolated widget')->firstOrFail();
        $this->assertSame('APPROVED', $widget->approval_status->value);
        $payload = app(SiteConfigurationBuilder::class)->build($this->site->refresh(), ConfigEnvironment::Production, 1801);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('ISOLATED_IFRAME', $encoded);
        $this->assertStringContainsString('allow-scripts', $encoded);
        $this->assertStringNotContainsString('allow-same-origin', $encoded);
    }

    public function test_ads_txt_and_reporting_reuse_existing_canonical_services(): void
    {
        $this->actingAs($this->admin)->post(route('admin.sites.demand.ads_txt', [$this->site, $this->demandSite]))->assertRedirect();
        $this->assertDatabaseHas('ads_txt_records', ['site_id' => $this->site->id, 'account_id' => '100']);

        $csv = "date,site,placement,impressions,clicks,revenue\n2026-08-12,direct-control.example,header_banner,100,5,7.25\n";
        $this->post(route('admin.demand.reports.csv', $this->account), [
            'from' => '2026-08-12', 'to' => '2026-08-12',
            'report' => UploadedFile::fake()->createWithContent('direct-demand.csv', $csv),
        ])->assertRedirect();
        $summary = app(DemandReportService::class)->summary($this->account->refresh());
        $this->assertSame(100, $summary['impressions']);
        $this->assertSame(5, $summary['clicks']);
        $this->assertSame(725, $summary['revenue_minor']);
    }

    public function test_publisher_readiness_is_white_label_and_static_config_has_no_credentials(): void
    {
        app(DemandAccountService::class)->upsertCredential($this->account, [
            'credential_key' => 'api_token', 'reference' => 'env:PRIVATE_PROVIDER_TOKEN', 'hint' => 'server-side only',
        ], $this->admin);
        $publisher = app(SiteMonetizationReadinessService::class)->publisher($this->site->refresh());
        $encodedHealth = json_encode($publisher, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('Direct Monetization', $encodedHealth);
        $this->assertStringNotContainsString('MGID', $encodedHealth);
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_TOKEN', $encodedHealth);
        $this->assertStringNotContainsString('revenue_share', strtolower($encodedHealth));

        $config = app(SiteConfigurationBuilder::class)->build($this->site->refresh(), ConfigEnvironment::Production, 1802);
        $encoded = json_encode($config, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('PRIVATE_PROVIDER_TOKEN', $encoded);
        $this->assertStringNotContainsString('credential', strtolower($encoded));
    }

    public function test_static_publication_rollback_and_unrelated_prebid_gam_state_remain_intact(): void
    {
        $gam = $this->makeGamConnection($this->horus, $this->admin, [
            'type' => GamConnectionType::HorusGam, 'driver' => 'MOCK', 'network_code' => '222222222',
            'is_primary' => true, 'configuration' => ['root_ad_unit_id' => '1111'],
        ]);
        $this->site->update(['serving_mode' => ServingMode::HorusGam]);
        $this->site->servingSettings()->update(['serving_mode' => ServingMode::HorusGam, 'prebid_enabled' => false]);

        $publisher = app(SiteConfigPublisher::class);
        $first = $publisher->publishActiveProduction($this->site->refresh(), $this->admin);
        $firstPayload = $first->payload;
        $this->assertTrue(data_get($firstPayload, 'engines.gam.enabled'));
        $this->assertFalse(data_get($firstPayload, 'engines.prebid.enabled'));

        $this->actingAs($this->admin)->patch(route('admin.sites.demand.placements.enabled', [$this->site, $this->demandPlacement]), ['enabled' => 0])->assertRedirect();
        $latest = ConfigVersion::withoutGlobalScopes()->where('site_id', $this->site->id)->latest('version')->firstOrFail();
        $this->assertTrue(data_get($latest->payload, 'engines.gam.enabled'));
        $this->assertFalse(data_get($latest->payload, 'engines.prebid.enabled'));

        $rollback = $publisher->rollback($this->site->refresh(), ConfigEnvironment::Production, $first, $this->admin);
        $this->assertSame($first->version, $rollback->payload['rollbackSourceVersion']);
        $this->assertTrue(data_get($rollback->payload, 'engines.gam.enabled'));
        $this->assertFalse(data_get($rollback->payload, 'engines.prebid.enabled'));
        $this->assertSame($gam->id, $gam->fresh()->id);
    }

    private function approvedMgid($site, $placement): array
    {
        $network = DemandNetwork::query()->where('code', 'MGID')->firstOrFail();
        $service = app(DemandAccountService::class);
        $account = $service->create([
            'organization_id' => $this->horus->id, 'demand_network_id' => $network->id, 'name' => 'Horus MGID Direct',
            'scope' => DemandAccountScope::HorusMedia, 'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'is_default' => true,
            'revenue_share_percent' => 20, 'fallback_priority' => 10, 'account_identifier' => 'mgid-publisher-100',
            'configuration' => [
                'script_url' => 'https://jsc.mgid.com/direct-control.example/header.js',
                'container_id' => 'mgid-header', 'render_timeout_ms' => 500,
                'ads_txt_records' => ['example.com, 100, DIRECT, abc123'], 'currency' => 'USD',
            ],
        ], $this->admin);
        $demandSite = $service->assignSite($account, $site, [
            'approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'is_default' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs, 'fallback_priority' => 10, 'remote_site_id' => 'site-100',
        ], $this->admin);
        $demandPlacement = $service->assignPlacement($demandSite, $placement, [
            'approval_status' => DemandApprovalStatus::Approved, 'is_enabled' => true, 'integration_mode' => DemandIntegrationMode::DirectJs,
            'fallback_priority' => 10, 'remote_placement_id' => 'placement-100', 'placement_code' => 'mgid-header',
        ], $this->admin);
        $service->upsertWidget($demandPlacement, [
            'name' => 'Existing MGID widget', 'remote_widget_id' => 'widget-100', 'widget_code' => 'mgid-header',
            'integration_mode' => DemandIntegrationMode::DirectJs, 'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true, 'configuration' => [],
        ], $this->admin);

        return [$account->refresh(), $demandSite->refresh(), $demandPlacement->refresh()];
    }
}
