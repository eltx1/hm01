<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ContractStatus;
use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Enums\SellerDeclarationStatus;
use App\Enums\SellerIdentityScope;
use App\Enums\SupplyChainReviewStatus;
use App\Models\PublisherApplication;
use App\Models\PublisherContract;
use App\Models\SellerDeclaration;
use App\Models\User;
use App\Services\Network\Contracts\DnsResolver;
use App\Services\PublisherApplications\ApplicationAdsTxtVerificationService;
use App\Services\PublisherApplications\PublisherApplicationService;
use App\Services\Sites\SiteLifecycleService;
use App\Services\SupplyChain\HorusSellerIdentityService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainInvariantService;
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

final class FinalLaunchReadinessContractTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        Config::set('publisher-applications.public_registration_enabled', true);
        Config::set('publisher-applications.turnstile.enabled', false);
        Config::set('supply-chain.manager_domain', 'horusmedia.net');
        $this->app->instance(DnsResolver::class, new class implements DnsResolver {
            public function addresses(string $host): array { return ['93.184.216.34']; }
        });
    }

    public function test_one_publisher_two_websites_share_hmp_but_have_distinct_hms_ads_txt_sellers_json_and_schain(): void
    {
        [$application, $user] = $this->application();
        $verification = app(ApplicationAdsTxtVerificationService::class);
        $reserved = $verification->reserve($application, $user);
        Http::fake(['*' => Http::response(implode("\n", $reserved['records'])."\n", 200, ['Content-Type' => 'text/plain'])]);
        $this->assertTrue($verification->verify($application->fresh(), $user)['verified']);

        $application->update(['status' => PublisherApplicationStatus::Submitted, 'submitted_at' => now(), 'current_revision' => 1]);
        $application->update(['status' => PublisherApplicationStatus::UnderReview, 'review_started_at' => now()]);
        $admin = $this->admin();
        $application->publisher->update(['status' => AccountStatus::Active]);
        $application->organization->update(['status' => AccountStatus::Active]);
        $application->update([
            'status' => PublisherApplicationStatus::Approved,
            'approved_at' => now(),
            'reviewed_at' => now(),
            'reviewed_by' => $admin->id,
        ]);

        $sites = app(SiteLifecycleService::class);
        $siteA = $sites->create($this->sitePayload($application, $application->primary_domain), $user);
        $siteB = $sites->create($this->sitePayload($application, 'second-'.$application->primary_domain), $user);
        $identities = app(HorusSellerIdentityService::class);
        $hmp = $identities->managedForPublisher($application->publisher);
        $hmsA = $identities->managedForSite($siteA);
        $hmsB = $identities->managedForSite($siteB);

        $this->assertSame($reserved['publisher_seller']->seller_id, $hmp->seller_id);
        $this->assertSame($reserved['website_seller']->seller_id, $hmsA->seller_id);
        $this->assertNotSame($hmsA->seller_id, $hmsB->seller_id);
        $this->assertSame(1, SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $application->publisher_id)
            ->where('identity_scope', SellerIdentityScope::Publisher->value)->count());
        $this->assertSame(2, SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $application->publisher_id)
            ->where('identity_scope', SellerIdentityScope::Website->value)->count());

        $this->activate($application, $hmp, [$hmsA, $hmsB], $admin);

        $artifact = app(SupplyChainArtifactBuilder::class);
        $adsA = $artifact->adsTxtForSite($siteA);
        $adsB = $artifact->adsTxtForSite($siteB);
        $hmpLine = 'horusmedia.net, '.$hmp->seller_id.', DIRECT';
        $hmsALine = 'horusmedia.net, '.$hmsA->seller_id.', DIRECT';
        $hmsBLine = 'horusmedia.net, '.$hmsB->seller_id.', DIRECT';
        $this->assertStringContainsString($hmpLine, $adsA);
        $this->assertStringContainsString($hmsALine, $adsA);
        $this->assertStringNotContainsString($hmsBLine, $adsA);
        $this->assertStringContainsString($hmpLine, $adsB);
        $this->assertStringContainsString($hmsBLine, $adsB);
        $this->assertStringNotContainsString($hmsALine, $adsB);

        $sellers = collect($artifact->sellersJsonPayload()['sellers'])->keyBy('seller_id');
        foreach ([$hmp->seller_id, $hmsA->seller_id, $hmsB->seller_id] as $sellerId) {
            $this->assertTrue($sellers->has($sellerId));
            $this->assertSame('PUBLISHER', $sellers[$sellerId]['seller_type']);
            $this->assertSame($application->publisher->legal_name, $sellers[$sellerId]['name']);
            $this->assertSame($application->publisher->business_domain, $sellers[$sellerId]['domain']);
        }

        $contract = app(SupplyChainStandardsContract::class);
        $schainA = $contract->schainForSite($siteA);
        $schainB = $contract->schainForSite($siteB);
        $this->assertSame([['asi' => 'horusmedia.net', 'sid' => $hmsA->seller_id, 'hp' => 1]], $schainA['nodes']);
        $this->assertSame([['asi' => 'horusmedia.net', 'sid' => $hmsB->seller_id, 'hp' => 1]], $schainB['nodes']);
        $this->assertNotContains($hmp->seller_id, collect($schainA['nodes'])->pluck('sid')->all());
        $this->assertNotContains($hmp->seller_id, collect($schainB['nodes'])->pluck('sid')->all());
    }

    /** @return array{0: PublisherApplication, 1: User} */
    private function application(): array
    {
        $application = app(PublisherApplicationService::class)->register([
            'name' => 'Final Audit Publisher Owner',
            'email' => 'owner@final-audit.example',
            'publisher_name' => 'Final Audit Publisher',
            'primary_domain' => 'final-audit.example',
            'password' => 'Secure-Password-2026!',
        ]);
        $user = $application->applicant;
        $user->forceFill(['email_verified_at' => now()])->save();
        app(PublisherApplicationService::class)->emailVerified($user);
        $application->refresh()->load(['publisher', 'applicant', 'organization', 'domainClaim']);

        return [$application, $user];
    }

    /** @return array<string, mixed> */
    private function sitePayload(PublisherApplication $application, string $domain): array
    {
        return [
            'organization_id' => $application->organization_id,
            'publisher_id' => $application->publisher_id,
            'display_name' => 'Site '.$domain,
            'primary_domain' => $domain,
            'language' => 'en',
            'content_category' => 'News',
            'country' => 'US',
            'main_traffic_countries' => ['US'],
            'estimated_monthly_pageviews' => 100000,
            'estimated_monthly_users' => 50000,
            'current_monetization_providers' => [],
            'prebid_enabled' => false,
            'native_demand_enabled' => false,
            'default_revenue_share_percent' => 70,
        ];
    }

    /** @param list<SellerDeclaration> $websiteSellers */
    private function activate(PublisherApplication $application, SellerDeclaration $hmp, array $websiteSellers, User $admin): void
    {
        PublisherContract::withoutGlobalScopes()->create([
            'organization_id' => $application->organization_id,
            'publisher_id' => $application->publisher_id,
            'contract_reference' => 'TASK42-FINAL',
            'status' => ContractStatus::Active,
            'starts_at' => now()->subDay(),
            'revenue_share_percent' => 70,
            'currency' => 'USD',
            'payment_terms' => 'NET_30',
            'created_by' => $admin->id,
        ]);
        $invariants = app(SupplyChainInvariantService::class);
        $identities = app(HorusSellerIdentityService::class);
        $invariants->reviewPublisherIdentity($application->publisher->fresh(), SupplyChainReviewStatus::Verified, $admin);

        foreach (array_merge([$hmp], $websiteSellers) as $seller) {
            $identities->reviewConfidentiality($seller->fresh(), false, $admin);
            $invariants->reviewSellerDeclaration($seller->fresh(), SupplyChainReviewStatus::Verified, $admin);
            $invariants->changeSellerStatus($seller->fresh(), SellerDeclarationStatus::Active, $admin);
        }
    }

    private function admin(): User
    {
        return $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'), RoleName::SuperAdmin);
    }
}
