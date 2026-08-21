<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\PublisherApplicationStatus;
use App\Enums\RoleName;
use App\Models\PublisherApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

final class OptionalAuthenticationRequirementsTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
        Notification::fake();
    }

    public function test_dedicated_admin_login_goes_directly_to_control_plane_when_two_factor_is_disabled(): void
    {
        Config::set('security.authentication.administrator_2fa_required', false);
        $admin = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'),
            RoleName::SuperAdmin,
            ['password' => 'correct-password'],
        );

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame('admin', session('auth_surface'));
        $this->assertFalse(session()->has('two_factor_user_id'));
        $this->assertFalse(session()->has('two_factor_context'));
        $this->get('/admin/organizations')->assertOk();
    }

    public function test_admin_without_enrolled_two_factor_can_use_control_plane_when_two_factor_is_disabled(): void
    {
        Config::set('security.authentication.administrator_2fa_required', false);
        $admin = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'),
            RoleName::SuperAdmin,
            [
                'password' => 'correct-password',
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
            ],
        );

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($admin);
        $this->get('/admin/organizations')->assertOk();
    }

    public function test_public_login_ignores_existing_two_factor_enrollment_when_two_factor_is_disabled(): void
    {
        Config::set('security.authentication.administrator_2fa_required', false);
        $admin = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia, 'Horus Media'),
            RoleName::SuperAdmin,
            ['password' => 'correct-password'],
        );

        $this->post('/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertRedirect('/');

        $this->assertAuthenticatedAs($admin);
        $this->assertSame('public', session('auth_surface'));
        $this->assertFalse(session()->has('two_factor_user_id'));
    }

    public function test_publisher_registration_skips_email_verification_and_opens_application_when_disabled(): void
    {
        Config::set('security.authentication.email_verification_required', false);
        Config::set('publisher-applications.public_registration_enabled', true);

        $this->post('/register/publisher', [
            'name' => 'Publisher Owner',
            'email' => 'owner@publisher.example',
            'publisher_name' => 'Publisher Example',
            'primary_domain' => 'publisher.example',
            'password' => 'Secure-Password-2026!',
            'password_confirmation' => 'Secure-Password-2026!',
        ])->assertRedirect(route('publisher-application.show'));

        $user = User::query()->where('email', 'owner@publisher.example')->firstOrFail();
        $application = PublisherApplication::withoutGlobalScopes()->where('applicant_user_id', $user->id)->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertSame(PublisherApplicationStatus::Draft, $application->status);
        $this->get(route('publisher-application.show'))->assertOk();
        Notification::assertNothingSent();
    }

    public function test_production_template_defaults_to_simple_auth_and_safe_session_bootstrap(): void
    {
        $template = file_get_contents(base_path('.env.production.example'));
        $this->assertIsString($template);
        $this->assertStringContainsString('AUTH_EMAIL_VERIFICATION_REQUIRED=false', $template);
        $this->assertStringContainsString('AUTH_ADMIN_2FA_REQUIRED=false', $template);
        $this->assertStringContainsString('SESSION_COOKIE=horus-media-session', $template);
        $this->assertStringNotContainsString("\nMYSQL_ATTR_SSL_CA=\n", $template);
    }
}
