<?php

namespace Tests\Feature;

use App\Enums\ConfigEnvironment;
use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\GamHealthStatus;
use App\Enums\MonetizationStatus;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandSite;
use App\Models\GamConnection;
use App\Models\Site;
use App\Services\Demand\ConfiguredDemandConnector;
use App\Services\Monetization\SiteMonetizationReadinessService;
use Database\Seeders\DemandNetworkSeeder;
use Database\Seeders\InventoryDeliverySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithGam;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PublisherMonetizationHealthTest extends TestCase
{
    use InteractsWithGam, InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $publisherUser;
    private $publisher;
    private $site;
    private $gam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed([InventoryDeliverySeeder::class, DemandNetworkSeeder::class]);

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser, ['display_name' => 'Publisher']);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'display_name' => 'Example Site',
            'primary_domain' => 'health.publisher.test',
        ]);
        $this->site->update(['status' => SiteStatus::Active]);
        $this->site->siteConfig()->update([
            'status' => 'ACTIVE',
            'privacy_settings' => ['mode' => 'AUTO'],
            'click_guard_settings' => ['enabled' => true],
        ]);
        $this->gam = $this->makeGamConnection($horus, $this->admin, [
            'network_code' => '123456789',
            'health_status' => GamHealthStatus::Healthy,
            'last_health_check_at' => now(),
        ]);
    }

    public function test_readiness_uses_controlled_statuses_and_optional_modules_do_not_break_overall_health(): void
    {
        $this->site->update(['serving_mode' => ServingMode::HorusGam]);
        $this->site->servingSettings()->update(['serving_mode' => ServingMode::HorusGam]);
        $this->site->gamAssignments()->create([
            'gam_connection_id' => $this->gam->id,
            'assigned_by' => $this->admin->id,
        ]);

        $result = app(SiteMonetizationReadinessService::class)->admin($this->site->fresh());
        $display = collect($result['modules'])->firstWhere('key', 'display');
        $this->assertSame(MonetizationStatus::Active->value, $display['status']);
        $this->assertSame(MonetizationStatus::Active->value, $result['overall']['status']);
        $this->assertSame('NOT_CONFIGURED', collect($result['modules'])->firstWhere('key', 'prebid')['status']);
    }

    public function test_direct_native_only_makes_native_critical_and_paused_serving_is_never_healthy(): void
    {
        $this->site->update([
            'serving_mode' => ServingMode::DirectNativeOnly,
            'native_demand_enabled' => false,
        ]);
        $this->site->servingSettings()->update([
            'serving_mode' => ServingMode::DirectNativeOnly,
            'native_demand_enabled' => false,
        ]);

        $result = app(SiteMonetizationReadinessService::class)->admin($this->site->fresh());
        $native = collect($result['modules'])->firstWhere('key', 'native');
        $this->assertSame('CRITICAL', $native['dependency']);
        $this->assertSame('ACTION_REQUIRED', $native['status']);
        $this->assertSame('ACTION_REQUIRED', $result['overall']['status']);

        $this->site->update(['serving_mode' => ServingMode::Paused]);
        $this->site->servingSettings()->update(['serving_mode' => ServingMode::Paused]);
        $paused = app(SiteMonetizationReadinessService::class)->admin($this->site->fresh());
        $this->assertSame('PAUSED', $paused['overall']['status']);
    }

    public function test_native_provider_is_white_labeled_for_publisher_but_visible_to_admin(): void
    {
        $this->site->update(['native_demand_enabled' => true]);
        $network = DemandNetwork::create([
            'code' => 'CUSTOM_NATIVE',
            'name' => 'INTERNAL PROVIDER ALPHA',
            'connector_class' => ConfiguredDemandConnector::class,
            'default_integration_mode' => DemandIntegrationMode::DirectJs,
            'is_enabled' => true,
        ]);
        $account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $this->admin->organization_id,
            'demand_network_id' => $network->id,
            'publisher_id' => $this->publisher->id,
            'name' => 'INTERNAL ACCOUNT BETA',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'revenue_share_percent' => 19.321,
            'account_identifier' => 'provider-account-SECRET-7788',
            'last_successful_sync_at' => now(),
        ]);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $this->site->id,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'last_synced_at' => now(),
        ]);

        $service = app(SiteMonetizationReadinessService::class);
        $publisher = json_encode($service->publisher($this->site->fresh()), JSON_THROW_ON_ERROR);
        $admin = json_encode($service->admin($this->site->fresh()), JSON_THROW_ON_ERROR);

        $this->assertStringContainsString('Direct Monetization', $publisher);
        $this->assertStringNotContainsString('INTERNAL PROVIDER ALPHA', $publisher);
        $this->assertStringNotContainsString('INTERNAL ACCOUNT BETA', $publisher);
        $this->assertStringNotContainsString($account->id, $publisher);
        $this->assertStringNotContainsString('19.321', $publisher);
        $this->assertStringNotContainsString('provider-account-SECRET-7788', $publisher);
        $this->assertStringContainsString('INTERNAL PROVIDER ALPHA', $admin);
        $this->assertStringContainsString('INTERNAL ACCOUNT BETA', $admin);
        $this->assertStringNotContainsString('provider-account-SECRET-7788', $admin);
    }

    public function test_publisher_html_minimizes_internal_data_while_admin_site_360_shows_technical_truth(): void
    {
        $this->site->update(['native_demand_enabled' => true]);
        $network = DemandNetwork::create([
            'code' => 'CUSTOM_NATIVE',
            'name' => 'WHITE LABEL INTERNAL PROVIDER',
            'connector_class' => ConfiguredDemandConnector::class,
            'default_integration_mode' => DemandIntegrationMode::DirectJs,
            'is_enabled' => true,
        ]);
        $account = DemandAccount::withoutGlobalScopes()->create([
            'organization_id' => $this->admin->organization_id,
            'demand_network_id' => $network->id,
            'publisher_id' => $this->publisher->id,
            'name' => 'WHITE LABEL INTERNAL ACCOUNT',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'revenue_share_percent' => 17.777,
            'account_identifier' => 'provider-account-SECRET-9911',
            'last_successful_sync_at' => now(),
        ]);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $this->site->id,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'last_synced_at' => now(),
        ]);

        $this->actingAs($this->publisherUser)->get(route('publisher.sites.show', $this->site))
            ->assertOk()
            ->assertDontSee('WHITE LABEL INTERNAL PROVIDER')
            ->assertDontSee('WHITE LABEL INTERNAL ACCOUNT')
            ->assertDontSee('provider-account-SECRET-9911')
            ->assertDontSee('17.777');

        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.show', $this->site))
            ->assertOk()
            ->assertSee('WHITE LABEL INTERNAL PROVIDER')
            ->assertSee('WHITE LABEL INTERNAL ACCOUNT')
            ->assertDontSee('provider-account-SECRET-9911');
    }
}
