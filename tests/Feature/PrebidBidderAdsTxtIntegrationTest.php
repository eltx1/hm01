<?php

namespace Tests\Feature;

use App\Enums\AdsTxtComplianceStatus;
use App\Enums\BidderAdsTxtRequirement;
use App\Enums\BidderSellersJsonStatus;
use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\SupplyChainReviewStatus;
use App\Models\BidderAccount;
use App\Models\BidderAdsTxtRecord;
use App\Models\PrebidBidder;
use App\Services\Compliance\AdsTxtComplianceService;
use App\Services\Network\Contracts\DnsResolver;
use App\Services\Prebid\BidderAdsTxtService;
use App\Services\Prebid\BidderSellersJsonVerifier;
use App\Services\Prebid\PrebidManager;
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Database\Seeders\PrebidSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithIdentity;
use Tests\Concerns\InteractsWithPublisherSites;
use Tests\TestCase;

class PrebidBidderAdsTxtIntegrationTest extends TestCase
{
    use InteractsWithIdentity, InteractsWithPublisherSites, RefreshDatabase;

    private $admin;
    private $site;
    private $account;
    private $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        $this->seed(PrebidSeeder::class);
        $horus = $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media');
        $this->admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $publisherUser = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Publisher Org'), RoleName::PublisherAdmin);
        $publisher = $this->makePublisherFor($publisherUser, ['business_domain' => 'publisher-owner.example']);
        $this->site = $this->makeSiteFor($publisher, $publisherUser, ['primary_domain' => 'publisher-site.example']);
        $bidder = PrebidBidder::withoutGlobalScopes()->where('code', 'msft')->firstOrFail();
        $this->manager = app(PrebidManager::class);
        $this->account = $this->manager->addAccount($bidder, ['name' => 'Primary bidder', 'enabled' => true], $this->admin);
        $this->manager->assignToSite($this->account, $this->site, ['enabled' => true], $this->admin);
        $this->publicDns();
    }

    public function test_mapped_required_bidder_valid_direct_record_enters_canonical_ads_txt(): void
    {
        $service = app(BidderAdsTxtService::class);
        $service->updateRequirement($this->account, ['ads_txt_requirement' => BidderAdsTxtRequirement::Required, 'ads_txt_evidence_url' => 'https://exchange.example/docs'], $this->admin);
        $record = $this->record($this->account, null, 'DIRECT');

        $canonical = app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site);
        $this->assertContains('exchange.example, seller-100, DIRECT, abc123', $canonical['lines']);
        $this->assertSame(0, $service->readinessForSite($this->site)['required_missing']);
        $this->assertSame('PREBID_BIDDER_MANUAL', collect(app(AdsTxtComplianceService::class)->canonical($this->site)['records'])->firstWhere('id', $record->id)['source']);
    }

    public function test_required_missing_bidder_record_is_action_required_in_ads_txt_readiness(): void
    {
        app(BidderAdsTxtService::class)->updateRequirement($this->account, ['ads_txt_requirement' => BidderAdsTxtRequirement::Required], $this->admin);

        $summary = app(AdsTxtComplianceService::class)->summary($this->site);
        $this->assertSame(AdsTxtComplianceStatus::Missing->value, $summary['status']);
        $this->assertSame(1, $summary['required_count']);
        $this->assertSame(1, $summary['missing_count']);
        $this->assertStringContainsString('Prebid bidder', $summary['action']);
    }

    public function test_global_record_applies_only_to_mapped_sites_and_site_specific_record_does_not_leak(): void
    {
        $publisherUser = $this->site->publisher->organization->users()->firstOrFail();
        $mapped = $this->makeSiteFor($this->site->publisher, $publisherUser, ['primary_domain' => 'mapped.example']);
        $unmapped = $this->makeSiteFor($this->site->publisher, $publisherUser, ['primary_domain' => 'unmapped.example']);
        $this->manager->assignToSite($this->account, $mapped, ['enabled' => true], $this->admin);
        $global = $this->record($this->account, null, 'DIRECT', 'global-1');
        $specific = $this->record($this->account, $this->site, 'DIRECT', 'site-only');
        $contract = app(SupplyChainStandardsContract::class);

        $this->assertContains($global->raw_record, $contract->adsTxtForSite($mapped)['lines']);
        $this->assertNotContains($specific->raw_record, $contract->adsTxtForSite($mapped)['lines']);
        $this->assertContains($specific->raw_record, $contract->adsTxtForSite($this->site)['lines']);
        $this->assertNotContains($global->raw_record, $contract->adsTxtForSite($unmapped)['lines']);
        $this->assertNotContains($specific->raw_record, $contract->adsTxtForSite($unmapped)['lines']);
    }

    public function test_reseller_is_explicit_and_exact_duplicates_collapse(): void
    {
        $first = $this->record($this->account, null, 'RESELLER', 'shared-seller');
        $second = $this->secondAccount('Second bidder');
        $duplicate = $this->record($second, null, 'RESELLER', 'shared-seller');

        $canonical = app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site);
        $this->assertSame(1, collect($canonical['lines'])->filter(fn ($line) => $line === $first->raw_record)->count());
        $this->assertSame($first->raw_record, $duplicate->raw_record);
        $this->assertTrue(collect($canonical['findings'])->contains(fn ($finding) => ($finding['code'] ?? null) === 'DUPLICATE_ADS_TXT_RECORD'));
    }

    public function test_conflicting_relationship_is_rejected_instead_of_silently_selected(): void
    {
        $direct = $this->record($this->account, null, 'DIRECT', 'conflict-seller');
        $second = $this->secondAccount('Conflict bidder');
        $this->record($second, null, 'RESELLER', 'conflict-seller');

        $canonical = app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site);
        $this->assertNotContains($direct->raw_record, $canonical['lines']);
        $this->assertTrue(collect($canonical['findings'])->contains(fn ($finding) => ($finding['code'] ?? null) === 'ADS_TXT_RELATIONSHIP_CONFLICT'));
    }

    public function test_disabled_account_or_mapping_excludes_bidder_record(): void
    {
        $record = $this->record($this->account);
        $this->account->update(['enabled' => false]);
        $this->assertNotContains($record->raw_record, app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site)['lines']);

        $this->account->update(['enabled' => true]);
        $this->account->siteMappings()->where('site_id', $this->site->id)->update(['enabled' => false]);
        $this->assertNotContains($record->raw_record, app(SupplyChainStandardsContract::class)->adsTxtForSite($this->site)['lines']);
    }

    public function test_remote_sellers_json_verification_distinguishes_present_absent_private_and_timeout(): void
    {
        $record = $this->record($this->account);
        $verifier = app(BidderSellersJsonVerifier::class);

        Http::fake(['https://exchange.example/sellers.json' => Http::response(['sellers' => [['seller_id' => 'seller-100', 'seller_type' => 'INTERMEDIARY']]], 200)]);
        $verified = $verifier->verify($record, $this->admin);
        $this->assertSame(BidderSellersJsonStatus::Verified, $verified->remote_verification_status);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://exchange.example/sellers.json');

        Http::fake(['*' => Http::response(['sellers' => [['seller_id' => 'someone-else']]], 200)]);
        $absent = $verifier->verify($record->refresh(), $this->admin);
        $this->assertSame(BidderSellersJsonStatus::Conflict, $absent->remote_verification_status);
        $this->assertSame('SELLER_ID_ABSENT', $absent->remote_error_code);

        Http::fake();
        $this->app->instance(DnsResolver::class, new class implements DnsResolver {
            public function addresses(string $host): array { return ['127.0.0.1', '169.254.169.254']; }
        });
        $private = app(BidderSellersJsonVerifier::class)->verify($record->refresh(), $this->admin);
        $this->assertSame(BidderSellersJsonStatus::Unreachable, $private->remote_verification_status);
        $this->assertSame('UNSAFE_OR_INVALID_TARGET', $private->remote_error_code);
        Http::assertNothingSent();

        $this->publicDns();
        Http::fake(['*' => Http::failedConnection()]);
        $timeout = app(BidderSellersJsonVerifier::class)->verify($record->refresh(), $this->admin);
        $this->assertSame(BidderSellersJsonStatus::Unreachable, $timeout->remote_verification_status);
        $this->assertSame('CONNECTION_FAILED', $timeout->remote_error_code);
    }

    public function test_admin_surface_exposes_requirement_records_relationship_and_remote_actions(): void
    {
        app(BidderAdsTxtService::class)->updateRequirement($this->account, ['ads_txt_requirement' => BidderAdsTxtRequirement::Required], $this->admin);
        $this->record($this->account, null, 'DIRECT');

        $this->actingAs($this->admin)->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.prebid.ads-txt.index', $this->site))
            ->assertOk()->assertSee('Authorized seller governance')->assertSee('DIRECT')->assertSee('Verify sellers.json')->assertSee('REQUIRED');
    }

    private function record(BidderAccount $account, $site = null, string $relationship = 'DIRECT', string $sellerId = 'seller-100'): BidderAdsTxtRecord
    {
        $record = app(BidderAdsTxtService::class)->create($account, $site, [
            'advertising_system_domain' => 'exchange.example',
            'publisher_account_id' => $sellerId,
            'relationship' => $relationship,
            'certification_authority_id' => 'abc123',
        ], $this->admin);

        return app(BidderAdsTxtService::class)->review($record, SupplyChainReviewStatus::Verified, $this->admin);
    }

    private function secondAccount(string $name): BidderAccount
    {
        $bidder = PrebidBidder::withoutGlobalScopes()->where('code', 'msft')->firstOrFail();
        $account = $this->manager->addAccount($bidder, ['name' => $name, 'enabled' => true], $this->admin);
        $this->manager->assignToSite($account, $this->site, ['enabled' => true], $this->admin);

        return $account;
    }

    private function publicDns(): void
    {
        $this->app->instance(DnsResolver::class, new class implements DnsResolver {
            public function addresses(string $host): array { return ['203.0.113.10']; }
        });
    }
}
