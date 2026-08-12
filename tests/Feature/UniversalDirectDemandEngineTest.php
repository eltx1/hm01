<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\DemandNetwork;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Demand\DemandConnectorManager;
use App\Services\Demand\DirectTagRecipeParser;
use App\Services\Inventory\InventoryManager;
use App\Services\Inventory\SiteConfigurationBuilder;
use App\Services\Operations\PlatformControlService;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class UniversalDirectDemandEngineTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $horus;
    private $publisherUser;
    private $publisher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, DemandNetworkSeeder::class]);

        $this->horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($this->horus, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Direct Demand Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Direct Demand Publisher']);
    }

    public function test_provider_neutral_parser_extracts_structured_public_material_without_execution(): void
    {
        $parser = app(DirectTagRecipeParser::class);
        $parsed = $parser->parse(<<<'HTML'
<div id="zone-100" class="provider-zone" data-zone-id="100"></div>
<script async src="https://jsc.mgid.com/example/loader-a.js"></script>
<script defer src="https://jsc.mgid.com/example/loader-b.js"></script>
HTML);

        $this->assertCount(2, $parsed['detectedScripts']);
        $this->assertSame('https://jsc.mgid.com/example/loader-a.js', data_get($parsed, 'detectedScripts.0.url'));
        $this->assertTrue(data_get($parsed, 'detectedScripts.0.async'));
        $this->assertTrue(data_get($parsed, 'detectedScripts.1.defer'));
        $this->assertSame('zone-100', data_get($parsed, 'detectedContainers.0.id'));
        $this->assertSame('100', data_get($parsed, 'detectedContainers.0.attributes.data-zone-id'));
        $this->assertContains('zone-100', $parsed['detectedPublicIdentifiers']);
        $this->assertContains('100', $parsed['detectedPublicIdentifiers']);
        $this->assertSame([], $parsed['unsupportedInlineCode']);
        $this->assertSame([], $parsed['securityWarnings']);
    }

    public function test_parser_normalizes_protocol_relative_https_but_rejects_javascript_and_credentials(): void
    {
        $parser = app(DirectTagRecipeParser::class);
        $protocolRelative = $parser->parse('<div id="taboola-zone"></div><script src="//cdn.taboola.com/libtrc/provider/loader.js"></script>');
        $this->assertSame('https://cdn.taboola.com/libtrc/provider/loader.js', data_get($protocolRelative, 'detectedScripts.0.url'));
        $this->assertSame([], $protocolRelative['securityWarnings']);

        $javascript = $parser->parse('<div id="bad"></div><script src="javascript:alert(1)"></script>');
        $this->assertNotEmpty($javascript['securityWarnings']);

        $credential = $parser->parse('<div id="bad" data-api-key="super-secret"></div><script src="https://jsc.mgid.com/example.js"></script>');
        $this->assertTrue($credential['containsSensitiveMaterial']);
        $this->assertNotEmpty($credential['securityWarnings']);
    }

    public function test_mgid_import_accepts_only_its_trusted_queue_recipe(): void
    {
        [$account] = $this->approvedMgidMapping('mgid_import');
        $connector = app(DemandConnectorManager::class)->for($account);

        $safe = $connector->parseDirectTag(<<<'HTML'
<div id="mgid-zone-101" class="mgbox" data-type="_mgwidget" data-widget-id="101"></div>
<script async src="https://jsc.mgid.com/example/loader.js"></script>
<script>window._mgq = window._mgq || []; window._mgq.push(["_mgc.load"]);</script>
HTML);
        $this->assertTrue($safe['safe']);
        $this->assertSame('MGID_QUEUE_LOAD', data_get($safe, 'recipe.initialization.type'));
        $this->assertSame('101', data_get($safe, 'recipe.container.attributes.data-widget-id'));

        $unsafe = $connector->parseDirectTag(<<<'HTML'
<div id="mgid-zone-102" class="mgbox" data-type="_mgwidget" data-widget-id="102"></div>
<script async src="https://jsc.mgid.com/example/loader.js"></script>
<script>window.top.location = "https://evil.example";</script>
HTML);
        $this->assertFalse($unsafe['safe']);
        $this->assertNotEmpty($unsafe['unsupportedInlineCode']);
        $this->assertNotEmpty($unsafe['securityWarnings']);
    }

    public function test_structured_recipe_and_schema_v4_are_additive_and_secret_safe(): void
    {
        [$account, , , $site] = $this->approvedMgidMapping('public_recipe');
        app(DemandAccountService::class)->upsertCredential($account, [
            'credential_key' => 'api_token',
            'reference' => 'env:MGID_PRIVATE_TOKEN',
            'hint' => 'server-side only',
        ], $this->admin);

        $demand = app(DemandConfigurationBuilder::class)->build($site->refresh());
        $candidate = data_get($demand, 'placements.public_recipe.candidates.0');
        $this->assertTrue($demand['enabled']);
        $this->assertSame('DIRECT_DEMAND', $demand['engine']);
        $this->assertSame(1, $demand['recipeVersion']);
        $this->assertSame('STRUCTURED', data_get($candidate, 'tag.executionMode'));
        $this->assertSame('MGID_QUEUE_LOAD', data_get($candidate, 'tag.initialization.type'));
        $this->assertSame('https://jsc.mgid.com/publisher.example/public_recipe.js', data_get($candidate, 'tag.scripts.0.url'));
        $this->assertSame(data_get($candidate, 'tag.scripts.0.url'), data_get($candidate, 'tag.scriptUrl'));

        $payload = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1701);
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertSame(4, $payload['schemaVersion']);
        $this->assertTrue($payload['directDemandEnabled']);
        $this->assertSame($payload['directDemand'], $payload['nativeDemand']);
        $this->assertSame($payload['directDemandEnabled'], $payload['nativeDemandEnabled']);
        $this->assertStringNotContainsString('MGID_PRIVATE_TOKEN', $encoded);
        $this->assertStringNotContainsString('env:', $encoded);
        $this->assertStringNotContainsString('credential', strtolower($encoded));
    }

    public function test_network_account_site_and_placement_lifecycle_gates_fail_closed(): void
    {
        [$account, $demandSite, $demandPlacement, $site] = $this->approvedMgidMapping('lifecycle');
        $builder = app(DemandConfigurationBuilder::class);
        $this->assertTrue($builder->build($site->refresh())['enabled']);

        $account->update(['is_enabled' => false]);
        $this->assertFalse($builder->build($site->refresh())['enabled']);
        $account->update(['is_enabled' => true]);

        $demandSite->update(['is_enabled' => false]);
        $this->assertFalse($builder->build($site->refresh())['enabled']);
        $demandSite->update(['is_enabled' => true]);

        $demandPlacement->update(['is_enabled' => false]);
        $this->assertFalse($builder->build($site->refresh())['enabled']);
        $demandPlacement->update(['is_enabled' => true]);

        $account->network->update(['is_enabled' => false]);
        $this->assertFalse($builder->build($site->refresh())['enabled']);
    }

    public function test_network_direct_js_control_disables_only_direct_delivery(): void
    {
        [$account, , , $site] = $this->approvedMgidMapping('network_control');
        app(PlatformControlService::class)->set(
            'DEMAND_NETWORK',
            $account->demand_network_id,
            'DIRECT_JS',
            true,
            'Task 17 network direct serving pause.',
            $this->admin,
        );

        $configuration = app(DemandConfigurationBuilder::class)->build($site->refresh());
        $this->assertFalse($configuration['enabled']);
        $this->assertSame([], $configuration['placements']);
    }

    public function test_custom_third_party_tag_is_opaque_iframe_only_and_requires_explicit_csp_origins(): void
    {
        [$site, $placement] = $this->siteAndPlacement('isolated_custom');
        $network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();
        $service = app(DemandAccountService::class);
        $account = $service->create([
            'organization_id' => $this->horus->id,
            'demand_network_id' => $network->id,
            'name' => 'Isolated Custom Account',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 20,
            'fallback_priority' => 10,
            'account_identifier' => 'public-custom-account',
            'configuration' => [
                'allowed_script_origins' => ['https://ads.example.com'],
                'isolation_allowed_origins' => ['https://ads.example.com'],
                'render_timeout_ms' => 700,
            ],
        ], $this->admin);
        $demandSite = $service->assignSite($account, $site, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
        ], $this->admin);
        $demandPlacement = $service->assignPlacement($demandSite, $placement, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'placement_code' => 'custom-zone',
        ], $this->admin);
        $service->upsertWidget($demandPlacement, [
            'name' => 'Approved isolated tag',
            'widget_code' => 'custom-zone',
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'direct_tag_template' => '<div id="custom-zone"></div><script src="https://ads.example.com/public.js"></script><script>window.providerQueue = window.providerQueue || [];</script>',
            'configuration' => [],
        ], $this->admin);

        $tag = app(DemandConnectorManager::class)->for($account->refresh())->generateDirectTag($demandPlacement->refresh());
        $this->assertSame('ISOLATED_IFRAME', $tag['executionMode']);
        $this->assertSame(['allow-scripts'], data_get($tag, 'isolation.sandbox'));
        $this->assertStringContainsString("default-src 'none'", data_get($tag, 'isolation.csp'));
        $this->assertStringContainsString('https://ads.example.com', data_get($tag, 'isolation.csp'));
        $this->assertStringNotContainsString('allow-same-origin', json_encode($tag, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('allow-top-navigation', json_encode($tag, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString('app.horusmedia.net', json_encode($tag, JSON_THROW_ON_ERROR));
    }

    public function test_paused_site_keeps_recipe_for_rollback_but_disables_renderer(): void
    {
        [, , , $site] = $this->approvedMgidMapping('paused_direct');
        $site->update(['status' => SiteStatus::Suspended]);

        $payload = app(SiteConfigurationBuilder::class)->build($site->refresh(), ConfigEnvironment::Production, 1702);
        $placement = collect($payload['placements'])->firstWhere('code', 'paused_direct');
        $this->assertSame('paused', $payload['status']);
        $this->assertFalse(data_get($payload, 'engines.directJs.enabled'));
        $this->assertFalse($placement['enabled']);
        $this->assertTrue($payload['directDemandEnabled']);
    }

    private function approvedMgidMapping(string $code): array
    {
        [$site, $placement] = $this->siteAndPlacement($code);
        $network = DemandNetwork::query()->where('code', 'MGID')->firstOrFail();
        $service = app(DemandAccountService::class);
        $account = $service->create([
            'organization_id' => $this->horus->id,
            'demand_network_id' => $network->id,
            'name' => 'MGID '.$code,
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 20,
            'fallback_priority' => 10,
            'account_identifier' => 'mgid-public-'.$code,
            'configuration' => [
                'script_url' => 'https://jsc.mgid.com/publisher.example/'.$code.'.js',
                'container_id' => 'mgid-'.$code,
                'render_timeout_ms' => 500,
                'ads_txt_records' => ['example.com, 100, DIRECT, abc123'],
                'currency' => 'USD',
            ],
        ], $this->admin);
        $demandSite = $service->assignSite($account, $site, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'fallback_priority' => 10,
            'remote_site_id' => 'site-'.$code,
        ], $this->admin);
        $demandPlacement = $service->assignPlacement($demandSite, $placement, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'fallback_priority' => 10,
            'remote_placement_id' => 'placement-'.$code,
            'placement_code' => 'mgid-'.$code,
        ], $this->admin);
        $service->upsertWidget($demandPlacement, [
            'name' => 'MGID '.$code.' widget',
            'remote_widget_id' => 'widget-'.$code,
            'widget_code' => 'mgid-'.$code,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'configuration' => [],
        ], $this->admin);

        return [$account->refresh(), $demandSite->refresh(), $demandPlacement->refresh(), $site->refresh(), $placement->refresh()];
    }

    private function siteAndPlacement(string $code): array
    {
        $site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Direct '.$code,
            'primary_domain' => $code.'.example.com',
            'native_demand_enabled' => true,
        ]);
        $site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => true,
        ]);
        $site->servingSettings()->update([
            'serving_mode' => ServingMode::HorusDirect,
            'native_demand_enabled' => true,
        ]);
        $site->siteConfig()->update(['status' => 'ACTIVE', 'immediate_pause' => false]);

        $inventory = app(InventoryManager::class);
        $adUnit = $inventory->createAdUnit($site, [
            'name' => 'Direct '.ucfirst($code),
            'code' => $code,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
        $placement = $inventory->createPlacement($site, [
            'name' => 'Direct '.ucfirst($code),
            'code' => $code,
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);

        return [$site->refresh(), $placement->refresh()];
    }
}
