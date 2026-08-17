<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\ContractStatus;
use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Enums\SellerDeclarationStatus;
use App\Enums\SellerIdentityScope;
use App\Enums\SellerIdentitySource;
use App\Enums\SupplyChainReviewStatus;
use App\Models\PublisherApplication;
use App\Models\PublisherContract;
use App\Models\SellerDeclaration;
use App\Models\User;
use App\Services\Compliance\AdsTxtFetcher;
use App\Services\Network\Contracts\DnsResolver;
use App\Services\PublisherApplications\ApplicationAdsTxtVerificationService;
use App\Services\PublisherApplications\PublisherApplicationService;
use App\Services\Sites\SiteLifecycleService;
use App\Services\SupplyChain\HorusSellerIdentityService;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use App\Services\SupplyChain\SupplyChainInvariantService;
use App\Services\SupplyChain\SupplyChainStandardsContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class DualHorusSellerIdentityTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        Config::set('publisher-applications.public_registration_enabled', true);
        Config::set('publisher-applications.turnstile.enabled', false);
        Config::set('supply-chain.manager_domain', 'horusmedia.net');
        $this->publicDns();
    }

    public function test_application_reserves_one_real_hmp_and_one_real_hms_without_creating_site(): void
    {
        [$application, $user] = $this->application();
        $service = app(ApplicationAdsTxtVerificationService::class);
        $first = $service->reserve($application, $user);
        $second = $service->reserve($application->fresh(), $user);
        $hmp = $first['publisher_seller'];
        $hms = $first['website_seller'];
        $claim = $first['claim'];

        $this->assertSame($hmp->id, $second['publisher_seller']->id);
        $this->assertSame($hms->id, $second['website_seller']->id);
        $this->assertMatchesRegularExpression('/^HMP-[0-9A-HJKMNP-TV-Z]{26}$/', $hmp->seller_id);
        $this->assertMatchesRegularExpression('/^HMS-[0-9A-HJKMNP-TV-Z]{26}$/', $hms->seller_id);
        $this->assertLessThanOrEqual(64, strlen($hmp->seller_id));
        $this->assertLessThanOrEqual(64, strlen($hms->seller_id));
        $this->assertSame(SellerIdentitySource::HorusManaged, $hmp->identity_source);
        $this->assertSame(SellerIdentitySource::HorusManaged, $hms->identity_source);
        $this->assertSame(SellerIdentityScope::Publisher, $hmp->identity_scope);
        $this->assertSame(SellerIdentityScope::Website, $hms->identity_scope);
        $this->assertSame($application->publisher_id, $hmp->publisher_id);
        $this->assertSame($application->publisher_id, $hms->publisher_id);
        $this->assertNull($hmp->site_id);
        $this->assertNull($hms->site_id);
        $this->assertSame($claim->id, $hms->publisher_application_domain_claim_id);
        $this->assertSame(SellerDeclarationStatus::Disabled, $hmp->status);
        $this->assertSame(SellerDeclarationStatus::Disabled, $hms->status);
        $this->assertTrue($hmp->is_confidential);
        $this->assertTrue($hms->is_confidential);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('site_configs', 0);
        $this->assertSame([
            'horusmedia.net, '.$hmp->seller_id.', DIRECT',
            'horusmedia.net, '.$hms->seller_id.', DIRECT',
        ], $first['records']);

        $payload = app(SupplyChainArtifactBuilder::class)->sellersJsonPayload();
        $publicIds = collect($payload['sellers'])->pluck('seller_id');
        $this->assertFalse($publicIds->contains($hmp->seller_id));
        $this->assertFalse($publicIds->contains($hms->seller_id));
        $this->assertStringNotContainsString($user->email, json_encode($payload));
    }

    public function test_hmp_and_hms_are_unique_immutable_non_recyclable_and_never_reassigned(): void
    {
        [$application, $user] = $this->application();
        $first = app(ApplicationAdsTxtVerificationService::class)->reserve($application, $user);
        [$otherApplication, $otherUser] = $this->application('other.example', 'owner@other.example');
        $other = app(ApplicationAdsTxtVerificationService::class)->reserve($otherApplication, $otherUser);

        $this->assertNotSame($first['publisher_seller']->seller_id, $other['publisher_seller']->seller_id);
        $this->assertNotSame($first['website_seller']->seller_id, $other['website_seller']->seller_id);

        foreach ([$first['publisher_seller'], $first['website_seller']] as $seller) {
            $id = $seller->seller_id;
            try {
                $seller->update(['seller_id' => str_starts_with($id, 'HMP-')
                    ? 'HMP-01ARZ3NDEKTSV4RRFFQ69G5FAV'
                    : 'HMS-01ARZ3NDEKTSV4RRFFQ69G5FAV']);
                $this->fail('Managed seller ID mutation must fail.');
            } catch (LogicException) {
                $this->assertSame($id, $seller->fresh()->seller_id);
            }
            try {
                $seller->fresh()->delete();
                $this->fail('Managed seller deletion must fail.');
            } catch (LogicException) {
                $this->assertDatabaseHas('seller_declarations', ['id' => $seller->id, 'seller_id' => $id]);
            }
        }
    }

    public function test_ads_txt_verification_requires_both_exact_direct_records_and_accepts_standard_whitespace(): void
    {
        [$application, $user] = $this->application();
        $service = app(ApplicationAdsTxtVerificationService::class);
        $reserved = $service->reserve($application, $user);
        $hmp = $reserved['publisher_seller']->seller_id;
        $hms = $reserved['website_seller']->seller_id;

        $cases = [
            ["horusmedia.net, {$hms}, DIRECT\n", 'PUBLISHER_HMP_AUTHORIZATION_MISSING'],
            ["horusmedia.net, {$hmp}, DIRECT\n", 'WEBSITE_HMS_AUTHORIZATION_MISSING'],
            ["horusmedia.net, {$hmp}, RESELLER\nhorusmedia.net, {$hms}, DIRECT\n", 'HORUS_RELATIONSHIP_MISMATCH'],
            ["horusmedia.net, HMP-01ARZ3NDEKTSV4RRFFQ69G5FAA, DIRECT\nhorusmedia.net, {$hms}, DIRECT\n", 'PUBLISHER_HMP_AUTHORIZATION_MISSING'],
            ["horusmedia.net, {$hmp}, DIRECT\nhorusmedia.net, HMS-01ARZ3NDEKTSV4RRFFQ69G5FAA, DIRECT\n", 'WEBSITE_HMS_AUTHORIZATION_MISSING'],
        ];
        $responses = array_map(fn (array $case): string => $case[0], $cases);
        $responses[] = " HORUSMEDIA.NET , {$hmp} , direct \n\thorusmedia.net, {$hms}, DIRECT # site account\n";
        Http::fake(function () use (&$responses) {
            return Http::response(array_shift($responses), 200, ['Content-Type' => 'text/plain; charset=utf-8']);
        });

        foreach ($cases as [, $expected]) {
            $result = $service->verify($application->fresh(), $user);
            $this->assertFalse($result['verified']);
            $this->assertSame($expected, $result['code']);
        }

        $result = $service->verify($application->fresh(), $user);
        $this->assertTrue($result['verified']);
        $claim = $application->fresh()->domainClaim()->firstOrFail();
        $this->assertSame('VERIFIED', $claim->verification_status);
        $this->assertNotNull($claim->verified_at);
        $this->assertSame(64, strlen((string) $claim->evidence_sha256));
        $this->assertSame(6, $claim->verification_attempt_count);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'publisher_application.domain_ads_txt_verification_attempted',
            'auditable_id' => $claim->id,
        ]);
        $this->assertSame(AccountStatus::Pending, $application->publisher->fresh()->status);
        $this->assertSame(AccountStatus::Pending, $application->organization->fresh()->status);
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_application_ads_txt_fetch_follows_one_external_delegation_and_blocks_unsafe_chains_and_private_ips(): void
    {
        [$application, $user] = $this->application();
        $service = app(ApplicationAdsTxtVerificationService::class);
        $reserved = $service->reserve($application, $user);
        $body = implode("\n", $reserved['records'])."\n";
        $requestNumber = 0;

        Http::fake(function (Request $request) use ($body, &$requestNumber) {
            $requestNumber++;
            return match ($requestNumber) {
                1, 3 => Http::response('', 302, ['Location' => 'https://delegated.example/ads.txt']),
                2 => Http::response($body, 200, ['Content-Type' => 'text/plain']),
                4 => Http::response('', 302, ['Location' => 'https://second.example/ads.txt']),
                default => Http::response('', 500),
            };
        });
        $ok = $service->verify($application->fresh(), $user);
        $this->assertTrue($ok['verified']);
        $this->assertSame('https://delegated.example/ads.txt', $ok['final_url']);

        $bad = $service->verify($application->fresh(), $user);
        $this->assertFalse($bad['verified']);
        $this->assertSame('EXTERNAL_REDIRECT_CHAIN_INVALID', $bad['code']);

        $sentBeforePrivateCheck = count(Http::recorded());
        $this->app->instance(DnsResolver::class, new class implements DnsResolver {
            public function addresses(string $host): array { return ['127.0.0.1']; }
        });
        $private = app(AdsTxtFetcher::class)->fetchDomain($application->primary_domain);
        $this->assertFalse($private['ok']);
        $this->assertSame('UNSAFE_TARGET', $private['error_code']);
        $this->assertCount($sentBeforePrivateCheck, Http::recorded());
    }

    public function test_approval_reuses_reserved_ids_and_matching_site_attaches_same_hms_idempotently(): void
    {
        [$application, $user] = $this->application();
        $reserved = $this->verifyApplication($application, $user);
        $hmpId = $reserved['publisher_seller']->seller_id;
        $hmsId = $reserved['website_seller']->seller_id;

        $application->update(['status' => PublisherApplicationStatus::Submitted, 'submitted_at' => now(), 'current_revision' => 1]);
        $application->update(['status' => PublisherApplicationStatus::UnderReview, 'review_started_at' => now()]);
        $admin = $this->admin();
        $application->update([
            'status' => PublisherApplicationStatus::Approved,
            'approved_at' => now(), 'reviewed_at' => now(), 'reviewed_by' => $admin->id,
        ]);

        $this->assertSame($hmpId, app(HorusSellerIdentityService::class)->managedForPublisher($application->publisher)->seller_id);
        $this->assertSame($hmsId, $application->domainClaims()->firstOrFail()->websiteSeller->seller_id);
        $this->assertDatabaseCount('sites', 0);

        $site = app(SiteLifecycleService::class)->create($this->sitePayload($application, $application->primary_domain), $user);
        $attached = app(HorusSellerIdentityService::class)->managedForSite($site);
        $this->assertSame($hmsId, $attached->seller_id);
        $again = app(HorusSellerIdentityService::class)->ensureForSite($site, $user);
        $this->assertSame($attached->id, $again->id);

        $second = app(SiteLifecycleService::class)->create($this->sitePayload($application, 'second-'.$application->primary_domain), $user);
        $secondHms = app(HorusSellerIdentityService::class)->managedForSite($second);
        $this->assertNotSame($hmsId, $secondHms->seller_id);
        $this->assertSame($hmpId, app(HorusSellerIdentityService::class)->managedForPublisher($application->publisher)->seller_id);
        $this->assertSame(2, SellerDeclaration::withoutGlobalScopes()
            ->where('publisher_id', $application->publisher_id)
            ->where('identity_scope', SellerIdentityScope::Website->value)->count());
    }

    public function test_reviewed_active_dual_ids_publish_both_ads_records_same_legal_entity_and_one_hms_schain_node(): void
    {
        [$application, $user] = $this->application();
        $reserved = $this->verifyApplication($application, $user);
        $application->update(['status' => PublisherApplicationStatus::Submitted, 'submitted_at' => now(), 'current_revision' => 1]);
        $application->update(['status' => PublisherApplicationStatus::UnderReview, 'review_started_at' => now()]);
        $admin = $this->admin();
        $application->publisher->update(['status' => AccountStatus::Active]);
        $application->organization->update(['status' => AccountStatus::Active]);
        $application->update(['status' => PublisherApplicationStatus::Approved, 'approved_at' => now(), 'reviewed_at' => now(), 'reviewed_by' => $admin->id]);
        $site = app(SiteLifecycleService::class)->create($this->sitePayload($application, $application->primary_domain), $user);
        $hmp = app(HorusSellerIdentityService::class)->managedForPublisher($application->publisher);
        $hms = app(HorusSellerIdentityService::class)->managedForSite($site);
        $this->activate($application, $hmp, $hms, $admin);

        $artifact = app(SupplyChainArtifactBuilder::class);
        $ads = $artifact->adsTxtForSite($site);
        $this->assertStringContainsString('horusmedia.net, '.$hmp->seller_id.', DIRECT', $ads);
        $this->assertStringContainsString('horusmedia.net, '.$hms->seller_id.', DIRECT', $ads);

        $rows = collect($artifact->sellersJsonPayload()['sellers'])->keyBy('seller_id');
        foreach ([$hmp->seller_id, $hms->seller_id] as $id) {
            $this->assertSame('PUBLISHER', $rows[$id]['seller_type']);
            $this->assertSame($application->publisher->legal_name, $rows[$id]['name']);
            $this->assertSame($application->publisher->business_domain, $rows[$id]['domain']);
        }

        $contract = app(SupplyChainStandardsContract::class);
        $this->assertSame($hms->seller_id, data_get($contract->sellerForSite($site), 'seller.payload.seller_id'));
        $schain = $contract->schainForSite($site);
        $this->assertSame(1, $schain['complete']);
        $this->assertSame([['asi' => 'horusmedia.net', 'sid' => $hms->seller_id, 'hp' => 1]], $schain['nodes']);
    }

    public function test_rejection_and_withdrawal_retain_ids_disable_serving_and_remove_terminal_reservations_from_public_output(): void
    {
        [$application, $user] = $this->application();
        $reserved = $this->verifyApplication($application, $user);
        $application->update(['status' => PublisherApplicationStatus::Submitted, 'submitted_at' => now(), 'current_revision' => 1]);
        $application->update(['status' => PublisherApplicationStatus::UnderReview, 'review_started_at' => now()]);
        $application->update(['status' => PublisherApplicationStatus::Rejected, 'rejected_at' => now()]);

        $claim = $application->fresh()->domainClaims()->firstOrFail();
        $this->assertSame('RELEASED', $claim->claim_status);
        foreach ([$reserved['publisher_seller'], $reserved['website_seller']] as $seller) {
            $this->assertDatabaseHas('seller_declarations', ['id' => $seller->id, 'seller_id' => $seller->seller_id, 'status' => 'DISABLED']);
        }
        $publicIds = collect(app(SupplyChainArtifactBuilder::class)->sellersJsonPayload()['sellers'])->pluck('seller_id');
        $this->assertFalse($publicIds->contains($reserved['publisher_seller']->seller_id));
        $this->assertFalse($publicIds->contains($reserved['website_seller']->seller_id));

        [$withdrawal, $withdrawUser] = $this->application('withdraw.example', 'owner@withdraw.example');
        $withdrawReserved = app(ApplicationAdsTxtVerificationService::class)->reserve($withdrawal, $withdrawUser);
        $withdrawal->update(['status' => PublisherApplicationStatus::Withdrawn, 'withdrawn_at' => now()]);
        $this->assertSame('RELEASED', $withdrawal->fresh()->domainClaims()->firstOrFail()->claim_status);
        $this->assertDatabaseHas('seller_declarations', ['id' => $withdrawReserved['website_seller']->id]);
    }

    public function test_applicant_ui_shows_dual_records_verification_is_throttled_and_other_tenant_cannot_enter_portal(): void
    {
        [$application, $user] = $this->application();
        $reserved = app(ApplicationAdsTxtVerificationService::class)->reserve($application, $user);
        $this->actingAs($user)->get(route('publisher-application.show', ['step' => 2]))
            ->assertOk()
            ->assertSee('Copy Both')
            ->assertSee($reserved['publisher_seller']->seller_id)
            ->assertSee($reserved['website_seller']->seller_id)
            ->assertSee('Verify Website');

        Http::fake(['*' => Http::response(implode("\n", $reserved['records'])."\n", 200, ['Content-Type' => 'text/plain'])]);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->put(route('publisher-application.update'), ['step' => 2, 'verify_website' => 1])->assertRedirect();
        }
        $this->put(route('publisher-application.update'), ['step' => 2, 'verify_website' => 1])->assertTooManyRequests();

        $other = $this->makeUser($this->makeOrganization(OrganizationType::Publisher, 'Other Tenant'), RoleName::PublisherAdmin);
        $this->actingAs($other)->get(route('publisher-application.show'))->assertForbidden();
    }

    public function test_task39_sqlite_migration_rolls_back_and_reapplies(): void
    {
        $migration = require database_path('migrations/2026_08_16_181500_add_dual_horus_seller_identity_and_application_ads_txt_verification.php');
        $this->assertTrue(Schema::hasColumn('seller_declarations', 'identity_scope'));
        $this->assertTrue(Schema::hasColumn('publisher_application_domain_claims', 'website_seller_declaration_id'));
        $migration->down();
        $this->assertFalse(Schema::hasColumn('seller_declarations', 'identity_scope'));
        $this->assertFalse(Schema::hasColumn('publisher_application_domain_claims', 'website_seller_declaration_id'));
        $migration->up();
        $this->assertTrue(Schema::hasColumn('seller_declarations', 'identity_scope'));
        $this->assertTrue(Schema::hasColumn('publisher_application_domain_claims', 'evidence_sha256'));
    }

    /** @return array{0: PublisherApplication, 1: User} */
    private function application(string $domain = 'publisher.example', string $email = 'owner@publisher.example'): array
    {
        $application = app(PublisherApplicationService::class)->register([
            'name' => 'Publisher Owner',
            'email' => $email,
            'publisher_name' => 'Publisher Example',
            'primary_domain' => $domain,
            'password' => 'Secure-Password-2026!',
        ]);
        $user = $application->applicant;
        $user->forceFill(['email_verified_at' => now()])->save();
        app(PublisherApplicationService::class)->emailVerified($user);
        $application->refresh()->load(['publisher', 'applicant', 'organization', 'domainClaim']);

        return [$application, $user];
    }

    /** @return array<string, mixed> */
    private function verifyApplication(PublisherApplication $application, User $user): array
    {
        $reserved = app(ApplicationAdsTxtVerificationService::class)->reserve($application, $user);
        Http::fake(['*' => Http::response(implode("\n", $reserved['records'])."\n", 200, ['Content-Type' => 'text/plain'])]);
        $result = app(ApplicationAdsTxtVerificationService::class)->verify($application->fresh(), $user);
        $this->assertTrue($result['verified']);

        return $reserved;
    }

    /** @return array<string, mixed> */
    private function sitePayload(PublisherApplication $application, string $domain): array
    {
        return [
            'organization_id' => $application->organization_id,
            'publisher_id' => $application->publisher_id,
            'display_name' => 'Site '.$domain,
            'primary_domain' => $domain,
            'language' => 'en', 'content_category' => 'News', 'country' => 'US',
            'main_traffic_countries' => ['US'], 'estimated_monthly_pageviews' => 100000,
            'estimated_monthly_users' => 50000, 'current_monetization_providers' => [],
            'prebid_enabled' => false, 'native_demand_enabled' => false,
            'default_revenue_share_percent' => 70,
        ];
    }

    private function activate(PublisherApplication $application, SellerDeclaration $hmp, SellerDeclaration $hms, User $admin): void
    {
        PublisherContract::withoutGlobalScopes()->create([
            'organization_id' => $application->organization_id,
            'publisher_id' => $application->publisher_id,
            'contract_reference' => 'TASK39-DUAL', 'status' => ContractStatus::Active,
            'starts_at' => now()->subDay(), 'revenue_share_percent' => 70,
            'currency' => 'USD', 'payment_terms' => 'NET_30', 'created_by' => $admin->id,
        ]);
        $invariants = app(SupplyChainInvariantService::class);
        $invariants->reviewPublisherIdentity($application->publisher->fresh(), SupplyChainReviewStatus::Verified, $admin);
        app(HorusSellerIdentityService::class)->reviewConfidentiality($hmp->fresh(), false, $admin);
        $invariants->reviewSellerDeclaration($hmp->fresh(), SupplyChainReviewStatus::Verified, $admin);
        $invariants->changeSellerStatus($hmp->fresh(), SellerDeclarationStatus::Active, $admin);
        app(HorusSellerIdentityService::class)->reviewConfidentiality($hms->fresh(), false, $admin);
        $invariants->reviewSellerDeclaration($hms->fresh(), SupplyChainReviewStatus::Verified, $admin);
        $invariants->changeSellerStatus($hms->fresh(), SellerDeclarationStatus::Active, $admin);
    }

    private function admin(): User
    {
        return $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'), RoleName::SuperAdmin);
    }

    private function publicDns(): void
    {
        $this->app->instance(DnsResolver::class, new class implements DnsResolver {
            public function addresses(string $host): array { return ['93.184.216.34']; }
        });
    }
}
