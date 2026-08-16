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
use App\Models\Organization;
use App\Models\PublisherApplication;
use App\Models\PublisherContract;
use App\Models\SellerDeclaration;
use App\Models\Site;
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

    public function test_application_reserves_real_hmp_and_hms_without_creating_a_site_and_publication_is_confidential(): void
    {
        [$application, $user] = $this->application();
        $reserved = app(ApplicationAdsTxtVerificationService::class)->reserve($application, $user);
        $hmp = $reserved['publisher_seller'];
        $hms = $reserved['website_seller'];
        $claim = $reserved['claim'];

        $this->assertMatchesRegularExpression('/^HMP-[0-9A-HJKMNP-TV-Z]{26}$/', $hmp->seller_id);
        $this->assertMatchesRegularExpression('/^HMS-[0-9A-HJKMNP-TV-Z]{26}$/', $hms->seller_id);
        $this->assertSame(SellerIdentitySource::HorusManaged, $hmp->identity_source);
        $this->assertSame(SellerIdentitySource::HorusManaged, $hms->identity_source);
        $this->assertSame(SellerIdentityScope::Publisher, $hmp->identity_scope);
        $this->assertSame(SellerIdentityScope::Website, $hms->identity_scope);
        $this->assertSame($application->publisher_id, $hmp->publisher_id);
        $this->assertSame($application->publisher_id, $hms->publisher_id);
        $this->assertNull($hmp->site_id);
        $this->assertNull($hms->site_id);
        $this->assertSame($claim->id, $hms->publisher_application_domain_claim_id);
        $this->assertSame($hmp->id, $claim->publisher_seller_declaration_id);
        $this->assertSame($hms->id, $claim->website_seller_declaration_id);
        $this->assertSame(SellerDeclarationStatus::Disabled, $hmp->status);
        $this->assertSame(SellerDeclarationStatus::Disabled, $hms->status);
        $this->assertTrue($hmp->is_confidential);
        $this->assertTrue($hms->is_confidential);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('site_configs', 0);
        $this->assertSame([
            'horusmedia.net, '.$hmp->seller_id.', DIRECT',
            'horusmedia.net, '.$hms->seller_id.', DIRECT',
        ], $reserved['records']);

        $payload = app(SupplyChainArtifactBuilder::class)->sellersJsonPayload();
        $rows = collect($payload['sellers'])->keyBy('seller_id');
        foreach ([$hmp->seller_id, $hms->seller_id] as $sellerId) {
            $row = $rows->get($sellerId);
            $this->assertNotNull($row);
            $this->assertSame('PUBLISHER', $row['seller_type']);
            $this->assertSame(1, $row['is_confidential']);
            $this->assertArrayNotHasKey('name', $row);
            $this->assertArrayNotHasKey('domain', $row);
        }
        $this->assertStringNotContainsString($user->email, json_encode($payload));
    }

    public function test_reservation_is_idempotent_unique_immutable_and_never_recycles_ids(): void
    {
        [$application, $user] = $this->application();
        $first = app(ApplicationAdsTxtVerificationService::class)->reserve($application, $user);
        $second = app(ApplicationAdsTxtVerificationService::class)->reserve($application->fresh(), $user);
        $this->assertSame($first['publisher_seller']->id, $second['publisher_seller']->id);
        $this->assertSame($first['website_seller']->id, $second['website_seller']->id);
        $this->assertSame(1, SellerDeclaration::withoutGlobalScopes()->where('publisher_id', $application->publisher_id)->where('identity_scope', 'PUBLISHER')->count());
        $this->assertSame(1, SellerDeclaration::withoutGlobalScopes()->where('publisher_id', $application->publisher_id)->where('identity_scope', 'WEBSITE')->count());

        foreach ([$first['publisher_seller'], $first['website_seller']] as $seller) {
            $original = $seller->seller_id;
            try {
                $seller->update(['seller_id' => str_starts_with($original, 'HMP-') ? 'HMP-01ARZ3NDEKTSV4RRFFQ69G5FAV' : 'HMS-01ARZ3NDEKTSV4RRFFQ69G5FAV']);
                $this->fail('Managed public seller IDs must be immutable.');
            } catch (LogicException) {
                $this->assertSame($original, $seller->fresh()->seller_id);
            }
            try {
                $seller->fresh()->delete();
                $this->fail('Managed seller IDs must never be deleted or recycled.');
            } catch (LogicException) {
                $this->assertDatabaseHas('seller_declarations', ['id' => $seller->id, 'seller_id' => $original]);
            }
        }

        $secondApplication = $this->application('other.example', 'other@other.example')[0];
        $other = app(ApplicationAdsTxtVerificationService::class)->reserve($secondApplication, $secondApplication->applicant)->publisher_seller ?? null;
        $this->assertNotSame($first['publisher_seller']->seller_id, $other?->seller_id);
    }

    public function test_ads_txt_verification_requires_both_real_direct_records_and_accepts_valid_whitespace(): void
    {
        [$application, $user] = $this->application();
        $reserved = app(ApplicationAdsTxtVerificationService::class)->reserve($application, $user);
        $hmp = $reserved['publisher_seller']->seller_id;
        $hms = $reserved['website_seller']->seller_id;

        $cases = [
            ['horusmedia.net, '.$hms.', DIRECT', 'PUBLISHER_HMP_AUTHORIZATION_MISSING'],
            ['horusmedia.net, '.$hmp.', DIRECT', 'WEBSITE_HMS_AUTHORIZATION_MISSING'],
            ['horusmedia.net, HMP-01ARZ3NDEKTSV4RRFFQ69G5FAA, DIRECT\nhorusmedia.net, '.$hms.', DIRECT', 'PUBLISHER_HMP_AUTHORIZATION_MISSING'],
            ['horusmedia.net, '.$hmp.', DIRECT\nhorusmedia.net, HMS-01ARZ3NDEKTSV4RRFFQ69G5FAA, DIRECT', 'WEBSITE_HMS_AUTHORIZATION_MISSING'],
            ['horusmedia.net, '.$hmp.', RESELLER\nhorusmedia.net, '.$hms.', DIRECT', 'HORUS_RELATIONSHIP_MISMATCH'],
        ];
        foreach ($cases as [$body, $code]) {
            Http::fake(['*' => Http::response(str_replace('\\n', "\n", $body), 200, ['Content-Type' => 'text/plain; charset=utf-8'])]);
            $result = app(ApplicationAdsTxtVerificationService::class)->verify($application->fresh(), $user);
            $this->assertFalse($result['verified']);
            $this->assertSame($code, $result['code']);
        }

        Http::fake(['*' => Http::response("  HORUSMEDIA.NET ,  {$hmp} , direct  \n\thorusmedia.net, {$hms}, DIRECT # website seller\n", 200, ['Content-Type' => 'text/plain'])]);
        $result = app(ApplicationAdsTxtVerificationService::class)->verify($application->fresh(), $user);
        $this->assertTrue($result['verified']);
        $claim = $application->fresh()->domainClaim()->firstOrFail();
        $this->assertSame('VERIFIED', $claim->verification_status);
        $this->assertNotNull($claim->verified_at);
        $this->assertNotNull($claim->evidence_sha256);
        $this->assertSame(64, strlen($claim->evidence_sha256));
        $this->assertSame(6, $claim->verification_attempt_count);
        $this->assertDatabaseHas('audit_logs', ['event' => 'publisher_application.domain_ads_txt_verification_attempted', 'auditable_id' => $claim->id]);
        $this->assertSame(AccountStatus::Pending, $application->publisher->fresh()->status);
        $this->assertSame(AccountStatus::Pending, $application->organization->fresh()->status);
        $this->assertDatabaseCount('sites', 0);
    }

    public function test_application_fetcher_follows_one_external_delegation_rejects_second_external_redirect_and_blocks_private_networks(): void
    {
        [$application, $user] = $this->application();
        $reserved = app(ApplicationAdsTxtVerificationService::class)->reserve($application, $user);
        $body = implode("\n", $reserved['records'])."\n";

        Http::fake(function (Request $request) use ($body) {
            return str_contains($request->url(), 'delegated.example')
                ? Http::response($body, 200, ['Content-Type' => 'text/plain'])
                : Http::response('', 302, ['Location' => 'https://delegated.example/ads.txt']);
        });
        $ok = app(ApplicationAdsTxtVerificationService::class)->verify($application->fresh(), $user);
        $this->assertTrue($ok['verified']);
        $this->assertSame('https://delegated.example/ads.txt', $ok['final_url']);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'delegated.example')) {
                return Http::response('', 302, ['Location' => 'https://second.example/ads.txt']);
            }
            return Http::response('', 302, ['Location' => 'https://delegated.example/ads.txt']);
        });
        $bad = app(ApplicationAdsTxtVerificationService::class)->verify($application->fresh(), $user);
        $this->assertFalse($bad['verified']);
        $this->assertSame('EXTERNAL_REDIRECT_CHAIN_INVALID', $bad['code']);

        Http::fake();
        $this->app->instance(DnsResolver::class, new class implements DnsResolver {
            public function addresses(string $host): array { return ['127.0.0.1']; }
        });
        $private = app(AdsTxtFetcher::class)->fetchDomain($application->primary_domain);
        $this->assertFalse($private['ok']);
        $this->assertSame('UNSAFE_TARGET', $private['error_code']);
        Http::assertNothingSent();
    }

    public function test_approval_reuses_ids_site_handoff_is_idempotent_and_additional_site_gets_new_hms_same_hmp(): void
    {
        [$application, $user] = $this->application();
        $reserved = $this->verifyApplication($application, $user);
        $hmpId = $reserved['publisher_seller']->seller_id;
        $hmsId = $reserved['website_seller']->seller_id;
        $this->completeDraft($user);
        $application = app(PublisherApplicationService::class)->submit($user);
        $admin = $this->admin();
        app(PublisherApplicationService::class)->startReview($application, $admin);
        app(PublisherApplicationService::class)->approve($application->fresh(), $admin);

        $application->refresh();
        $this->assertSame(PublisherApplicationStatus::Approved, $application->status);
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
        $this->assertNotNull($secondHms);
        $this->assertNotSame($hmsId, $secondHms->seller_id);
        $this->assertSame($application->publisher_id, $secondHms->publisher_id);
        $this->assertSame($hmpId, app(HorusSellerIdentityService::class)->managedForPublisher($application->publisher)->seller_id);
        $this->assertSame(2, SellerDeclaration::withoutGlobalScopes()->where('publisher_id', $application->publisher_id)->where('identity_scope', 'WEBSITE')->count());
    }

    public function test_active_hmp_and_hms_publish_two_ads_records_map_to_same_legal_publisher_and_emit_one_hms_schain_node(): void
    {
        [$application, $user] = $this->application();
        $reserved = $this->verifyApplication($application, $user);
        $this->completeDraft($user);
        $application = app(PublisherApplicationService::class)->submit($user);
        $admin = $this->admin();
        app(PublisherApplicationService::class)->startReview($application, $admin);
        app(PublisherApplicationService::class)->approve($application->fresh(), $admin);
        $application->refresh();
        $site = app(SiteLifecycleService::class)->create($this->sitePayload($application, $application->primary_domain), $user);
        $hmp = $reserved['publisher_seller']->fresh();
        $hms = app(HorusSellerIdentityService::class)->managedForSite($site);
        $this->activateDualIdentities($application, $hmp, $hms, $admin);

        $artifact = app(SupplyChainArtifactBuilder::class);
        $ads = $artifact->adsTxtForSite($site);
        $this->assertStringContainsString('horusmedia.net, '.$hmp->seller_id.', DIRECT', $ads);
        $this->assertStringContainsString('horusmedia.net, '.$hms->seller_id.', DIRECT', $ads);

        $payload = $artifact->sellersJsonPayload();
        $rows = collect($payload['sellers'])->keyBy('seller_id');
        $this->assertSame($application->publisher->legal_name, $rows[$hmp->seller_id]['name']);
        $this->assertSame($application->publisher->legal_name, $rows[$hms->seller_id]['name']);
        $this->assertSame($application->publisher->business_domain, $rows[$hmp->seller_id]['domain']);
        $this->assertSame($application->publisher->business_domain, $rows[$hms->seller_id]['domain']);

        $contract = app(SupplyChainStandardsContract::class);
        $selected = $contract->sellerForSite($site);
        $this->assertSame($hms->seller_id, data_get($selected, 'seller.payload.seller_id'));
        $schain = $contract->schainForSite($site);
        $this->assertSame(1, $schain['complete']);
        $this->assertSame([['asi' => 'horusmedia.net', 'sid' => $hms->seller_id, 'hp' => 1]], $schain['nodes']);
    }

    public function test_rejection_and_withdrawal_keep_reserved_ids_disabled_and_release_claim_without_deleting_evidence(): void
    {
        [$application, $user] = $this->application();
        $reserved = $this->verifyApplication($application, $user);
        $hmp = $reserved['publisher_seller'];
        $hms = $reserved['website_seller'];
        $this->completeDraft($user);
        $application = app(PublisherApplicationService::class)->submit($user);
        $admin = $this->admin();
        app(PublisherApplicationService::class)->startReview($application, $admin);
        app(PublisherApplicationService::class)->reject($application->fresh(), $admin, 'Task 39 rejection test.');
        $this->assertSame('RELEASED', $application->fresh()->domainClaims()->firstOrFail()->claim_status);
        $this->assertDatabaseHas('seller_declarations', ['id' => $hmp->id, 'seller_id' => $hmp->seller_id, 'status' => 'DISABLED']);
        $this->assertDatabaseHas('seller_declarations', ['id' => $hms->id, 'seller_id' => $hms->seller_id, 'status' => 'DISABLED']);
        $payload = app(SupplyChainArtifactBuilder::class)->sellersJsonPayload();
        $ids = collect($payload['sellers'])->pluck('seller_id');
        $this->assertFalse($ids->contains($hmp->seller_id));
        $this->assertFalse($ids->contains($hms->seller_id));

        [$withdrawal, $withdrawUser] = $this->application('withdraw.example', 'owner@withdraw.example');
        $withdrawReserved = app(ApplicationAdsTxtVerificationService::class)->reserve($withdrawal, $withdrawUser);
        app(PublisherApplicationService::class)->withdraw($withdrawUser);
        $this->assertSame('RELEASED', $withdrawal->fresh()->domainClaims()->firstOrFail()->claim_status);
        $this->assertDatabaseHas('seller_declarations', ['id' => $withdrawReserved['website_seller']->id]);
    }

    public function test_application_verification_uses_existing_write_throttle_and_cross_tenant_application_access_remains_closed(): void
    {
        [$application, $user] = $this->application();
        $reserved = app(ApplicationAdsTxtVerificationService::class)->reserve($application, $user);
        Http::fake(['*' => Http::response(implode("\n", $reserved['records'])."\n", 200, ['Content-Type' => 'text/plain'])]);
        $this->actingAs($user);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->put(route('publisher-application.update'), ['step' => 2, 'verify_website' => 1])->assertRedirect();
        }
        $this->put(route('publisher-application.update'), ['step' => 2, 'verify_website' => 1])->assertTooManyRequests();

        $otherOrg = $this->makeOrganization(OrganizationType::Publisher, 'Other Tenant');
        $other = $this->makeUser($otherOrg, RoleName::PublisherAdmin);
        $this->actingAs($other)->get(route('publisher-application.show'))->assertForbidden();
    }

    public function test_task39_sqlite_migration_rolls_back_and_reapplies_with_identity_and_evidence_columns(): void
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

    private function completeDraft(User $user): void
    {
        $application = PublisherApplication::withoutGlobalScopes()->where('applicant_user_id', $user->id)->firstOrFail();
        app(PublisherApplicationService::class)->saveDraft($user, [
            'contact_name' => 'Publisher Owner', 'legal_name' => 'Publisher Example LLC',
            'publisher_name' => 'Publisher Example', 'primary_domain' => $application->primary_domain,
            'content_categories' => ['NEWS'], 'content_description' => 'Original independent reporting and analysis.',
            'monthly_pageviews' => 150000, 'organic_percent' => 60, 'social_percent' => 10,
            'direct_percent' => 25, 'paid_percent' => 5, 'other_percent' => 0,
            'audience_countries' => ['US', 'GB'], 'desktop_percent' => 35, 'mobile_percent' => 60, 'tablet_percent' => 5,
            'original_content' => 1, 'user_generated_content' => 0, 'ai_assisted_content' => 0,
            'sensitive_content' => 0, 'has_privacy_policy' => 1, 'has_contact_details' => 1,
            'has_cmp' => 1, 'prior_policy_incidents' => 0, 'monetization_history' => 'Programmatic.',
            'application_notes' => 'Task 39 verified application.',
        ]);
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

    private function activateDualIdentities(PublisherApplication $application, SellerDeclaration $hmp, SellerDeclaration $hms, User $admin): void
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
