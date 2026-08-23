<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Mail\HorusNotificationMail;
use App\Mail\PublisherApplicantVerificationMail;
use App\Models\PublisherApplication;
use App\Models\PublisherApplicationLegalAcceptance;
use App\Models\PublisherApplicationMarketingConsent;
use App\Models\User;
use App\Services\PublisherApplications\ApplicationAdsTxtVerificationService;
use App\Services\PublisherApplications\PublisherApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class PremiumPublisherApplicationExperienceTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        Notification::fake();
        Cache::flush();
        Config::set('publisher-applications.public_registration_enabled', true);
        Config::set('publisher-applications.turnstile.enabled', false);
        Config::set('publisher-applications.support_url', 'mailto:support@horusmedia.net');
        Config::set('publisher-applications.legal_documents', [
            'TERMS_OF_SERVICE' => [
                'label' => 'Terms of Service', 'version' => '2026-08',
                'url' => 'https://horusmedia.net/legal/terms?v=2026-08', 'required' => true,
            ],
            'PRIVACY_POLICY' => [
                'label' => 'Privacy Policy', 'version' => '2026-08',
                'url' => 'https://horusmedia.net/legal/privacy?v=2026-08', 'required' => true,
            ],
        ]);
    }

    public function test_public_registration_is_branded_accessible_and_presents_the_express_contract(): void
    {
        $this->get(route('publisher-registration.create'))
            ->assertOk()->assertSee('Horus Media official logo')->assertSee('Apply as a Publisher')
            ->assertSee('Account')->assertSee('Company &amp; submit', false)->assertDontSee('Primary website or domain')
            ->assertSee('Need help?')->assertSee('support@horusmedia.net');

        $this->from(route('publisher-registration.create'))
            ->post(route('publisher-registration.store'), $this->registrationPayload(['name' => '']))
            ->assertRedirect(route('publisher-registration.create'))->assertSessionHasErrors('name');

        $this->get(route('publisher-registration.create'))->assertSee('role="alert"', false)->assertSee('Please correct the following:');
    }

    public function test_turnstile_requires_server_validation_and_rejects_invalid_expired_and_replayed_tokens(): void
    {
        Config::set('publisher-applications.turnstile', [
            'enabled' => true, 'site_key' => 'task29-site-key', 'secret_key' => 'server-only-secret',
            'expected_hostname' => 'localhost', 'action' => 'publisher_registration', 'provider' => 'fake',
            'timeout_seconds' => 5, 'test_token' => 'turnstile-test-valid',
        ]);

        $response = $this->get(route('publisher-registration.create'));
        $response->assertOk()->assertSee('task29-site-key')->assertSee('https://challenges.cloudflare.com/turnstile/v0/api.js', false);
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('script-src', $csp);
        $this->assertStringContainsString('frame-src', $csp);
        $this->assertStringContainsString('https://challenges.cloudflare.com', $csp);
        $this->assertStringNotContainsString('script-src *', $csp);
        $this->assertStringNotContainsString('frame-src *', $csp);
        $this->assertStringNotContainsString('unsafe-eval', $csp);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.1'])->post(route('publisher-registration.store'), $this->registrationPayload())->assertSessionHasErrors('cf-turnstile-response');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.2'])->post(route('publisher-registration.store'), $this->registrationPayload(['cf-turnstile-response' => 'bad-token']))->assertSessionHasErrors('cf-turnstile-response');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.3'])->post(route('publisher-registration.store'), $this->registrationPayload(['cf-turnstile-response' => 'turnstile-test-expired']))->assertSessionHasErrors('cf-turnstile-response');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.4'])->post(route('publisher-registration.store'), $this->registrationPayload(['cf-turnstile-response' => 'turnstile-test-valid']))->assertRedirect(route('verification.notice'));
        $this->post(route('logout'));
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.5'])->post(route('publisher-registration.store'), $this->registrationPayload([
            'email' => 'second@publisher.test', 'primary_domain' => 'second-publisher.example', 'cf-turnstile-response' => 'turnstile-test-valid',
        ]))->assertSessionHasErrors('cf-turnstile-response');
        $this->assertDatabaseCount('publisher_applications', 1);
    }

    public function test_turnstile_disabled_does_not_require_a_token(): void
    {
        $this->post(route('publisher-registration.store'), $this->registrationPayload())->assertRedirect(route('verification.notice'));
        $this->assertDatabaseCount('publisher_applications', 1);
    }

    public function test_honeypot_and_existing_rate_limit_are_both_enforced(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->post(route('publisher-registration.store'), $this->registrationPayload(['_company_website' => 'bot.example']))->assertSessionHasErrors('_company_website');
        $this->assertDatabaseCount('publisher_applications', 0);
        $invalid = $this->registrationPayload(['password_confirmation' => 'different']);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->post(route('publisher-registration.store'), $invalid)->assertSessionHasErrors('password');
        }
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.11'])->post(route('publisher-registration.store'), $invalid)->assertTooManyRequests();
    }

    public function test_applicant_can_save_each_decision_step_resume_and_keep_validation_input(): void
    {
        $user = $this->readyDraft();
        $this->from(route('publisher-application.show', ['step' => 2]))->put(route('publisher-application.update'), [
            'step' => 2, 'contact_name' => '', 'legal_name' => 'Publisher LLC', 'publisher_name' => 'Publisher', 'primary_domain' => 'publisher.example',
        ])->assertSessionHasErrors('contact_name');
        $this->get(route('publisher-application.show', ['step' => 2]))->assertSee('role="alert"', false)->assertSee('Publisher LLC');

        $this->put(route('publisher-application.update'), $this->websiteStepPayload())->assertRedirect(route('publisher-application.show', ['step' => 2]));
        $this->verifyWebsite($user);
        $this->put(route('publisher-application.update'), $this->qualityStepPayload())->assertRedirect(route('publisher-application.show', ['step' => 4]));

        $this->post(route('logout'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Secure-Password-2026!'])->assertRedirect(route('publisher-application.show'));
        $this->get(route('publisher-application.show', ['step' => 3]))->assertOk()->assertSee('Original independent reporting and analysis.')->assertSee('Last saved')->assertSee('Application status');
    }

    public function test_legal_acceptance_is_version_exact_historical_and_marketing_is_independent(): void
    {
        $user = $this->readyQualityDraft();
        $this->post(route('publisher-application.submit'), ['confirm' => 1])->assertSessionHasErrors(['legal.TERMS_OF_SERVICE', 'legal.PRIVACY_POLICY']);
        $this->put(route('publisher-application.update'), [
            'step' => 4, 'legal' => ['TERMS_OF_SERVICE' => 1, 'PRIVACY_POLICY' => 1], 'marketing_opt_in' => 0,
        ])->assertRedirect(route('publisher-application.show', ['step' => 5]));
        $this->assertDatabaseHas('publisher_application_legal_acceptances', [
            'user_id' => $user->id, 'document_type' => 'TERMS_OF_SERVICE', 'document_version' => '2026-08',
            'canonical_url' => 'https://horusmedia.net/legal/terms?v=2026-08',
        ]);
        $this->assertDatabaseHas('publisher_application_marketing_consents', ['user_id' => $user->id, 'opted_in' => 0]);
        $this->post(route('publisher-application.submit'), ['confirm' => 1])->assertSessionHasNoErrors();
        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(PublisherApplicationStatus::Submitted, $application->status);

        Config::set('publisher-applications.legal_documents.TERMS_OF_SERVICE.version', '2026-09');
        Config::set('publisher-applications.legal_documents.TERMS_OF_SERVICE.url', 'https://horusmedia.net/legal/terms?v=2026-09');
        $admin = $this->admin();
        $service = app(PublisherApplicationService::class);
        $service->startReview($application, $admin);
        $service->requestMoreInformation($application, $admin, 'Please confirm the updated application information.');
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '203.0.113.31'])->post(route('publisher-application.submit'), ['confirm' => 1])->assertSessionHasErrors('legal.TERMS_OF_SERVICE');
        $this->assertSame(1, PublisherApplicationLegalAcceptance::query()->where('document_type', 'TERMS_OF_SERVICE')->where('document_version', '2026-08')->count());
        $this->assertDatabaseMissing('publisher_application_legal_acceptances', ['document_type' => 'TERMS_OF_SERVICE', 'document_version' => '2026-09']);
        $this->assertFalse(PublisherApplicationMarketingConsent::query()->latest('recorded_at')->firstOrFail()->opted_in);
    }

    public function test_verification_received_and_lifecycle_emails_use_branded_application_communications(): void
    {
        Mail::fake();
        $this->post(route('publisher-registration.store'), $this->registrationPayload())->assertRedirect(route('verification.notice'));
        $user = User::query()->where('email', 'owner@publisher.example')->firstOrFail();
        Mail::assertSent(PublisherApplicantVerificationMail::class, fn (PublisherApplicantVerificationMail $mail) => $mail->hasTo($user->email));
        $user->forceFill(['email_verified_at' => now()])->save();
        app(PublisherApplicationService::class)->emailVerified($user);
        $this->actingAs($user);
        $this->put(route('publisher-application.update'), $this->websiteStepPayload())->assertRedirect();
        $this->verifyWebsite($user);
        $this->put(route('publisher-application.update'), $this->qualityStepPayload())->assertRedirect();
        $this->put(route('publisher-application.update'), ['step' => 4, 'legal' => ['TERMS_OF_SERVICE' => 1, 'PRIVACY_POLICY' => 1], 'marketing_opt_in' => 0])->assertRedirect();
        $this->post(route('publisher-application.submit'), ['confirm' => 1])->assertSessionHasNoErrors();

        Mail::fake();
        $this->artisan('notifications:deliver-email')->assertSuccessful();
        Mail::assertSent(HorusNotificationMail::class, fn (HorusNotificationMail $mail) => $mail->hasTo($user->email) && $mail->item->type === 'PUBLISHER_APPLICATION_RECEIVED');
    }

    public function test_status_page_hides_internal_review_metadata_and_admin_sees_review_evidence(): void
    {
        $user = $this->readyQualityDraft();
        $this->put(route('publisher-application.update'), ['step' => 4, 'legal' => ['TERMS_OF_SERVICE' => 1, 'PRIVACY_POLICY' => 1], 'marketing_opt_in' => 1])->assertRedirect();
        $this->get(route('publisher-application.show', ['step' => 5]))->assertOk()->assertSee('THOTH advisory cannot approve or reject')->assertDontSee('internal risk score')->assertDontSee('provider secret')->assertDontSee('staff notes');

        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $admin = $this->admin();
        $this->actingAs($admin)->withSession(['two_factor_passed_at' => now()->timestamp])->get(route('admin.publisher-applications.show', $application))
            ->assertOk()->assertSee('Publisher quality evidence')->assertSee('Legal documents &amp; consent', false)->assertSee('Terms Of Service')->assertSee('2026-08')
            ->assertSee('Optional marketing consent')->assertSee('THOTH may append advisory evidence but can never approve or reject');
    }

    public function test_task29_keeps_csrf_closed_and_mobile_and_vite_sources_are_explicit(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $this->assertStringContainsString('validateCsrfTokens(except: [])', $bootstrap);
        $this->assertStringContainsString("'cf-turnstile-response'", $bootstrap);
        $css = file_get_contents(resource_path('css/publisher-application.css'));
        $this->assertStringContainsString('repeat(5, minmax(0, 1fr))', $css);
        $this->assertStringContainsString('@media (max-width: 800px)', $css);
        $this->assertStringContainsString('@media (max-width: 430px)', $css);
        $javascript = file_get_contents(resource_path('js/app.js'));
        $this->assertStringContainsString("import '../css/publisher-application.css';", $javascript);
        $vite = file_get_contents(base_path('vite.config.js'));
        $this->assertStringContainsString("'resources/css/app.css'", $vite);
        $this->assertStringContainsString("'resources/js/app.js'", $vite);
    }

    private function readyDraft(): User
    {
        $this->post(route('publisher-registration.store'), $this->registrationPayload())->assertRedirect(route('verification.notice'));
        $user = User::query()->where('email', 'owner@publisher.example')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();
        app(PublisherApplicationService::class)->emailVerified($user);
        $this->actingAs($user);

        return $user;
    }

    private function readyQualityDraft(): User
    {
        $user = $this->readyDraft();
        $this->put(route('publisher-application.update'), $this->websiteStepPayload())->assertRedirect(route('publisher-application.show', ['step' => 2]));
        $this->verifyWebsite($user);
        $this->put(route('publisher-application.update'), $this->qualityStepPayload())->assertRedirect(route('publisher-application.show', ['step' => 4]));

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

    private function admin(): User
    {
        return $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'), RoleName::SuperAdmin);
    }

    /** @return array<string, mixed> */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Publisher Owner', 'email' => 'owner@publisher.example', 'publisher_name' => 'Publisher Example',
            'primary_domain' => 'publisher.example', 'password' => 'Secure-Password-2026!', 'password_confirmation' => 'Secure-Password-2026!', '_company_website' => '',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function websiteStepPayload(): array
    {
        return ['step' => 2, 'contact_name' => 'Publisher Owner', 'legal_name' => 'Publisher Example LLC', 'publisher_name' => 'Publisher Example', 'primary_domain' => 'publisher.example'];
    }

    /** @return array<string, mixed> */
    private function qualityStepPayload(): array
    {
        return [
            'step' => 3, 'content_categories' => ['NEWS'], 'content_description' => 'Original independent reporting and analysis.',
            'monthly_pageviews' => 150000, 'organic_percent' => 55, 'social_percent' => 10, 'direct_percent' => 30, 'paid_percent' => 5, 'other_percent' => 0,
            'audience_countries' => ['US', 'GB'], 'desktop_percent' => 35, 'mobile_percent' => 60, 'tablet_percent' => 5,
            'original_content' => 1, 'user_generated_content' => 0, 'ai_assisted_content' => 0, 'sensitive_content' => 0,
            'has_privacy_policy' => 1, 'has_contact_details' => 1, 'has_cmp' => 1, 'prior_policy_incidents' => 0,
            'monetization_history' => 'Direct sponsorships and programmatic monetization.', 'application_notes' => 'Available for manual verification.',
        ];
    }
}
