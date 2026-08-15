<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\PublisherApplication;
use App\Models\User;
use App\Services\PublisherApplications\PublisherApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class Task29AdminViewDiagnosticTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_admin_application_view_renders_without_wrapped_exception(): void
    {
        $this->seedIdentity();
        Notification::fake();
        Config::set('publisher-applications.public_registration_enabled', true);
        Config::set('publisher-applications.turnstile.enabled', false);
        Config::set('publisher-applications.legal_documents', [
            'TERMS_OF_SERVICE' => [
                'label' => 'Terms of Service',
                'version' => '2026-08',
                'url' => 'https://horusmedia.net/legal/terms?v=2026-08',
                'required' => true,
            ],
            'PRIVACY_POLICY' => [
                'label' => 'Privacy Policy',
                'version' => '2026-08',
                'url' => 'https://horusmedia.net/legal/privacy?v=2026-08',
                'required' => true,
            ],
        ]);

        $this->post(route('publisher-registration.store'), [
            'name' => 'Publisher Owner',
            'email' => 'owner@publisher.example',
            'publisher_name' => 'Publisher Example',
            'primary_domain' => 'publisher.example',
            'password' => 'Secure-Password-2026!',
            'password_confirmation' => 'Secure-Password-2026!',
            '_company_website' => '',
        ])->assertRedirect(route('verification.notice'));

        $user = User::query()->where('email', 'owner@publisher.example')->firstOrFail();
        $user->forceFill(['email_verified_at' => now()])->save();
        app(PublisherApplicationService::class)->emailVerified($user);
        $this->actingAs($user);

        $this->put(route('publisher-application.update'), [
            'step' => 3,
            'content_categories' => ['NEWS'],
            'content_description' => 'Original independent reporting and analysis.',
            'monthly_pageviews' => 150000,
            'organic_percent' => 55,
            'social_percent' => 10,
            'direct_percent' => 30,
            'paid_percent' => 5,
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
        ])->assertRedirect();

        $this->put(route('publisher-application.update'), [
            'step' => 4,
            'legal' => ['TERMS_OF_SERVICE' => 1, 'PRIVACY_POLICY' => 1],
            'marketing_opt_in' => 1,
        ])->assertRedirect();

        $application = PublisherApplication::withoutGlobalScopes()->firstOrFail();
        $admin = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'),
            RoleName::SuperAdmin,
        );

        $this->withoutExceptionHandling();
        $this->actingAs($admin)
            ->withSession(['two_factor_passed_at' => now()->timestamp])
            ->get(route('admin.publisher-applications.show', $application))
            ->assertOk();
    }
}
