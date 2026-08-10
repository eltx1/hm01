<?php

namespace Tests\Feature;

use App\Enums\DemandAccountScope;
use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\GamHealthStatus;
use App\Enums\MonetizationStatus;
use App\Enums\OrganizationType;
use App\Enums\ReportConnectionStatus;
use App\Enums\RoleName;
use App\Enums\ServingMode;
use App\Enums\SiteStatus;
use App\Models\DailyReport;
use App\Models\DemandAccount;
use App\Models\DemandNetwork;
use App\Models\DemandSite;
use App\Models\GamConnection;
use App\Models\PrebidSetting;
use App\Models\ReportDimension;
use App\Models\ReportImportJob;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\Site;
use App\Models\SiteConfig;
use App\Models\SiteNote;
use App\Services\Demand\ConfiguredDemandConnector;
use App\Services\Monetization\SiteMonetizationReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PublisherMonetizationHealthTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $publisherUser;
    private $publisher;
    private Site $site;
    private GamConnection $gam;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();

        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Publisher Health Org');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $this->publisher = $this->makePublisherFor($this->publisherUser);
        $this->site = $this->makeSiteFor($this->publisher, $this->publisherUser, [
            'primary_domain' => 'health.publisher.test',
            'default_revenue_share_percent' => 73.777,
        ]);
        $this->site->update([
            'status' => SiteStatus::Active,
            'serving_mode' => ServingMode::HorusGam,
        ]);
        SiteConfig::withoutGlobalScopes()->updateOrCreate(
            ['site_id' => $this->site->id],
            [
                'organization_id' => $this->site->organization_id,
                'status' => 'ACTIVE',
                'privacy_settings' => null,
                'click_guard_settings' => ['enabled' => true, 'maxClicks' => 5, 'windowHours' => 8, 'blockHours' => 24],
            ],
        );
        $this->site->unsetRelation('siteConfig');

        $this->gam = GamConnection::withoutGlobalScopes()->create([
            'organization_id' => $horus->id,
            'name' => 'Horus Primary Internal GAM',
            'type' => 'HORUS_GAM',
            'credential_type' => 'SERVICE_ACCOUNT',
            'driver' => 'GOOGLE_AD_MANAGER',
            'network_code' => '123456789',
            'is_primary' => true,
            'is_enabled' => true,
            'dry_run_default' => true,
            'health_status' => GamHealthStatus::Healthy,
            'last_health_check_at' => now(),
            'last_successful_sync_at' => now(),
        ]);
    }

    public function test_readiness_uses_controlled_statuses_and_optional_modules_do_not_break_active_site(): void
    {
        $this->site->update(['prebid_enabled' => true]);
        $result = app(SiteMonetizationReadinessService::class)->publisher($this->site->fresh());
        $modules = collect($result['modules'])->keyBy('key');

        $this->assertSame(MonetizationStatus::Active->value, $modules['display']['status']);
        $this->assertSame('ACTION_REQUIRED', $modules['prebid']['status']);
        $this->assertSame('OPTIONAL', $modules['prebid']['dependency']);
        $this->assertSame(MonetizationStatus::Active->value, $result['overall']['status']);
        $this->assertSame(MonetizationStatus::Active->value, $modules['click_guard']['status']);
        $this->assertSame(MonetizationStatus::Ready->value, $modules['privacy']['status']);
        $this->assertStringContainsString('not a legal certification', $modules['privacy']['reason']);
    }

    public function test_direct_native_only_makes_native_critical_and_paused_serving_is_never_healthy(): void
    {
        $this->site->update(['serving_mode' => ServingMode::DirectNativeOnly, 'native_demand_enabled' => false]);
        $result = app(SiteMonetizationReadinessService::class)->publisher($this->site->fresh());
        $native = collect($result['modules'])->firstWhere('key', 'native');
        $this->assertSame('CRITICAL', $native['dependency']);
        $this->assertSame(MonetizationStatus::ActionRequired->value, $native['status']);
        $this->assertSame(MonetizationStatus::ActionRequired->value, $result['overall']['status']);

        $this->site->update(['serving_mode' => ServingMode::Paused]);
        $paused = app(SiteMonetizationReadinessService::class)->publisher($this->site->fresh());
        $this->assertSame(MonetizationStatus::Paused->value, $paused['overall']['status']);
    }

    public function test_native_provider_is_white_labeled_for_publisher_but_visible_to_admin_without_credentials(): void
    {
        $this->site->update(['native_demand_enabled' => true]);
        $network = DemandNetwork::create([
            'code' => 'CUSTOM_NATIVE',
            'name' => 'INTERNAL PROVIDER ALPHA',
            'connector_class' => ConfiguredDemandConnector::class,
            'default_integration_mode' => DemandIntegrationMode::DirectJs,
            'is_enabled' => true,
            'supports_direct_js' => true,
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

        $this->assertStringContainsString('Native Network', $publisher);
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
            'name' => 'WHITE LABEL ACCOUNT',
            'scope' => DemandAccountScope::HorusMedia,
            'integration_mode' => DemandIntegrationMode::DirectJs,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'revenue_share_percent' => 18.888,
            'account_identifier' => 'DO-NOT-LEAK-ACCOUNT-ID',
        ]);
        DemandSite::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'demand_account_id' => $account->id,
            'site_id' => $this->site->id,
            'approval_status' => DemandApprovalStatus::Approved,
            'is_enabled' => true,
            'integration_mode' => DemandIntegrationMode::DirectJs,
        ]);
        SiteNote::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'site_id' => $this->site->id,
            'author_id' => $this->admin->id,
            'note' => 'INTERNAL NOTE MUST NEVER LEAK',
        ]);

        $publisherResponse = $this->actingAs($this->publisherUser)
            ->get(route('publisher.sites.show', $this->site));
        $publisherResponse->assertOk()
            ->assertSee('Monetization health')
            ->assertSee('Native Network')
            ->assertSee($this->site->installationCode())
            ->assertDontSee('HORUS_GAM')
            ->assertDontSee('Horus Primary Internal GAM')
            ->assertDontSee('123456789')
            ->assertDontSee('WHITE LABEL INTERNAL PROVIDER')
            ->assertDontSee('WHITE LABEL ACCOUNT')
            ->assertDontSee($account->id)
            ->assertDontSee('DO-NOT-LEAK-ACCOUNT-ID')
            ->assertDontSee('73.777')
            ->assertDontSee('18.888')
            ->assertDontSee('INTERNAL NOTE MUST NEVER LEAK')
            ->assertDontSee('Admin diagnostics');

        $adminResponse = $this->actingAs($this->admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.sites.show', $this->site));
        $adminResponse->assertOk()
            ->assertSee('Site monetization health')
            ->assertSee('Horus Primary Internal GAM')
            ->assertSee('123456789')
            ->assertSee('WHITE LABEL INTERNAL PROVIDER')
            ->assertSee('WHITE LABEL ACCOUNT')
            ->assertSee('Admin diagnostics')
            ->assertDontSee('DO-NOT-LEAK-ACCOUNT-ID');
    }

    public function test_reporting_health_uses_persisted_freshness_and_does_not_call_external_apis(): void
    {
        $source = ReportSource::create([
            'code' => 'HORUS_GAM',
            'name' => 'Horus persisted reporting',
            'is_primary' => true,
            'is_enabled' => true,
        ]);
        $connection = ReportSourceConnection::withoutGlobalScopes()->create([
            'organization_id' => $this->admin->organization_id,
            'report_source_id' => $source->id,
            'name' => 'Internal reporting account',
            'connection_type' => 'GAM',
            'connection_id' => 'internal-reporting-identity',
            'account_identifier' => 'reporting-account-secretish',
            'status' => ReportConnectionStatus::Active,
            'is_enabled' => true,
            'last_successful_import_at' => now(),
        ]);
        $import = ReportImportJob::withoutGlobalScopes()->create([
            'organization_id' => $this->admin->organization_id,
            'report_source_connection_id' => $connection->id,
            'import_type' => 'DAILY',
            'granularity' => 'DAILY',
            'finality' => 'FINALIZED',
            'status' => 'COMPLETED',
            'period_start' => now()->subDay()->startOfDay(),
            'period_end' => now()->subDay()->endOfDay(),
            'idempotency_key' => hash('sha256', 'health-report-import'),
            'completed_at' => now(),
        ]);
        $dimension = ReportDimension::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'publisher_id' => $this->publisher->id,
            'site_id' => $this->site->id,
            'dimension_hash' => hash('sha256', 'health-site-dimension'),
        ]);
        DailyReport::withoutGlobalScopes()->create([
            'organization_id' => $this->site->organization_id,
            'report_source_connection_id' => $connection->id,
            'report_import_job_id' => $import->id,
            'report_dimension_id' => $dimension->id,
            'report_date' => today()->subDay(),
            'finality' => 'FINALIZED',
            'currency' => 'USD',
            'source_row_hash' => hash('sha256', 'health-row'),
        ]);

        Http::fake();
        $fresh = app(SiteMonetizationReadinessService::class)->publisher($this->site->fresh());
        $reporting = collect($fresh['modules'])->firstWhere('key', 'reporting');
        $this->assertSame(MonetizationStatus::Active->value, $reporting['status']);
        $this->assertStringNotContainsString('reporting-account-secretish', json_encode($fresh));
        Http::assertNothingSent();

        $connection->update(['last_successful_import_at' => now()->subDays(5)]);
        DailyReport::withoutGlobalScopes()->where('report_dimension_id', $dimension->id)->update(['report_date' => today()->subDays(5)]);
        $stale = app(SiteMonetizationReadinessService::class)->publisher($this->site->fresh());
        $this->assertSame(MonetizationStatus::Degraded->value, collect($stale['modules'])->firstWhere('key', 'reporting')['status']);
        Http::assertNothingSent();
    }

    public function test_monetization_center_is_site_isolated_read_only_and_preserves_loader_contract(): void
    {
        $beforeConfigVersions = $this->site->configVersions()->count();
        $beforePrebidSettings = PrebidSetting::withoutGlobalScopes()->count();
        Http::fake();

        $response = $this->actingAs($this->publisherUser)->get(route('publisher.monetization.index'));
        $response->assertOk()
            ->assertSee('Monetization Center')
            ->assertSee('health.publisher.test');
        $this->assertSame($beforeConfigVersions, $this->site->configVersions()->count());
        $this->assertSame($beforePrebidSettings, PrebidSetting::withoutGlobalScopes()->count());
        Http::assertNothingSent();
        $this->assertStringContainsString('/hm-loader.js', $this->site->installationCode());
        $this->assertStringContainsString($this->site->public_key, $this->site->installationCode());

        $otherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Other Publisher Org');
        $otherUser = $this->makeUser($otherOrg, RoleName::PublisherAdmin);
        $this->actingAs($otherUser)->get(route('publisher.sites.show', $this->site))->assertNotFound();
        $this->actingAs($otherUser)->get(route('publisher.monetization.index'))
            ->assertOk()->assertDontSee('health.publisher.test');
    }
}