<?php

namespace Tests\Feature;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Http\Controllers\Admin\DirectDemandQuickMonetizeController;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Models\DemandWidget;
use App\Services\Demand\DemandAccountService;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Inventory\InventoryManager;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

final class DirectDemandQuickFinalHardeningTest extends TestCase
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
        $publisherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Final Hardening Publisher');
        $this->publisherUser = $this->makeUser($publisherOrg, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Final Hardening Publisher']);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Final Hardening Site',
            'primary_domain' => 'quick-final.example',
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
            'name' => 'Final Quick Unit',
            'code' => 'final_quick',
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
        $this->placement = $inventory->createPlacement($this->site, [
            'name' => 'Final Quick Placement',
            'code' => 'final_quick',
            'type' => 'DISPLAY',
            'status' => 'ACTIVE',
            'ad_unit_id' => $adUnit->id,
            'sizes' => [['width' => 300, 'height' => 250]],
        ], $this->admin);
    }

    public function test_quick_csp_keeps_resource_only_origins_out_of_script_src_and_allows_reviewed_stylesheet(): void
    {
        $resourceTag = "<iframe id=\"provider-frame\" src='https://securepubads.g.doubleclick.net/provider-frame'></iframe>"
            .'<link rel=stylesheet href=https://pagead2.googlesyndication.com/provider.css>';
        $resourceOriginsMethod = new \ReflectionMethod(DirectDemandQuickMonetizeController::class, 'resourceOrigins');
        $resourceOriginsMethod->setAccessible(true);
        $resourceOrigins = $resourceOriginsMethod->invoke(app(DirectDemandQuickMonetizeController::class), $resourceTag);

        $this->assertSame(['https://securepubads.g.doubleclick.net'], $resourceOrigins['frame']);
        $this->assertSame(['https://pagead2.googlesyndication.com'], $resourceOrigins['style']);

        $tag = '<script async src="https://cdn.taboola.com/libtrc/horus-final/loader.js"></script>'
            .'<div id="quick-style-zone"></div>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload($tag))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $widget = DemandWidget::withoutGlobalScopes()->firstOrFail();
        $widgetConfiguration = (array) $widget->configuration;
        $widgetConfiguration['isolation_allowed_origins'] = collect((array) ($widgetConfiguration['isolation_allowed_origins'] ?? []))
            ->merge($resourceOrigins['all'])
            ->unique()
            ->values()
            ->all();
        $widgetConfiguration['isolation_frame_origins'] = $resourceOrigins['frame'];
        $widgetConfiguration['isolation_style_origins'] = $resourceOrigins['style'];
        $widget->update(['configuration' => $widgetConfiguration]);

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.final_quick.candidates.0');
        $attributes = (array) data_get($candidate, 'tag.container.attributes', []);
        $csp = $this->decodedPayload($attributes, 'data-hm-isolated-csp');

        $this->assertSame(
            "script-src 'unsafe-inline' https://cdn.taboola.com",
            $this->directive($csp, 'script-src'),
        );
        $this->assertStringContainsString('https://securepubads.g.doubleclick.net', $this->directive($csp, 'frame-src'));
        $this->assertStringNotContainsString('https://pagead2.googlesyndication.com', $this->directive($csp, 'frame-src'));
        $this->assertStringContainsString('https://pagead2.googlesyndication.com', $this->directive($csp, 'style-src'));
        $this->assertStringNotContainsString('https://securepubads.g.doubleclick.net', $this->directive($csp, 'style-src'));
    }

    public function test_single_quoted_private_resource_is_rejected_before_any_write(): void
    {
        $tag = '<script async src="https://cdn.taboola.com/libtrc/horus-final/loader.js"></script>'
            ."<iframe id=\"provider-frame\" src='https://127.0.0.1/private-ad'></iframe>";

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload($tag))
            ->assertSessionHasErrors('tag');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_quick_isolated_tag_cannot_self_navigate_its_sandbox(): void
    {
        $tag = '<script async src="https://cdn.taboola.com/libtrc/horus-final/loader.js"></script>'
            .'<div id="quick-nav-zone"></div>'
            ."<script>window.location='https://127.0.0.1/private-ad';</script>";

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload($tag))
            ->assertSessionHasErrors('tag');

        $this->assertSame(0, DemandAccount::withoutGlobalScopes()->count());
        $this->assertFalse($this->site->fresh()->native_demand_enabled);
    }

    public function test_quick_reactivation_preserves_advanced_mapping_state_it_does_not_own(): void
    {
        $firstTag = '<script async src="https://cdn.taboola.com/libtrc/horus-final-first/loader.js"></script><div id="quick-first-zone"></div>';
        $secondTag = '<script async src="https://cdn.taboola.com/libtrc/horus-final-second/loader.js"></script><div id="quick-second-zone"></div>';

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload($firstTag))
            ->assertRedirect();

        $demandSite = DemandSite::withoutGlobalScopes()->firstOrFail();
        $siteConfiguration = (array) $demandSite->configuration;
        $siteConfiguration['advanced_site_flag'] = 'keep-site';
        $demandSite->update([
            'remote_site_id' => 'advanced-remote-site',
            'configuration' => $siteConfiguration,
        ]);

        $demandPlacement = DemandPlacement::withoutGlobalScopes()->firstOrFail();
        $placementConfiguration = (array) $demandPlacement->configuration;
        $placementConfiguration['house_html'] = '<div>advanced house fallback</div>';
        $placementConfiguration['advanced_placement_flag'] = 'keep-placement';
        $demandPlacement->update([
            'remote_placement_id' => 'advanced-remote-placement',
            'placement_code' => 'advanced-placement-code',
            'configuration' => $placementConfiguration,
        ]);

        $this->adminSession()
            ->post(route('admin.demand.quick.store'), $this->payload($secondTag))
            ->assertRedirect();

        $demandSite->refresh();
        $demandPlacement->refresh();
        $this->assertSame('advanced-remote-site', $demandSite->remote_site_id);
        $this->assertSame('keep-site', data_get($demandSite->configuration, 'advanced_site_flag'));
        $this->assertTrue((bool) data_get($demandSite->configuration, 'quick_monetize_managed'));
        $this->assertSame('advanced-remote-placement', $demandPlacement->remote_placement_id);
        $this->assertSame('advanced-placement-code', $demandPlacement->placement_code);
        $this->assertSame('<div>advanced house fallback</div>', data_get($demandPlacement->configuration, 'house_html'));
        $this->assertSame('keep-placement', data_get($demandPlacement->configuration, 'advanced_placement_flag'));
        $this->assertTrue((bool) data_get($demandPlacement->configuration, 'quick_monetize_managed'));
    }

    public function test_legacy_custom_tag_generation_does_not_require_live_dns_resolution(): void
    {
        $network = DemandNetwork::query()->where('code', 'CUSTOM_THIRD_PARTY_TAG')->firstOrFail();
        $service = app(DemandAccountService::class);
        $origin = 'https://legacy-provider-nxdomain-987654321.net';
        $account = $service->create([
            'organization_id' => $this->admin->organization_id,
            'demand_network_id' => $network->id,
            'name' => 'Legacy DNS Compatibility',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'is_default' => false,
            'revenue_share_percent' => 10,
            'fallback_priority' => 20,
            'account_identifier' => 'legacy-no-dns',
            'configuration' => [
                'allowed_script_origins' => [$origin],
                'isolation_allowed_origins' => [$origin],
            ],
        ], $this->admin);
        $mapping = $service->assignSite($account, $this->site, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
        ], $this->admin);
        $demandPlacement = $service->assignPlacement($mapping, $this->placement, [
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'placement_code' => 'legacy-no-dns-zone',
        ], $this->admin);
        $service->upsertWidget($demandPlacement, [
            'name' => 'Legacy no DNS widget',
            'widget_code' => 'legacy-no-dns-zone',
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'direct_tag_template' => '<div id="legacy-no-dns-zone"></div><script src="'.$origin.'/ad.js"></script>',
            'configuration' => [],
        ], $this->admin);
        $this->site->update(['native_demand_enabled' => true]);

        $configuration = app(DemandConfigurationBuilder::class)->build($this->site->fresh());
        $candidate = data_get($configuration, 'placements.final_quick.candidates.0');

        $this->assertSame('ISOLATED_IFRAME', data_get($candidate, 'tag.executionMode'));
        $this->assertStringContainsString(
            'script-src \'unsafe-inline\' '.$origin.';',
            (string) data_get($candidate, 'tag.isolation.csp'),
        );
    }

    private function payload(string $tag): array
    {
        return [
            'site_id' => $this->site->id,
            'placement_id' => $this->placement->id,
            'tag' => $tag,
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function decodedPayload(array $attributes, string $baseAttribute): string
    {
        $encoded = (string) ($attributes[$baseAttribute] ?? '');
        if ($encoded === '') {
            $parts = (int) ($attributes[$baseAttribute.'-parts'] ?? 0);
            $this->assertGreaterThan(0, $parts, 'Expected a direct or chunked encoded payload.');

            for ($index = 0; $index < $parts; $index++) {
                $key = $baseAttribute.'-'.$index;
                $this->assertArrayHasKey($key, $attributes);
                $encoded .= (string) $attributes[$key];
            }
        }

        $decoded = base64_decode($encoded, true);
        $this->assertIsString($decoded);

        return $decoded;
    }

    private function directive(string $csp, string $name): string
    {
        foreach (explode(';', $csp) as $directive) {
            $directive = trim($directive);
            if (str_starts_with($directive, $name.' ')) {
                return $directive;
            }
        }

        return '';
    }

    private function adminSession(): static
    {
        $this->actingAs($this->admin);
        $this->withSession(['two_factor_passed_at' => now()->timestamp]);

        return $this;
    }
}
