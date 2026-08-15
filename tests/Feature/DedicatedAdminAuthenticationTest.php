<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\LoginEvent;
use App\Services\Identity\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

final class DedicatedAdminAuthenticationTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_staff_surface_is_branded_and_separate_from_customer_login(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('Staff Control Plane')
            ->assertSee('Secure Staff Access')
            ->assertSee('Horus Media')
            ->assertDontSee('APP_ENV');

        $this->get('/login')
            ->assertOk()
            ->assertSee('Publisher / Advertiser portal')
            ->assertDontSee('Secure Staff Access');
    }

    public function test_horus_admin_uses_admin_login_and_must_complete_two_factor(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia),
            RoleName::SuperAdmin,
            ['password' => 'correct-password'],
        );

        $this->post('/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->assertSame('admin', session('two_factor_context'));

        $code = app(TwoFactorService::class)->currentCode($admin->two_factor_secret);
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->assertSame('admin', session('auth_surface'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin_auth.succeeded', 'actor_id' => $admin->id]);
        $this->assertDatabaseHas('login_events', ['user_id' => $admin->id, 'successful' => true]);
    }

    public function test_non_horus_customer_identities_never_receive_admin_session(): void
    {
        $this->seedIdentity();
        $cases = [
            [OrganizationType::Publisher, RoleName::PublisherAdmin],
            [OrganizationType::Advertiser, RoleName::AdvertiserAdmin],
            [OrganizationType::Partner, RoleName::PartnerAdmin],
        ];

        foreach ($cases as [$type, $role]) {
            $user = $this->makeUser($this->makeOrganization($type), $role, ['password' => 'correct-password']);
            $response = $this->post('/admin/login', ['email' => $user->email, 'password' => 'correct-password']);
            $response->assertSessionHasErrors(['email' => 'The provided credentials or account status are invalid.']);
            $this->assertGuest();
            $this->assertDatabaseHas('login_events', [
                'user_id' => $user->id,
                'successful' => false,
                'failure_reason' => 'admin_non_horus_identity',
            ]);
            $this->assertDatabaseHas('audit_logs', ['event' => 'admin_auth.non_horus_denied', 'actor_id' => $user->id]);
        }
    }

    public function test_wrong_password_and_non_horus_valid_password_have_same_public_failure_message(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin, ['password' => 'correct-password']);
        $publisher = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin, ['password' => 'correct-password']);

        $wrong = $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong-password']);
        $nonHorus = $this->post('/admin/login', ['email' => $publisher->email, 'password' => 'correct-password']);

        $wrong->assertSessionHasErrors(['email' => 'The provided credentials or account status are invalid.']);
        $nonHorus->assertSessionHasErrors(['email' => 'The provided credentials or account status are invalid.']);
        $this->assertGuest();
    }

    public function test_locked_staff_account_is_denied_generically_and_audited(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia),
            RoleName::SuperAdmin,
            ['password' => 'correct-password', 'locked_until' => now()->addMinutes(20)],
        );

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'correct-password'])
            ->assertSessionHasErrors(['email' => 'The provided credentials or account status are invalid.']);

        $this->assertGuest();
        $this->assertDatabaseHas('login_events', [
            'user_id' => $admin->id,
            'successful' => false,
            'failure_reason' => 'admin_account_locked',
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin_auth.failed', 'actor_id' => $admin->id]);
    }

    public function test_admin_without_enrolled_two_factor_is_sent_to_enrollment_and_cannot_enter_control_plane(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia),
            RoleName::SuperAdmin,
            [
                'password' => 'correct-password',
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
            ],
        );

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'correct-password'])
            ->assertRedirect(route('two-factor.setup'));
        $this->assertAuthenticatedAs($admin);

        $this->get('/admin/organizations')->assertRedirect(route('two-factor.setup'));
    }

    public function test_intended_admin_destination_is_preserved_but_external_redirect_is_rejected(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin, ['password' => 'correct-password']);
        $service = app(TwoFactorService::class);

        $this->withSession(['url.intended' => '/admin/organizations'])
            ->post('/admin/login', ['email' => $admin->email, 'password' => 'correct-password'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->post(route('two-factor.verify'), ['code' => $service->currentCode($admin->two_factor_secret)])
            ->assertRedirect('/admin/organizations');

        $this->post('/logout');
        $this->withSession(['url.intended' => 'https://evil.example/phish'])
            ->post('/admin/login', ['email' => $admin->email, 'password' => 'correct-password'])
            ->assertRedirect(route('two-factor.challenge'));
        $this->post(route('two-factor.verify'), ['code' => $service->currentCode($admin->two_factor_secret)])
            ->assertRedirect('/');
    }

    public function test_admin_logout_invalidates_staff_surface_and_returns_to_admin_login(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia),
            RoleName::SuperAdmin,
            ['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'password' => 'correct-password'],
        );

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'correct-password']);
        $this->assertAuthenticatedAs($admin);

        $this->post('/logout')->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }

    public function test_admin_login_has_dedicated_strict_rate_limit(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin, ['password' => 'correct-password']);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong-password'])
                ->assertSessionHasErrors('email');
        }

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'wrong-password'])
            ->assertStatus(429);
    }

    public function test_public_login_still_authenticates_publisher_and_admin_routes_do_not_cross_tenant(): void
    {
        $this->seedIdentity();
        $publisher = $this->makeUser(
            $this->makeOrganization(OrganizationType::Publisher),
            RoleName::PublisherAdmin,
            ['password' => 'correct-password'],
        );

        $this->post('/login', ['email' => $publisher->email, 'password' => 'correct-password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($publisher);
        $this->assertSame('public', session('auth_surface'));
        $this->get('/admin/organizations')->assertForbidden();
    }

    public function test_guest_admin_destination_uses_staff_login_without_exposing_it_on_public_login(): void
    {
        $this->get('/admin/organizations')->assertRedirect(route('admin.login'));
        $this->get('/login')->assertOk()->assertDontSee('/admin/login');
    }

    public function test_existing_password_reset_and_invitation_routes_remain_registered(): void
    {
        $this->get('/forgot-password')->assertOk();
        $this->assertTrue(app('router')->has('password.reset'));
        $this->assertTrue(app('router')->has('invitations.accept.show'));
        $this->assertTrue(app('router')->has('invitations.accept'));
    }

    public function test_staff_primary_authentication_regenerates_session(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser(
            $this->makeOrganization(OrganizationType::HorusMedia),
            RoleName::SuperAdmin,
            [
                'password' => 'correct-password',
                'two_factor_secret' => null,
                'two_factor_confirmed_at' => null,
            ],
        );

        $this->withSession(['session_probe' => 'before']);
        $before = session()->getId();
        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'correct-password']);
        $after = session()->getId();

        $this->assertAuthenticatedAs($admin);
        $this->assertNotSame($before, $after);
    }

    public function test_staff_two_factor_failure_is_audited_without_creating_admin_session(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin, ['password' => 'correct-password']);

        $this->post('/admin/login', ['email' => $admin->email, 'password' => 'correct-password']);
        $this->post(route('two-factor.verify'), ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertSame('admin', session('two_factor_context'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'admin_auth.failed', 'actor_id' => $admin->id]);
        $this->assertSame('two_factor_invalid', LoginEvent::latest()->value('failure_reason'));
    }
}
