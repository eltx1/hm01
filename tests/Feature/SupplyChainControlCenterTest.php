<?php

namespace Tests\Feature;

use App\Enums\AdsTxtDeploymentMode;
use App\Enums\BidderAdsTxtRequirement;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SiteStatus;
use App\Enums\SupplyChainReviewStatus;
use App\Models\AuditLog;
use App\Models\PlatformAdsTxtRecord;
use App\Models\PrebidBidder;
use App\Services\Compliance\AdsTxtVerifier;
use App\Services\Network\Contracts\DnsResolver;
use App\Services\Prebid\BidderAdsTxtService;
use App\Services\Prebid\PrebidManager;
use App\Services\SupplyChain\PlatformAdsTxtService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class SupplyChainControlCenterTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $publisherUser;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Task 38 Publisher');
        $this->publisherUser = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($this->publisherUser, [
            'display_name' => 'Task 38 Publisher',
            'business_domain' => 'task38-owner.example',
        ]);
        $this->site = $this->makeSiteFor($publisher, $this->publisherUser, [
            'display_name' => 'Task 38 Site',
            'primary_domain' => 'task38-site.example',
        ]);
        $this->site->domains()->where('is_primary', true)->update([
            'verification_status' => 'VERIFIED',
            'verified_at' => now(),
        ]);
        $this->publicDns();
    }

    public function test_admin_control_center_has_required_information_architecture_rbac_and_mobile_shell(): void
    {
        $response = $this->adminRequest()->get(route('admin.compliance.supply-chain.overview'));

        $response->assertOk()
            ->assertSee('Supply Chain Control Center')
            ->assertSee('Overview')
            ->assertSee('Master Ads.txt')
            ->assertSee('Horus Sellers')
            ->assertSee('Bidder Authorizations')
            ->assertSee('Direct Demand Authorizations')
            ->assertSee('Websites')
            ->assertSee('sellers.json')
            ->assertSee('Verification / Findings')
            ->assertSee('name="viewport"', false)
            ->assertSee('table-wrap', false);

        $this->actingAs($this->publisherUser)
            ->get(route('admin.compliance.supply-chain.overview'))
            ->assertForbidden();
    }

    public function test_site_detail_exposes_canonical_live_diff_provenance_and_download_without_manual_file_inspection(): void
    {
        $canonical = app(SupplyChainArtifactBuilder::class)->adsTxtForSite($this->site);
        Http::fake(['https://task38-site.example/ads.txt' => Http::response($canonical, 200, ['Content-Type' => 'text/plain; charset=utf-8'])]);
        app(AdsTxtVerifier::class)->verify($this->site, 'ADMIN', $this->admin);

        $this->adminRequest()->get(route('admin.compliance.supply-chain.site', $this->site))
            ->assertOk()
            ->assertSee('OWNERDOMAIN')
            ->assertSee('task38-owner.example')
            ->assertSee('Horus Seller ID')
            ->assertSee('Canonical ads.txt')
            ->assertSee('Live ads.txt')
            ->assertSee('Missing lines')
            ->assertSee('Extra lines')
            ->assertSee('Conflicts / invalid')
            ->assertSee('Master records')
            ->assertSee('Bidder records')
            ->assertSee('Demand records')
            ->assertSee('sellers.json and schain')
            ->assertSee('Why is this here?')
            ->assertSee('PUBLISHER_IDENTITY');

        $this->adminRequest()->get(route('admin.compliance.ads-txt.download', $this->site))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee($canonical, false);
    }

    public function test_publisher_surface_is_cross_tenant_safe_and_shows_managed_delegation_without_internal_account_details(): void
    {
        $settings = $this->site->servingSettings()->firstOrFail();
        $settings->update([
            'ads_txt_deployment_mode' => AdsTxtDeploymentMode::ManagedRedirectDelegation,
            'ads_txt_redirect_status' => 'VERIFIED',
            'ads_txt_redirect_verified_at' => now(),
        ]);

        $otherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Other Publisher');
        $otherUser = $this->makeUser($otherOrganization, RoleName::PublisherAdmin);
        $otherPublisher = $this->makePublisherFor($otherUser, ['business_domain' => 'other-owner.example']);
        $this->makeSiteFor($otherPublisher, $otherUser, ['primary_domain' => 'other-private.example']);

        $this->seed(PrebidSeeder::class);
        $bidder = PrebidBidder::withoutGlobalScopes()->firstOrFail();
        $account = app(PrebidManager::class)->addAccount($bidder, ['name' => 'INTERNAL COMMERCIAL BIDDER SECRET', 'enabled' => true], $this->admin);
        app(PrebidManager::class)->assignToSite($account, $this->site, ['enabled' => true], $this->admin);

        $this->actingAs($this->publisherUser)->get(route('publisher.ads-txt.index'))
            ->assertOk()
            ->assertSee('MANAGED_REDIRECT_DELEGATION')
            ->assertSee('Managed canonical target')
            ->assertSee('VERIFIED')
            ->assertDontSee('other-private.example')
            ->assertDontSee('INTERNAL COMMERCIAL BIDDER SECRET')
            ->assertDontSee('Horus Media platform master authorization');
    }

    public function test_bidder_authorization_findings_are_engine_aware_and_unused_bidder_does_not_block_ads_txt(): void
    {
        $this->seed(PrebidSeeder::class);
        $bidder = PrebidBidder::withoutGlobalScopes()->firstOrFail();
        $account = app(PrebidManager::class)->addAccount($bidder, ['name' => 'Required Bidder', 'enabled' => true], $this->admin);
        $account->update(['ads_txt_requirement' => BidderAdsTxtRequirement::Required]);
        app(PrebidManager::class)->assignToSite($account, $this->site, ['enabled' => true], $this->admin);

        $service = app(BidderAdsTxtService::class);
        $off = $service->readinessForSite($this->site->refresh());
        $this->assertSame(0, $off['required']);
        $this->assertSame(0, $off['required_missing']);
        $this->assertSame([], $off['findings']);

        $this->site->update(['prebid_enabled' => true]);
        $this->site->servingSettings()->update(['prebid_enabled' => true]);
        $on = $service->readinessForSite($this->site->refresh());
        $this->assertSame(1, $on['required']);
        $this->assertSame(1, $on['required_missing']);
        $this->assertSame('BIDDER_ADS_TXT_REQUIRED_MISSING', $on['findings'][0]['code']);
    }

    public function test_master_impact_preview_requires_password_typed_confirmation_and_audits_enablement(): void
    {
        $this->site->update(['status' => SiteStatus::Active]);
        $service = app(PlatformAdsTxtService::class);
        $record = $service->create([
            'advertising_system_domain' => 'master-task38.example',
            'publisher_account_id' => 'seat-38',
            'relationship' => 'DIRECT',
        ], $this->admin);
        $service->review($record, SupplyChainReviewStatus::Verified, $this->admin);

        $this->adminRequest()->get(route('admin.compliance.ads-txt.master.index'))
            ->assertOk()
            ->assertSee('This record will appear on 1 eligible websites.')
            ->assertSee('ENABLE 1 SITES');

        $this->adminRequest()->post(route('admin.compliance.ads-txt.master.enable', $record), [
            'reason' => 'Task 38 controlled platform enablement.',
            'current_password' => 'password',
            'impact_confirmation' => 'WRONG',
        ])->assertSessionHasErrors('impact_confirmation');
        $this->assertSame('DISABLED', PlatformAdsTxtRecord::findOrFail($record->id)->status);

        $this->adminRequest()->post(route('admin.compliance.ads-txt.master.enable', $record), [
            'reason' => 'Task 38 controlled platform enablement.',
            'current_password' => 'password',
            'impact_confirmation' => 'ENABLE 1 SITES',
        ])->assertSessionHasNoErrors();

        $this->assertSame('ACTIVE', PlatformAdsTxtRecord::findOrFail($record->id)->status);
        $audit = AuditLog::query()->where('event', 'supply_chain.platform_ads_txt.high_impact_confirmed')->latest()->firstOrFail();
        $this->assertSame('ENABLE', $audit->new_values['action']);
        $this->assertSame(1, $audit->new_values['impact_count']);
    }

    private function adminRequest()
    {
        return $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp]);
    }

    private function publicDns(): void
    {
        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function addresses(string $host): array
            {
                return ['93.184.216.34'];
            }
        });
    }
}
