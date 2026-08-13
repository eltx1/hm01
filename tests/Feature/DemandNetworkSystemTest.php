<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\GamConnectionType;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandRemoteObject;
use App\Models\GamRemoteObject;
use App\Services\Demand\Contracts\DemandConnectorInterface;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Demand\DemandConnectorManager;
use App\Services\Demand\DemandGamDeploymentService;
use App\Services\Demand\DemandReportService;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class DemandNetworkSystemTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    public function test_registry_exposes_all_modular_connectors_through_one_contract(): void
    {
        $this->seed(DemandNetworkSeeder::class);

        $this->assertSame(8, DemandNetwork::query()->count());
        $this->assertDatabaseHas('demand_networks', ['code' => 'MGID', 'supports_direct_js' => true, 'supports_gam_creative' => true]);
        $this->assertDatabaseHas('demand_networks', ['code' => 'TABOOLA']);
        $this->assertDatabaseHas('demand_networks', ['code' => 'SPEAKOL']);
        $this->assertDatabaseHas('demand_networks', ['code' => 'OUTBRAIN']);
        $this->assertDatabaseHas('demand_networks', ['code' => 'EXOCLICK', 'supports_direct_js' => true, 'supports_gam_creative' => false, 'supports_gam_line_item' => false]);

        [$account] = $this->approvedMgidMapping(DemandIntegrationMode::DirectJs);
        $connector = app(DemandConnectorManager::class)->for($account);

        $this->assertInstanceOf(DemandConnectorInterface::class, $connector);
        $this->assertSame([], $connector->validateConfiguration());
        $this->assertTrue($connector->testConnection(['dry_run' => true])->success);
    }

    public function test_public_configuration_contains_only_approved_enabled_mappings_and_never_credentials(): void
    {
        [$account, $demandSite, $demandPlacement, $site] = $this->approvedMgidMapping(DemandIntegrationMode::DirectJs);
        app(DemandAccountService::class)->upsertCredential($account, [
            'credential_key' => 'api_token',
            'reference' => 'env:MGID_API_TOKEN',
            'hint' => 'configured in hosting environment',
        ], $this->admin);

        $payload = app(SiteConfigurationBuilder::class)->build($site->fresh(), ConfigEnvironment::Production, 1);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $candidate = data_get($payload, 'nativeDemand.placements.article_native.candidates.0');

        $this->assertTrue($payload['nativeDemandEnabled']);
        $this->assertSame('MGID', $candidate['network']);
        $this->assertSame('DIRECT_JS', $candidate['mode']);
        $this->assertFalse($candidate['gamManaged']);
        $this->assertSame('https://jsc.mgid.com/publisher.example/article.js', data_get($candidate, 'tag.scriptUrl'));
        $this->assertStringNotContainsString('MGID_API_TOKEN', $encoded);
        $this->assertStringNotContainsString('credential', strtolower($encoded));
        $this->assertDatabaseMissing('demand_account_credentials', ['reference' => 'env:MGID_API_TOKEN']);

        $demandPlacement->update(['approval_status' => DemandApprovalStatus::Rejected]);
        $rejected = app(DemandConfigurationBuilder::class)->build($site->fresh());
        $this->assertFalse($rejected['enabled']);
        $this->assertSame([], $rejected['placements']);

        $demandPlacement->update(['approval_status' => DemandApprovalStatus::Approved]);
        $demandSite->update(['is_enabled' => false]);
        $disabled = app(DemandConfigurationBuilder::class)->build($site->fresh());
        $this->assertFalse($disabled['enabled']);
    }

    public function test_direct_js_and_gam_modes_produce_distinct_delivery_outputs(): void
    {
        [$account, $demandSite, $demandPlacement, $site] = $this->approvedMgidMapping(DemandIntegrationMode::DirectJs);
        $builder = app(DemandConfigurationBuilder::class);

        $direct = data_get($builder->build($site->fresh()), 'placements.article_native.candidates.0');
        $this->assertFalse($direct['gamManaged']);
        $this->assertArrayHasKey('tag', $direct);

        $account->update(['integration_mode' => DemandIntegrationMode::GamThirdPartyCreative]);
        $demandSite->update(['integration_mode' => DemandIntegrationMode::GamThirdPartyCreative]);
        $demandPlacement->update(['integration_mode' => DemandIntegrationMode::GamThirdPartyCreative]);
        $gam = data_get($builder->build($site->fresh()), 'placements.article_native.candidates.0');

        $this->assertTrue($gam['gamManaged']);
        $this->assertSame('GAM_THIRD_PARTY_CREATIVE', $gam['mode']);
        $this->assertArrayNotHasKey('tag', $gam);

        $creative = app(DemandConnectorManager::class)->for($account->fresh())->generateGamCreative($demandPlacement->fresh());
        $this->assertSame('THIRD_PARTY', $creative['creativeType']);
        $this->assertStringContainsString('<script', $creative['snippet']);
    }

    public function test_disabling_connector_removes_future_delivery_but_preserves_reports(): void
    {
        [$account, , , $site] = $this->approvedMgidMapping(DemandIntegrationMode::DirectJs);
        $csv = "date,site,placement,impressions,clicks,revenue\n2026-07-29,publisher.example,article_native,100,4,12.34\n";
        $import = app(DemandReportService::class)->importCsv(
            $account,
            UploadedFile::fake()->createWithContent('mgid.csv', $csv),
            now()->startOfDay(),
            now()->startOfDay(),
            $this->admin,
        );

        $this->assertSame(1, $import->row_count);
        $this->assertSame(1234, data_get($import->totals, 'revenue_minor'));

        $account->network->update(['is_enabled' => false]);
        $configuration = app(DemandConfigurationBuilder::class)->build($site->fresh());
        $summary = app(DemandReportService::class)->summary($account->fresh());

        $this->assertFalse($configuration['enabled']);
        $this->assertSame(1, $summary['imports']);
        $this->assertSame(100, $summary['impressions']);
        $this->assertSame(1234, $summary['revenue_minor']);
        $this->assertDatabaseHas('demand_report_imports', ['id' => $import->id, 'status' => 'COMPLETED']);
    }

    public function test_gam_native_deployment_is_repeat_safe_and_pause_resume_are_synchronized(): void
    {
        [$account, $demandSite, $demandPlacement] = $this->approvedMgidMapping(DemandIntegrationMode::GamThirdPartyCreative);
        $service = app(DemandGamDeploymentService::class);
        $preview = $service->preview($demandSite);

        $this->assertSame([], $preview['issues']);
        $this->assertSame(5, $preview['estimatedObjects']);

        $first = $service->deploy($demandSite, $this->admin, false, true);
        $this->assertTrue($first['success']);
        $count = DemandRemoteObject::withoutGlobalScopes()->where('demand_account_id', $account->id)->where('connection_key', $this->gam->id)->count();
        $this->assertSame(5, $count);

        $second = $service->deploy($demandSite->fresh(), $this->admin, false, true);
        $this->assertTrue($second['success']);
        $this->assertSame($count, DemandRemoteObject::withoutGlobalScopes()->where('demand_account_id', $account->id)->where('connection_key', $this->gam->id)->count());

        $creativeBefore = DemandRemoteObject::withoutGlobalScopes()
            ->where('demand_account_id', $account->id)
            ->where('connection_key', $this->gam->id)
            ->where('remote_object_type', 'creative')
            ->firstOrFail();
        $associationBefore = DemandRemoteObject::withoutGlobalScopes()
            ->where('demand_account_id', $account->id)
            ->where('connection_key', $this->gam->id)
            ->where('remote_object_type', 'creative_association')
            ->firstOrFail();
        $widget = $demandPlacement->widgets()->firstOrFail();
        $widget->update(['configuration' => ['container_id' => 'mgid-widget-replacement']]);
        $replacement = $service->deploy($demandSite->fresh(), $this->admin, false, true);
        $this->assertTrue($replacement['success']);
        $this->assertNotSame($creativeBefore->payload_hash, $creativeBefore->fresh()->payload_hash);
        $this->assertNotSame($associationBefore->payload_hash, $associationBefore->fresh()->payload_hash);
        $this->assertSame($count, DemandRemoteObject::withoutGlobalScopes()->where('demand_account_id', $account->id)->where('connection_key', $this->gam->id)->count());

        $this->assertTrue($service->pause($demandPlacement->fresh(), $this->admin));
        $this->assertDatabaseHas('demand_placements', ['id' => $demandPlacement->id, 'is_enabled' => false, 'sync_status' => 'PAUSED']);
        $this->assertDatabaseHas('demand_remote_objects', [
            'demand_account_id' => $account->id,
            'local_object_type' => 'demand_placement',
            'local_object_id' => $demandPlacement->id,
            'remote_object_type' => 'line_item',
            'remote_status' => 'PAUSED',
        ]);

        $this->assertTrue($service->resume($demandPlacement->fresh(), $this->admin));
        $this->assertDatabaseHas('demand_placements', ['id' => $demandPlacement->id, 'is_enabled' => true, 'sync_status' => 'IN_SYNC']);
    }

    private $admin;
    private $gam;

    private function approvedMgidMapping(DemandIntegrationMode $mode): array
    {
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, DemandNetworkSeeder::class]);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $this->gam = $this->makeGamConnection($horus, $this->admin, [
            'type' => GamConnectionType::HorusGam,
            'driver' => 'MOCK',
            'network_code' => '111111111',
            'is_primary' => true,
            'dry_run_default' => false,
        ]);

        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher');
        $publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['display_name' => 'Publisher']);
        $site = $this->makeSiteFor($publisher, $publisherUser, [
            'display_name' => 'Publisher Site',
            'primary_domain' => 'publisher.example',
            'native_demand_enabled' => true,
        ]);

        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => 'Article Native',
            'code' => 'article_native',
            'sizes' => [['width' => 1, 'height' => 1]],
        ], $this->admin);
        $placement = $inventory->createPlacement($site, [
            'name' => 'Article Native',
            'code' => 'article_native',
            'type' => 'NATIVE',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 1, 'height' => 1]],
        ], $this->admin);
        GamRemoteObject::withoutGlobalScopes()->create([
            'organization_id' => $horus->id,
            'gam_connection_id' => $this->gam->id,
            'local_object_type' => 'ad_unit',
            'local_object_id' => $adUnit->id,
            'remote_object_type' => 'ad_unit',
            'remote_object_id' => '5101',
            'idempotency_key' => hash('sha256', 'native-test-ad-unit'),
            'payload_hash' => hash('sha256', 'native-test-ad-unit-payload'),
            'remote_status' => 'ACTIVE',
            'metadata' => ['local_hash' => hash('sha256', 'native-test-ad-unit-payload')],
            'synced_at' => now(),
        ]);

        $network = DemandNetwork::query()->where('code', 'MGID')->firstOrFail();
        $accounts = app(DemandAccountService::class);
        $account = $accounts->create([
            'organization_id' => $horus->id,
            'demand_network_id' => $network->id,
            'name' => 'Horus MGID',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => $mode,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => true,
            'revenue_share_percent' => 20,
            'fallback_priority' => 10,
            'account_identifier' => 'mgid-publisher-100',
            'configuration' => [
                'script_url' => 'https://jsc.mgid.com/publisher.example/article.js',
                'container_id' => 'mgid-widget-100',
                'render_timeout_ms' => 500,
                'ads_txt_records' => ['example.com, 100, DIRECT, abc123'],
                'currency' => 'USD',
            ],
        ], $this->admin);
        $demandSite = $accounts->assignSite($account, $site, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => true,
            'integration_mode' => $mode,
            'fallback_priority' => 10,
            'remote_site_id' => 'mgid-site-100',
        ], $this->admin);
        $demandPlacement = $accounts->assignPlacement($demandSite, $placement, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => $mode,
            'fallback_priority' => 10,
            'remote_placement_id' => 'mgid-placement-100',
            'placement_code' => 'mgid-widget-100',
        ], $this->admin);
        $accounts->upsertWidget($demandPlacement, [
            'name' => 'MGID Article Widget',
            'remote_widget_id' => 'widget-100',
            'widget_code' => 'mgid-widget-100',
            'integration_mode' => $mode,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'configuration' => [],
        ], $this->admin);

        return [$account->refresh(), $demandSite->refresh(), $demandPlacement->refresh(), $site->refresh(), $placement];
    }
}
