<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Publisher;
use App\Models\PublisherApplication;
use App\Models\PublisherApplicationRevision;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Identity\InvitationService;
use App\Services\PublisherApplications\PublisherApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use LogicException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class PublicPublisherApplicationTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        Notification::fake();
        Config::set('publisher-applications.public_registration_enabled', true);
        Config::set('publisher-applications.turnstile.enabled', false);
        Config::set('publisher-applications.legal_documents', [
            'PUBLISHER_TERMS' => [
                'label' => 'Publisher Terms',
                'version' => '2026-08',
                'url' => 'https://horusmedia.net/legal/publisher-terms?v=2026-08',
                'required' => true,
            ],
        ]);
    }

    public function test_public_registration_feature_switch_controls_only_new_applications(): void
    {
        Config::set('publisher-applications.public_registration_enabled', false);
        $this->get('/register/publisher')->assertNotFound();
        $this->post('/register/publisher', $this->registrationPayload())->assertNotFound();

        Config::set('publisher-applications.public_registration_enabled', true);
        $this->get('/register/publisher')->assertOk()->assertSee('Create a Publisher account');
        $this->post('/register/publisher', $this->registrationPayload())
            ->assertRedirect(route('verification.notice'));
    }

    public function test_public_registration_has_a_dedicated_strong_rate_limit(): void
    {
        $invalid = $this->registrationPayload(['password_confirmation' => 'different']);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])
                ->post('/register/publisher', $invalid)
                ->assertSessionHasErrors('password');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.41'])
            ->post('/register/publisher', $invalid)
            ->assertTooManyRequests();
        $this->assertDatabaseCount('publisher_applications', 0);
    }

    public function test_registration_creates_active_account_default_terms_and_no_website(): void
    {
        $user = $this->registerApplicant(['primary_domain' => 'stale-client.example']);
        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $publisher = Publisher::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(PublisherApplicationStatus::Approved, $application->status);
        $this->assertSame(AccountStatus::Active, $publisher->status);
        $this->assertSame(AccountStatus::Active, $publisher->organization->status);
        $this->assertNull($application->primary_domain);
        $this->assertNull($publisher->business_domain);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertTrue($user->hasRole(RoleName::PublisherAdmin->value));
        $this->assertDatabaseHas('publisher_contracts', [
            'publisher_id' => $publisher->id,
            'status' => 'ACTIVE',
            'revenue_share_percent' => 70,
            'ends_at' => null,
        ]);
        $this->assertDatabaseCount('publisher_application_domain_claims', 0);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('site_configs', 0);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'publisher_application.created',
            'auditable_id' => $application->id,
        ]);
    }

    public function test_duplicate_email_is_rejected_while_stale_domain_input_is_ignored(): void
    {
        $this->registerApplicant(['primary_domain' => 'news.example']);
        $this->post('/logout');

        $this->post('/register/publisher', $this->registrationPayload([
            'primary_domain' => 'other.example',
        ]))->assertSessionHasErrors('email');

        $this->post('/register/publisher', $this->registrationPayload([
            'email' => 'other@example.test',
            'primary_domain' => 'HTTPS://NEWS.EXAMPLE/',
        ]))->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('publisher_applications', 2);
        $this->assertDatabaseCount('publisher_application_domain_claims', 0);
        $this->assertTrue(PublisherApplication::withoutGlobalScopes()->get()
            ->every(fn (PublisherApplication $application): bool => $application->primary_domain === null));
    }

    public function test_public_registration_cannot_claim_existing_publisher_or_site_domains(): void
    {
        $organization = $this->makeOrganization(OrganizationType::Publisher, 'Existing Publisher');
        $publisher = Publisher::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'legal_name' => 'Existing LLC',
            'display_name' => 'Existing',
            'business_domain' => 'owner.example',
            'status' => AccountStatus::Active,
        ]);
        Site::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'publisher_id' => $publisher->id,
            'display_name' => 'Existing Site',
            'primary_domain' => 'site.example',
            'content_category' => 'NEWS',
            'country' => 'US',
            'default_revenue_share_percent' => 70,
        ]);

        $this->post('/register/publisher', $this->registrationPayload([
            'email' => 'one@example.test',
            'primary_domain' => 'owner.example',
        ]))->assertRedirect(route('verification.notice'));
        $this->post('/logout');
        $this->post('/register/publisher', $this->registrationPayload([
            'email' => 'two@example.test',
            'primary_domain' => 'site.example',
        ]))->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('publisher_applications', 2);
        $this->assertDatabaseCount('publisher_application_domain_claims', 0);
        $this->assertTrue(PublisherApplication::withoutGlobalScopes()->get()
            ->every(fn (PublisherApplication $application): bool => $application->primary_domain === null));
    }

    public function test_email_verification_is_required_and_existing_applicant_can_continue_when_registration_is_disabled(): void
    {
        $user = $this->registerApplicant();
        Config::set('publisher-applications.public_registration_enabled', false);
        $this->post(route('publisher-application.submit'), ['confirm' => 1])
            ->assertSessionHasErrors('email');

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
        $this->get($url)->assertRedirect(route('dashboard'));
        $this->assertSame(
            PublisherApplicationStatus::Approved,
            PublisherApplication::withoutGlobalScopes()->firstOrFail()->status,
        );
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_express_applicant_submits_an_immutable_revision_without_website_or_traffic_data(): void
    {
        $user = $this->readyDraft();
        $this->get(route('publisher-application.show'))
            ->assertOk()
            ->assertDontSee('Monthly pageviews')
            ->assertSee('No website, ads.txt, traffic data, or technical setup is required here.');

        $this->post(route('publisher-application.complete'), $this->expressPayload())
            ->assertRedirect(route('publisher-application.show'))
            ->assertSessionHasNoErrors();

        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $revision = PublisherApplicationRevision::firstOrFail();
        $this->assertSame(PublisherApplicationStatus::Submitted, $application->status);
        $this->assertNull($application->primary_domain);
        $this->assertNotNull($application->submitted_at);
        $this->assertSame(1, $application->current_revision);
        $this->assertSame(64, strlen($revision->snapshot_hash));
        $this->assertDatabaseCount('publisher_application_domain_claims', 0);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'publisher_application.submitted',
            'auditable_id' => $application->id,
        ]);

        $this->expectException(LogicException::class);
        $revision->update(['snapshot_hash' => str_repeat('0', 64)]);
    }

    public function test_pending_applicant_authenticates_only_to_application_portal_and_operational_routes_fail_backend_authorization(): void
    {
        $user = $this->readyDraft();
        $otherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Other Publisher');
        $otherPublisher = Publisher::withoutGlobalScopes()->create([
            'organization_id' => $otherOrganization->id,
            'legal_name' => 'Other LLC',
            'display_name' => 'Other',
        ]);
        $this->actingAs($user)->get(route('publisher-application.show'))->assertOk();

        foreach (['/', '/publisher/sites', '/publisher/finance', '/publisher/reporting', '/publisher/monetization', '/admin/publishers', '/admin/sites', '/admin/demand', '/admin/gam/connections', '/support/tickets'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/admin/prebid/accounts')->assertForbidden();
        $this->get(route('admin.publishers.show', $otherPublisher))->assertNotFound();
        $this->assertAuthenticatedAs($user);
    }

    public function test_more_information_roundtrip_preserves_prior_revision_and_resubmits_express_application(): void
    {
        [$user, $application] = $this->submittedApplication();
        $admin = $this->admin();
        $service = app(PublisherApplicationService::class);
        $service->startReview($application, $admin);
        $service->requestMoreInformation($application, $admin, 'Clarify the content description.');

        $this->assertSame(PublisherApplicationStatus::MoreInfoRequired, $application->fresh()->status);
        $this->actingAs($user)->get(route('publisher-application.show'))
            ->assertOk()
            ->assertSee('Clarify the content description.');

        $this->post(route('publisher-application.complete'), $this->expressPayload([
            'content_description' => 'Updated independent reporting and explanatory analysis for a broad audience.',
        ]))->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(PublisherApplicationStatus::Submitted, $application->status);
        $this->assertSame(2, $application->current_revision);
        $this->assertDatabaseCount('publisher_application_revisions', 2);
        $this->assertDatabaseHas('publisher_application_events', [
            'publisher_application_id' => $application->id,
            'action' => 'RESUBMITTED',
        ]);
    }

    public function test_approval_is_idempotent_and_hands_off_exactly_once_without_creating_a_site(): void
    {
        [, $application] = $this->submittedApplication();
        $admin = $this->admin();
        $service = app(PublisherApplicationService::class);
        $service->startReview($application, $admin);
        $first = $service->approve($application, $admin);
        $second = $service->approve($application->fresh(), $admin);

        $this->assertSame($first->publisher_id, $second->publisher_id);
        $this->assertSame(PublisherApplicationStatus::Approved, $second->status);
        $this->assertSame(AccountStatus::Active, $second->publisher->status);
        $this->assertSame(AccountStatus::Active, $second->publisher->organization->status);
        $this->assertTrue($second->applicant->hasRole(RoleName::PublisherAdmin->value));
        $this->assertDatabaseCount('publishers', 1);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('publisher_application_domain_claims', 0);
        $this->assertSame(1, AuditLog::query()->where('event', 'publisher_application.approved')->count());

        $this->actingAs($second->applicant)
            ->get(route('publisher.onboarding.show', 1))
            ->assertRedirect(route('publisher.sites.index'));
    }

    public function test_rejection_retains_application_evidence_without_creating_a_domain_claim(): void
    {
        [$user, $application] = $this->submittedApplication();
        $admin = $this->admin();
        $service = app(PublisherApplicationService::class);
        $service->startReview($application, $admin);
        $service->reject($application, $admin, 'The application could not be approved.');

        $application->refresh();
        $this->assertSame(PublisherApplicationStatus::Rejected, $application->status);
        $this->assertSame(AccountStatus::Pending, $application->publisher->status);
        $this->assertDatabaseCount('publisher_application_revisions', 1);
        $this->assertDatabaseCount('publisher_application_domain_claims', 0);
        $this->actingAs($user)->get(route('publisher-application.show'))
            ->assertOk()
            ->assertSee('not approved');
        $this->get('/publisher/sites')->assertForbidden();
    }

    public function test_applicant_can_withdraw_without_deleting_application_evidence(): void
    {
        [$user, $application] = $this->submittedApplication();
        $this->actingAs($user)
            ->post(route('publisher-application.withdraw'), ['confirm_withdrawal' => 1])
            ->assertSessionHasNoErrors();

        $this->assertSame(PublisherApplicationStatus::Withdrawn, $application->fresh()->status);
        $this->assertDatabaseCount('publisher_application_revisions', 1);
        $this->assertDatabaseCount('publisher_application_domain_claims', 0);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'publisher_application.withdrawn',
            'auditable_id' => $application->id,
        ]);
    }

    public function test_review_queue_and_actions_are_horus_only_and_explicitly_permission_protected(): void
    {
        [, $application] = $this->submittedApplication();
        $support = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia, 'Support'),
            RoleName::SupportAgent,
        );
        $this->actingAs($support)
            ->withSession($this->adminSession())
            ->get(route('admin.publisher-applications.index'))
            ->assertOk();
        $this->post(route('admin.publisher-applications.start-review', $application))->assertForbidden();

        $publisherUser = $application->applicant;
        $this->actingAs($publisherUser)
            ->get(route('admin.publisher-applications.index'))
            ->assertForbidden();
    }

    public function test_existing_invitation_and_active_logins_remain_unchanged(): void
    {
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Invited Publisher');
        $publisherAdmin = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin, [
            'password' => 'existing-password',
        ]);
        Publisher::withoutGlobalScopes()->create([
            'organization_id' => $publisherOrganization->id,
            'legal_name' => 'Invited LLC',
            'display_name' => 'Invited',
        ]);
        $viewerRole = Role::query()->where('name', RoleName::PublisherViewer->value)->firstOrFail();
        [, $token] = app(InvitationService::class)->issue(
            $publisherOrganization,
            'viewer@invited.test',
            $viewerRole,
            $publisherAdmin,
        );
        $viewer = app(InvitationService::class)->accept($token, 'Viewer', 'secure-password-123');
        $this->assertTrue($viewer->hasRole(RoleName::PublisherViewer->value));

        $this->post('/login', [
            'email' => $publisherAdmin->email,
            'password' => 'existing-password',
        ])->assertRedirect('/');
    }

    public function test_repeated_approval_requests_cannot_duplicate_canonical_entities(): void
    {
        [, $application] = $this->submittedApplication();
        $admin = $this->admin();
        app(PublisherApplicationService::class)->startReview($application, $admin);

        $this->actingAs($admin)
            ->withSession($this->adminSession())
            ->post(route('admin.publisher-applications.approve', $application))
            ->assertRedirect();
        $this->post(route('admin.publisher-applications.approve', $application->fresh()))
            ->assertRedirect();

        $this->assertDatabaseCount('publisher_applications', 1);
        $this->assertDatabaseCount('publishers', 1);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('publisher_application_domain_claims', 0);
        $this->assertSame(1, AuditLog::query()->where('event', 'publisher_application.approved')->count());
    }

    private function registerApplicant(array $overrides = []): User
    {
        $this->post('/register/publisher', $this->registrationPayload($overrides))
            ->assertRedirect(route('verification.notice'));

        return User::query()->where('email', $overrides['email'] ?? 'owner@news.example')->firstOrFail();
    }

    private function readyDraft(): User
    {
        $organization = Organization::create([
            'name' => 'Legacy News Publisher',
            'slug' => 'legacy-news-publisher',
            'type' => OrganizationType::Publisher,
            'status' => AccountStatus::Pending,
        ]);
        $publisher = Publisher::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'legal_name' => 'News Publisher',
            'display_name' => 'News Publisher',
            'status' => AccountStatus::Pending,
        ]);
        $user = User::create([
            'organization_id' => $organization->id,
            'name' => 'News Owner',
            'email' => 'owner@news.example',
            'password' => 'Secure-Password-2026!',
            'status' => 'ACTIVE',
        ]);
        $user->forceFill(['email_verified_at' => now()])->save();
        PublisherApplication::withoutGlobalScopes()->create([
            'organization_id' => $organization->id,
            'publisher_id' => $publisher->id,
            'applicant_user_id' => $user->id,
            'status' => PublisherApplicationStatus::Draft,
        ]);
        $this->actingAs($user);

        return $user;
    }

    /** @return array{0: User, 1: PublisherApplication} */
    private function submittedApplication(): array
    {
        $user = $this->readyDraft();
        $this->post(route('publisher-application.complete'), $this->expressPayload())
            ->assertSessionHasNoErrors();

        return [$user, PublisherApplication::withoutGlobalScopes()->firstOrFail()];
    }

    private function admin(): User
    {
        return $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'),
            RoleName::SuperAdmin,
        );
    }

    /** @return array<string, int> */
    private function adminSession(): array
    {
        return ['two_factor_passed_at' => now()->timestamp];
    }

    /** @return array<string, mixed> */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'News Owner',
            'email' => 'owner@news.example',
            'publisher_name' => 'News Publisher',
            'password' => 'Secure-Password-2026!',
            'password_confirmation' => 'Secure-Password-2026!',
            '_company_website' => '',
            'legal' => ['PUBLISHER_TERMS' => 1],
            'marketing_opt_in' => 0,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function expressPayload(array $overrides = []): array
    {
        return array_merge([
            'contact_name' => 'News Owner',
            'legal_name' => 'News Publisher LLC',
            'publisher_name' => 'News Publisher',
            'content_categories' => ['NEWS'],
            'content_description' => 'Original independent reporting and analysis for a general audience.',
            'legal' => ['PUBLISHER_TERMS' => 1],
            'marketing_opt_in' => 0,
            'confirm' => 1,
        ], $overrides);
    }
}
