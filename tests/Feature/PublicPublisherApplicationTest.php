<?php

namespace Tests\Feature;

use App\Enums\AccountStatus;
use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Mail\HorusNotificationMail;
use App\Models\AuditLog;
use App\Models\Publisher;
use App\Models\PublisherApplication;
use App\Models\PublisherApplicationRevision;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Identity\InvitationService;
use App\Services\PublisherApplications\ApplicationAdsTxtVerificationService;
use App\Services\PublisherApplications\PublisherApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
    }

    public function test_public_registration_feature_switch_controls_only_new_applications(): void
    {
        Config::set('publisher-applications.public_registration_enabled', false);
        $this->get('/register/publisher')->assertNotFound();
        $this->post('/register/publisher', $this->registrationPayload())->assertNotFound();

        Config::set('publisher-applications.public_registration_enabled', true);
        $this->get('/register/publisher')->assertOk()->assertSee('Apply as a Publisher');
        $this->post('/register/publisher', $this->registrationPayload())->assertRedirect(route('verification.notice'));
    }

    public function test_public_registration_has_a_dedicated_strong_rate_limit(): void
    {
        Config::set('publisher-applications.public_registration_enabled', true);
        $invalid = $this->registrationPayload(['password_confirmation' => 'different']);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('/register/publisher', $invalid)->assertSessionHasErrors('password');
        }
        $this->post('/register/publisher', $invalid)->assertTooManyRequests();
        $this->assertDatabaseCount('publisher_applications', 0);
    }

    public function test_registration_creates_pending_canonical_entities_without_roles_sites_or_serving(): void
    {
        $user = $this->registerApplicant();
        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $publisher = Publisher::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(PublisherApplicationStatus::EmailVerificationRequired, $application->status);
        $this->assertSame(AccountStatus::Pending, $publisher->status);
        $this->assertSame(AccountStatus::Pending, $publisher->organization->status);
        $this->assertSame('news.example', $application->primary_domain);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->assertSame(0, $user->roles()->count());
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('site_configs', 0);
        $this->assertDatabaseHas('audit_logs', ['event' => 'publisher_application.created', 'auditable_id' => $application->id]);
    }

    public function test_duplicate_email_domain_and_canonical_domain_variants_are_safely_rejected(): void
    {
        $this->registerApplicant();
        $this->post('/logout');

        $duplicateEmail = $this->registrationPayload(['primary_domain' => 'other.example']);
        $this->post('/register/publisher', $duplicateEmail)->assertSessionHasErrors('email');

        $duplicateDomain = $this->registrationPayload(['email' => 'other@example.test', 'primary_domain' => 'HTTPS://NEWS.EXAMPLE/']);
        $this->post('/register/publisher', $duplicateDomain)->assertSessionHasErrors('primary_domain');
        $this->assertDatabaseCount('publisher_applications', 1);
    }

    public function test_existing_publisher_and_website_domains_cannot_be_claimed_by_a_public_application(): void
    {
        Config::set('publisher-applications.public_registration_enabled', true);
        $organization = $this->makeOrganization(OrganizationType::Publisher, 'Existing Publisher');
        $publisher = Publisher::withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'legal_name' => 'Existing LLC',
            'display_name' => 'Existing', 'business_domain' => 'owner.example', 'status' => AccountStatus::Active,
        ]);
        Site::withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'publisher_id' => $publisher->id,
            'display_name' => 'Existing Site', 'primary_domain' => 'site.example',
            'content_category' => 'NEWS', 'country' => 'US', 'default_revenue_share_percent' => 70,
        ]);

        $this->post('/register/publisher', $this->registrationPayload(['email' => 'one@example.test', 'primary_domain' => 'owner.example']))->assertSessionHasErrors('primary_domain');
        $this->post('/register/publisher', $this->registrationPayload(['email' => 'two@example.test', 'primary_domain' => 'site.example']))->assertSessionHasErrors('primary_domain');
        $this->assertDatabaseCount('publisher_applications', 0);
    }

    public function test_email_verification_is_required_and_existing_applicant_can_continue_when_registration_is_disabled(): void
    {
        $user = $this->registerApplicant();
        Config::set('publisher-applications.public_registration_enabled', false);
        $this->post(route('publisher-application.submit'), ['confirm' => 1])->assertSessionHasErrors('email');

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(5), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($url)->assertRedirect(route('publisher-application.show'));
        $this->assertSame(PublisherApplicationStatus::Draft, PublisherApplication::withoutGlobalScopes()->firstOrFail()->status);
        $this->get(route('publisher-application.show'))->assertOk();
    }

    public function test_applicant_can_save_resume_and_submit_an_immutable_revision(): void
    {
        $user = $this->readyDraft();
        $this->put(route('publisher-application.update'), $this->applicationPayload())->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->post('/logout')->assertRedirect('/login');
        $this->post('/login', ['email' => $user->email, 'password' => 'Secure-Password-2026!'])->assertRedirect(route('publisher-application.show'));
        $this->get(route('publisher-application.show'))->assertSee('Original independent reporting');
        $this->verifyWebsite($user);

        $this->post(route('publisher-application.submit'), ['confirm' => 1])->assertSessionHasNoErrors();
        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $revision = PublisherApplicationRevision::firstOrFail();
        $this->assertSame(PublisherApplicationStatus::Submitted, $application->status);
        $this->assertNotNull($application->submitted_at);
        $this->assertSame(1, $application->current_revision);
        $this->assertSame(64, strlen($revision->snapshot_hash));
        $this->assertDatabaseHas('audit_logs', ['event' => 'publisher_application.submitted', 'auditable_id' => $application->id]);

        $this->expectException(LogicException::class);
        $revision->update(['snapshot_hash' => str_repeat('0', 64)]);
    }

    public function test_pending_applicant_authenticates_only_to_application_portal_and_operational_routes_fail_backend_authorization(): void
    {
        $user = $this->readyDraft();
        $otherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Other Publisher');
        $otherPublisher = Publisher::withoutGlobalScopes()->create(['organization_id' => $otherOrganization->id, 'legal_name' => 'Other LLC', 'display_name' => 'Other']);
        $this->actingAs($user)->get(route('publisher-application.show'))->assertOk();

        foreach (['/', '/publisher/sites', '/publisher/finance', '/publisher/reporting', '/publisher/monetization', '/admin/publishers', '/admin/sites', '/admin/demand', '/admin/gam/connections', '/support/tickets'] as $path) {
            $this->get($path)->assertForbidden();
        }
        $this->post('/admin/prebid/accounts')->assertForbidden();
        $this->get(route('admin.publishers.show', $otherPublisher))->assertNotFound();
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->get('/admin/publishers')->assertRedirect(route('admin.login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'Secure-Password-2026!'])
            ->assertRedirect(route('publisher-application.show'));
    }

    public function test_more_information_roundtrip_preserves_prior_revision_and_resubmits(): void
    {
        [$user, $application] = $this->submittedApplication();
        $admin = $this->admin();
        $this->actingAs($admin)->withSession($this->adminSession())
            ->post(route('admin.publisher-applications.start-review', $application))->assertSessionHasNoErrors();
        $this->post(route('admin.publisher-applications.request-information', $application), ['reason' => 'Clarify the source of paid traffic.'])->assertSessionHasNoErrors();
        $this->assertSame(PublisherApplicationStatus::MoreInfoRequired, $application->fresh()->status);

        $this->actingAs($user)->get(route('publisher-application.show'))->assertSee('Clarify the source of paid traffic.');
        $updated = $this->applicationPayload(['application_notes' => 'Paid traffic uses a documented search campaign.']);
        $this->put(route('publisher-application.update'), $updated)->assertSessionHasNoErrors();
        $this->post(route('publisher-application.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        $application->refresh();
        $this->assertSame(PublisherApplicationStatus::Submitted, $application->status);
        $this->assertSame(2, $application->current_revision);
        $this->assertDatabaseCount('publisher_application_revisions', 2);
        $this->assertDatabaseHas('publisher_application_events', ['publisher_application_id' => $application->id, 'action' => 'RESUBMITTED']);
    }

    public function test_pending_applicant_receives_account_notification_email_for_information_request(): void
    {
        [, $application] = $this->submittedApplication();
        $admin = $this->admin();
        $service = app(PublisherApplicationService::class);
        $service->startReview($application, $admin);
        $service->requestMoreInformation($application, $admin, 'Provide an ownership contact.');

        Mail::fake();
        $this->artisan('notifications:deliver-email')->assertSuccessful();
        Mail::assertSent(HorusNotificationMail::class, fn (HorusNotificationMail $mail) => $mail->hasTo($application->applicant->email)
            && $mail->item->type === 'PUBLISHER_APPLICATION_MORE_INFO');
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
        $this->assertDatabaseCount('organizations', 2);
        $this->assertDatabaseCount('sites', 0);
        $this->assertDatabaseCount('publisher_application_revisions', 1);
        $this->assertSame(1, AuditLog::query()->where('event', 'publisher_application.approved')->count());

        $this->actingAs($second->applicant)->get(route('publisher.onboarding.show', 1))->assertOk();
    }

    public function test_rejection_retains_evidence_and_keeps_operational_access_disabled(): void
    {
        [$user, $application] = $this->submittedApplication();
        $admin = $this->admin();
        $service = app(PublisherApplicationService::class);
        $service->startReview($application, $admin);
        $service->reject($application, $admin, 'The submitted ownership evidence could not be validated.');

        $application->refresh();
        $this->assertSame(PublisherApplicationStatus::Rejected, $application->status);
        $this->assertSame(AccountStatus::Pending, $application->publisher->status);
        $this->assertDatabaseCount('publisher_application_revisions', 1);
        $this->assertDatabaseHas('publisher_application_domain_claims', [
            'publisher_application_id' => $application->id,
            'claim_status' => 'RELEASED',
        ]);
        $this->actingAs($user)->get(route('publisher-application.show'))->assertOk()->assertSee('not approved');
        $this->get('/publisher/sites')->assertForbidden();
    }

    public function test_applicant_can_withdraw_without_deleting_evidence(): void
    {
        [$user, $application] = $this->submittedApplication();
        $this->actingAs($user)->post(route('publisher-application.withdraw'), ['confirm_withdrawal' => 1])->assertSessionHasNoErrors();
        $this->assertSame(PublisherApplicationStatus::Withdrawn, $application->fresh()->status);
        $this->assertDatabaseCount('publisher_application_revisions', 1);
        $this->assertDatabaseHas('publisher_application_domain_claims', ['publisher_application_id' => $application->id, 'claim_status' => 'RELEASED']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'publisher_application.withdrawn', 'auditable_id' => $application->id]);
    }

    public function test_thoth_and_legacy_account_controls_cannot_make_the_application_decision(): void
    {
        [, $application] = $this->submittedApplication();
        $admin = $this->admin();
        app(PublisherApplicationService::class)->startReview($application, $admin);
        $this->actingAs($admin)->withSession($this->adminSession())
            ->post(route('admin.publishers.review', $application->publisher), ['decision' => 'APPROVE', 'reason' => 'AI or legacy decision.'])
            ->assertSessionHasErrors('decision');
        $this->patch(route('admin.publishers.status', $application->publisher), ['status' => 'ACTIVE'])->assertSessionHasErrors('status');
        $this->patch(route('admin.organizations.status', $application->organization), ['status' => 'ACTIVE'])->assertSessionHasErrors('status');
        $this->put(route('admin.publishers.update', $application->publisher), [
            'legal_name' => 'Bypass LLC', 'display_name' => 'Bypass', 'business_domain' => 'bypass.example',
            'organization_slug' => $application->organization->slug, 'status' => 'ACTIVE',
        ])->assertSessionHasErrors('publisher');
        $this->put(route('admin.organizations.update', $application->organization), [
            'name' => 'Bypass Organization', 'slug' => $application->organization->slug,
            'type' => OrganizationType::Publisher->value, 'status' => 'ACTIVE',
        ])->assertSessionHasErrors('organization');
        $this->assertSame(PublisherApplicationStatus::UnderReview, $application->fresh()->status);
        $this->assertSame(AccountStatus::Pending, $application->publisher->fresh()->status);
    }

    public function test_review_queue_and_actions_are_horus_only_and_explicitly_permission_protected(): void
    {
        [, $application] = $this->submittedApplication();
        $support = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Support'), RoleName::SupportAgent);
        $this->actingAs($support)->withSession($this->adminSession())->get(route('admin.publisher-applications.index'))->assertOk();
        $this->post(route('admin.publisher-applications.start-review', $application))->assertForbidden();

        $publisherUser = $application->applicant;
        $this->actingAs($publisherUser)->get(route('admin.publisher-applications.index'))->assertForbidden();
    }

    public function test_existing_invitation_and_active_logins_remain_unchanged(): void
    {
        $publisherOrganization = $this->makeOrganization(OrganizationType::Publisher, 'Invited Publisher');
        $publisherAdmin = $this->makeUser($publisherOrganization, RoleName::PublisherAdmin, ['password' => 'existing-password']);
        Publisher::withoutGlobalScopes()->create(['organization_id' => $publisherOrganization->id, 'legal_name' => 'Invited LLC', 'display_name' => 'Invited']);
        $viewerRole = Role::query()->where('name', RoleName::PublisherViewer->value)->firstOrFail();
        [, $token] = app(InvitationService::class)->issue($publisherOrganization, 'viewer@invited.test', $viewerRole, $publisherAdmin);
        $viewer = app(InvitationService::class)->accept($token, 'Viewer', 'secure-password-123');
        $this->assertTrue($viewer->hasRole(RoleName::PublisherViewer->value));

        $this->post('/login', ['email' => $publisherAdmin->email, 'password' => 'existing-password'])->assertRedirect('/');
        $admin = $this->admin();
        $this->post('/logout');
        $this->post('/login', ['email' => $admin->email, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
    }

    public function test_repeated_approval_requests_cannot_duplicate_canonical_entities(): void
    {
        [, $application] = $this->submittedApplication();
        $admin = $this->admin();
        app(PublisherApplicationService::class)->startReview($application, $admin);
        $this->actingAs($admin)->withSession($this->adminSession())->post(route('admin.publisher-applications.approve', $application))->assertRedirect();
        $this->post(route('admin.publisher-applications.approve', $application->fresh()))->assertRedirect();

        $this->assertDatabaseCount('publisher_applications', 1);
        $this->assertDatabaseCount('publishers', 1);
        $this->assertDatabaseCount('user_roles', 2);
        $this->assertSame(1, AuditLog::query()->where('event', 'publisher_application.approved')->count());
    }

    private function registerApplicant(array $overrides = []): User
    {
        Config::set('publisher-applications.public_registration_enabled', true);
        $this->post('/register/publisher', $this->registrationPayload($overrides))->assertRedirect(route('verification.notice'));

        return User::query()->where('email', $overrides['email'] ?? 'owner@news.example')->firstOrFail();
    }

    private function readyDraft(): User
    {
        $user = $this->registerApplicant();
        $user->forceFill(['email_verified_at' => now()])->save();
        app(PublisherApplicationService::class)->emailVerified($user);
        $this->actingAs($user);

        return $user;
    }

    private function verifyWebsite(User $user): void
    {
        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $verification = app(ApplicationAdsTxtVerificationService::class);
        $reserved = $verification->reserve($application, $user);
        Http::fake(['*' => Http::response(implode("\n", $reserved['records'])."\n", 200, ['Content-Type' => 'text/plain'])]);
        $this->assertTrue($verification->verify($application->fresh(), $user)['verified']);
    }

    /** @return array{0: User, 1: PublisherApplication} */
    private function submittedApplication(): array
    {
        $user = $this->readyDraft();
        $this->put(route('publisher-application.update'), $this->applicationPayload())->assertSessionHasNoErrors();
        $this->verifyWebsite($user);
        $this->post(route('publisher-application.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        return [$user, PublisherApplication::withoutGlobalScopes()->firstOrFail()];
    }

    private function admin(): User
    {
        return $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'), RoleName::SuperAdmin);
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
            'primary_domain' => 'News.Example',
            'password' => 'Secure-Password-2026!',
            'password_confirmation' => 'Secure-Password-2026!',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function applicationPayload(array $overrides = []): array
    {
        return array_merge([
            'contact_name' => 'News Owner',
            'legal_name' => 'News Publisher LLC',
            'publisher_name' => 'News Publisher',
            'primary_domain' => 'news.example',
            'content_categories' => ['NEWS'],
            'content_description' => 'Original independent reporting and analysis.',
            'monthly_pageviews' => 100000,
            'organic_percent' => 50,
            'social_percent' => 10,
            'direct_percent' => 40,
            'paid_percent' => 0,
            'other_percent' => 0,
            'audience_countries' => ['US', 'GB'],
            'desktop_percent' => 35,
            'mobile_percent' => 60,
            'tablet_percent' => 5,
            'original_content' => 1,
            'user_generated_content' => 0,
            'ai_assisted_content' => 0,
            'sensitive_content' => 0,
            'has_privacy_policy' => 1,
            'has_contact_details' => 1,
            'has_cmp' => 1,
            'prior_policy_incidents' => 0,
            'monetization_history' => 'Previously used direct sponsorships.',
            'legal' => [
                'TERMS_OF_SERVICE' => 1,
                'PRIVACY_POLICY' => 1,
                'PUBLISHER_TERMS' => 1,
            ],
            'application_notes' => 'Available for manual verification.',
        ], $overrides);
    }
}
