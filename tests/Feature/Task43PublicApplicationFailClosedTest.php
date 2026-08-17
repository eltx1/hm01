<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Enums\StaticDeliveryPriority;
use App\Models\PublisherApplication;
use App\Models\SellerDeclaration;
use App\Models\StaticGlobalArtifactChange;
use App\Models\User;
use App\Services\Network\Contracts\DnsResolver;
use App\Services\PublisherApplications\ApplicationAdsTxtVerificationService;
use App\Services\PublisherApplications\PublisherApplicationLegalService;
use App\Services\PublisherApplications\PublisherApplicationReadinessService;
use App\Services\PublisherApplications\PublisherApplicationService;
use App\Services\PublisherApplications\TurnstileVerifier;
use App\Services\StaticDelivery\SupplyChainStaticPublisher;
use App\Services\SupplyChain\SupplyChainArtifactBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class Task43PublicApplicationFailClosedTest extends TestCase
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

    public function test_unverified_reserved_hmp_hms_never_publish_during_unrelated_global_publication(): void
    {
        [$application, $user] = $this->application();
        $reserved = app(ApplicationAdsTxtVerificationService::class)->reserve($application, $user);

        app(SupplyChainStaticPublisher::class)->queueNormal(['event' => 'UNRELATED_GLOBAL_CHANGE']);

        $ids = collect(app(SupplyChainArtifactBuilder::class)->sellersJsonPayload()['sellers'])->pluck('seller_id');
        $this->assertFalse($ids->contains($reserved['publisher_seller']->seller_id));
        $this->assertFalse($ids->contains($reserved['website_seller']->seller_id));
    }

    public function test_real_ads_txt_verification_publishes_reserved_ids_and_queues_once_idempotently(): void
    {
        [$application, $user] = $this->application();
        $verification = app(ApplicationAdsTxtVerificationService::class);
        $reserved = $verification->reserve($application, $user);
        Http::fake(['*' => Http::response(implode("\n", $reserved['records'])."\n", 200, ['Content-Type' => 'text/plain'])]);

        $this->assertTrue($verification->verify($application->fresh(), $user)['verified']);
        $change = StaticGlobalArtifactChange::query()->where('artifact_type', StaticGlobalArtifactChange::SUPPLY_CHAIN)->firstOrFail();
        $this->assertSame(StaticDeliveryPriority::Normal, $change->priority);
        $this->assertSame(1, $change->event_count);

        $ids = collect(app(SupplyChainArtifactBuilder::class)->sellersJsonPayload()['sellers'])->pluck('seller_id');
        $this->assertTrue($ids->contains($reserved['publisher_seller']->seller_id));
        $this->assertTrue($ids->contains($reserved['website_seller']->seller_id));

        $this->assertTrue($verification->verify($application->fresh(), $user)['verified']);
        $this->assertSame(1, StaticGlobalArtifactChange::query()->where('artifact_type', StaticGlobalArtifactChange::SUPPLY_CHAIN)->count());
        $this->assertSame(1, StaticGlobalArtifactChange::query()->firstOrFail()->event_count);
    }

    public function test_verified_claim_failure_revokes_publication_and_escalates_existing_outbox_to_urgent(): void
    {
        [$application, $user] = $this->application();
        $verification = app(ApplicationAdsTxtVerificationService::class);
        $reserved = $verification->reserve($application, $user);
        Http::fakeSequence()
            ->push(implode("\n", $reserved['records'])."\n", 200, ['Content-Type' => 'text/plain'])
            ->push('', 503, ['Content-Type' => 'text/plain']);

        $this->assertTrue($verification->verify($application->fresh(), $user)['verified']);
        $this->assertFalse($verification->refreshExistingVerification($application->fresh(), $user)['verified']);

        $change = StaticGlobalArtifactChange::query()->firstOrFail();
        $this->assertSame(StaticDeliveryPriority::Urgent, $change->priority);
        $this->assertSame(2, $change->event_count);
        $ids = collect(app(SupplyChainArtifactBuilder::class)->sellersJsonPayload()['sellers'])->pluck('seller_id');
        $this->assertFalse($ids->contains($reserved['publisher_seller']->seller_id));
        $this->assertFalse($ids->contains($reserved['website_seller']->seller_id));
    }

    public function test_rejection_and_withdrawal_remove_verified_reservations_and_queue_urgent_without_deleting_identities(): void
    {
        foreach ([PublisherApplicationStatus::Rejected, PublisherApplicationStatus::Withdrawn] as $terminal) {
            [$application, $user] = $this->application($terminal === PublisherApplicationStatus::Rejected ? 'reject.example' : 'withdraw.example', $terminal === PublisherApplicationStatus::Rejected ? 'reject@example.test' : 'withdraw@example.test');
            $reserved = $this->verify($application, $user);
            StaticGlobalArtifactChange::query()->delete();

            if ($terminal === PublisherApplicationStatus::Rejected) {
                $application->update(['status' => PublisherApplicationStatus::Submitted, 'submitted_at' => now(), 'current_revision' => 1]);
                $application->update(['status' => PublisherApplicationStatus::UnderReview, 'review_started_at' => now()]);
                $application->update(['status' => PublisherApplicationStatus::Rejected, 'rejected_at' => now()]);
            } else {
                $application->update(['status' => PublisherApplicationStatus::Withdrawn, 'withdrawn_at' => now()]);
            }

            $this->assertSame('RELEASED', $application->fresh()->domainClaim()->firstOrFail()->claim_status);
            $change = StaticGlobalArtifactChange::query()->firstOrFail();
            $this->assertSame(StaticDeliveryPriority::Urgent, $change->priority);
            foreach ([$reserved['publisher_seller'], $reserved['website_seller']] as $seller) {
                $this->assertDatabaseHas('seller_declarations', ['id' => $seller->id, 'seller_id' => $seller->seller_id]);
            }
            $ids = collect(app(SupplyChainArtifactBuilder::class)->sellersJsonPayload()['sellers'])->pluck('seller_id');
            $this->assertFalse($ids->contains($reserved['publisher_seller']->seller_id));
            $this->assertFalse($ids->contains($reserved['website_seller']->seller_id));
            StaticGlobalArtifactChange::query()->delete();
        }
    }

    public function test_direct_claim_release_revokes_publication_but_retains_immutable_hmp_hms_records(): void
    {
        [$application, $user] = $this->application();
        $reserved = $this->verify($application, $user);
        StaticGlobalArtifactChange::query()->delete();
        $claim = $application->fresh()->domainClaim()->firstOrFail();

        $claim->update(['claim_status' => 'RELEASED', 'released_at' => now()]);

        $this->assertSame(StaticDeliveryPriority::Urgent, StaticGlobalArtifactChange::query()->firstOrFail()->priority);
        $this->assertDatabaseHas('seller_declarations', ['id' => $reserved['publisher_seller']->id, 'seller_id' => $reserved['publisher_seller']->seller_id]);
        $this->assertDatabaseHas('seller_declarations', ['id' => $reserved['website_seller']->id, 'seller_id' => $reserved['website_seller']->seller_id]);
        $ids = collect(app(SupplyChainArtifactBuilder::class)->sellersJsonPayload()['sellers'])->pluck('seller_id');
        $this->assertFalse($ids->contains($reserved['publisher_seller']->seller_id));
        $this->assertFalse($ids->contains($reserved['website_seller']->seller_id));
    }

    public function test_rolled_back_claim_authorization_leaves_no_static_publication_request(): void
    {
        [$application] = $this->application();
        $claim = $application->domainClaim()->firstOrFail();

        try {
            DB::transaction(function () use ($claim): void {
                $claim->update(['verification_status' => 'VERIFIED', 'verified_at' => now()]);
                throw new RuntimeException('force rollback');
            });
            $this->fail('Rollback sentinel must throw.');
        } catch (RuntimeException $exception) {
            $this->assertSame('force rollback', $exception->getMessage());
        }

        $this->assertNotSame('VERIFIED', $claim->fresh()->verification_status);
        $this->assertDatabaseCount('static_global_artifact_changes', 0);
    }

    public function test_missing_required_legal_version_or_url_blocks_public_registration_without_partial_rows(): void
    {
        foreach ([
            ['publisher-applications.legal_documents.TERMS_OF_SERVICE.version', '', 'Legal Terms Version Missing'],
            ['publisher-applications.legal_documents.PRIVACY_POLICY.url', '', 'Legal Privacy Url Missing'],
        ] as [$key, $value, $adminReason]) {
            Config::set($key, $value);
            $beforeApplications = PublisherApplication::withoutGlobalScopes()->count();
            $beforeUsers = User::count();

            $this->get('/register/publisher')->assertStatus(503)->assertSee('temporarily unavailable');
            $this->post('/register/publisher', $this->registrationPayload())->assertStatus(503);
            $this->assertSame($beforeApplications, PublisherApplication::withoutGlobalScopes()->count());
            $this->assertSame($beforeUsers, User::count());

            $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media '.$adminReason), RoleName::SuperAdmin);
            $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])
                ->get(route('admin.publisher-applications.index'))
                ->assertOk()->assertSee('Publisher Application — BLOCKED')->assertSee($adminReason);

            $this->setValidLegalConfig();
        }
    }

    public function test_current_legal_contract_requires_explicit_exact_acceptance_and_ready_config_allows_it(): void
    {
        [$application, $user] = $this->application();
        $legal = app(PublisherApplicationLegalService::class);
        $documents = $legal->documents();
        $this->assertSame(PublisherApplicationReadinessService::READY, app(PublisherApplicationReadinessService::class)->state()['status']);

        $request = Request::create('/publisher/application', 'PUT', []);
        $request->setUserResolver(fn () => $user);
        $input = ['legal' => collect($documents)->mapWithKeys(fn ($document, $type) => [$type => true])->all()];
        $legal->record($application, $user, $input, $request);
        $legal->assertCurrentRequiredAccepted($application, $user);

        Config::set('publisher-applications.legal_documents.TERMS_OF_SERVICE.version', 'test-terms-v2');
        $this->expectException(ValidationException::class);
        $legal->assertCurrentRequiredAccepted($application, $user);
    }

    public function test_production_fake_turnstile_fails_closed_while_test_fake_provider_remains_deterministic(): void
    {
        Config::set('publisher-applications.turnstile.enabled', true);
        Config::set('publisher-applications.turnstile.provider', 'fake');
        Config::set('publisher-applications.turnstile.expected_hostname', 'app.horusmedia.net');
        Config::set('publisher-applications.turnstile.action', 'publisher_registration');
        Config::set('publisher-applications.turnstile.test_token', 'turnstile-test-valid');

        app(TurnstileVerifier::class)->verify('turnstile-test-valid');

        $this->app['env'] = 'production';
        Config::set('publisher-applications.turnstile.secret_key', 'not-a-real-secret-test-value');
        Config::set('publisher-applications.turnstile.site_key', 'test-site-key');
        $this->assertSame(PublisherApplicationReadinessService::BLOCKED, app(PublisherApplicationReadinessService::class)->state()['status']);

        $this->expectException(ValidationException::class);
        app(TurnstileVerifier::class)->verify('turnstile-test-valid');
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

        return [$application->fresh(), $user];
    }

    /** @return array<string, mixed> */
    private function verify(PublisherApplication $application, User $user): array
    {
        $service = app(ApplicationAdsTxtVerificationService::class);
        $reserved = $service->reserve($application, $user);
        Http::fake(['*' => Http::response(implode("\n", $reserved['records'])."\n", 200, ['Content-Type' => 'text/plain'])]);
        $this->assertTrue($service->verify($application->fresh(), $user)['verified']);

        return $reserved;
    }

    /** @return array<string, mixed> */
    private function registrationPayload(): array
    {
        return [
            'name' => 'Blocked Applicant',
            'email' => 'blocked@example.test',
            'publisher_name' => 'Blocked Publisher',
            'primary_domain' => 'blocked.example',
            'password' => 'Secure-Password-2026!',
            'password_confirmation' => 'Secure-Password-2026!',
        ];
    }

    private function setValidLegalConfig(): void
    {
        Config::set('publisher-applications.legal_documents.TERMS_OF_SERVICE.version', 'test-terms-v1');
        Config::set('publisher-applications.legal_documents.TERMS_OF_SERVICE.url', 'https://horus.test/legal/terms');
        Config::set('publisher-applications.legal_documents.PRIVACY_POLICY.version', 'test-privacy-v1');
        Config::set('publisher-applications.legal_documents.PRIVACY_POLICY.url', 'https://horus.test/legal/privacy');
        Config::set('publisher-applications.legal_documents.PUBLISHER_TERMS.version', 'test-publisher-terms-v1');
        Config::set('publisher-applications.legal_documents.PUBLISHER_TERMS.url', 'https://horus.test/legal/publisher-terms');
    }
}
