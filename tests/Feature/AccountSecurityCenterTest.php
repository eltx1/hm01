<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Identity\AccountSessionService;
use App\Services\Identity\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

final class AccountSecurityCenterTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    private const CURRENT_PASSWORD = 'CurrentPassword1!';
    private const NEW_PASSWORD = 'ReplacementPassword2@';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentity();
    }

    public function test_account_center_requires_authentication_and_keeps_admin_login_separate(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
        $this->get('/account/profile')->assertRedirect(route('login'));
        $this->get('/account/security')->assertRedirect(route('login'));

        $this->assertTrue(app('router')->has('admin.login'));
        $this->assertSame('/admin/login', route('admin.login', absolute: false));
        $this->assertSame('/account', route('account.index', absolute: false));
    }

    public function test_user_can_update_only_own_name_without_mutating_identity_or_access_scope(): void
    {
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $user = $this->makeUser($organization, RoleName::PublisherAdmin, [
            'name' => 'Original Name',
            'email' => 'owner@example.test',
        ]);
        $foreign = $this->makeUser($organization, RoleName::PublisherUser, ['name' => 'Foreign Name']);
        $roleIds = $user->roles()->pluck('roles.id')->all();

        $this->actingAs($user)->patch(route('account.profile.update'), [
            'name' => 'Updated Name',
            'email' => 'attacker@example.test',
            'organization_id' => $this->makeOrganization(OrganizationType::Advertiser)->id,
            'role' => RoleName::SuperAdmin->value,
            'permissions' => ['organizations.manage'],
            'user_id' => $foreign->id,
        ])->assertRedirect(route('account.profile.edit'));

        $user->refresh();
        $this->assertSame('Updated Name', $user->name);
        $this->assertSame('owner@example.test', $user->email);
        $this->assertSame($organization->id, $user->organization_id);
        $this->assertSame($roleIds, $user->roles()->pluck('roles.id')->all());
        $this->assertSame('Foreign Name', $foreign->fresh()->name);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'account.profile.updated',
            'actor_id' => $user->id,
            'auditable_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->patch('/account/profile/'.$foreign->id, ['name' => 'Nope'])
            ->assertNotFound();
    }

    public function test_profile_page_displays_email_as_read_only_and_never_exposes_role_controls(): void
    {
        $user = $this->publisherUser();

        $this->actingAs($user)->get(route('account.profile.edit'))
            ->assertOk()
            ->assertSee($user->name)
            ->assertSee($user->email)
            ->assertSee('readonly', false)
            ->assertSee('autocomplete="name"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertDontSee('name="organization_id"', false)
            ->assertDontSee('name="role"', false)
            ->assertDontSee('name="permissions"', false);
    }

    public function test_password_change_requires_current_password_enforces_policy_invalidates_other_sessions_and_audits_without_password_values(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->publisherUser();
        $foreign = $this->publisherUser('foreign-password@example.test');
        $this->insertSession($user, 'owned-password-session', 'Mozilla/5.0 (Macintosh) Firefox/130.0', 'private-owned-payload');
        $this->insertSession($foreign, 'foreign-password-session', 'Mozilla/5.0 (Android) Chrome/130.0', 'private-foreign-payload');

        $this->actingAs($user)->put(route('account.security.password.update'), [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertRedirect(route('account.security'));

        $user->refresh();
        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->password));
        $this->assertNotNull($user->password_changed_at);
        $this->assertDatabaseMissing('sessions', ['id' => 'owned-password-session']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-password-session', 'user_id' => $foreign->id]);

        $audit = AuditLog::query()->where('event', 'account.password.changed')->where('actor_id', $user->id)->firstOrFail();
        $serializedAudit = strtolower(json_encode([
            $audit->old_values,
            $audit->new_values,
            $audit->metadata,
        ], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(strtolower(self::CURRENT_PASSWORD), $serializedAudit);
        $this->assertStringNotContainsString(strtolower(self::NEW_PASSWORD), $serializedAudit);
    }

    public function test_wrong_current_password_and_weak_new_password_are_rejected(): void
    {
        $user = $this->publisherUser();

        $this->actingAs($user)->put(route('account.security.password.update'), [
            'current_password' => 'WrongPassword1!',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->fresh()->password));

        $this->actingAs($user)->put(route('account.security.password.update'), [
            'current_password' => self::CURRENT_PASSWORD,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');
        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $user->fresh()->password));
    }

    public function test_publisher_can_enable_two_factor_with_existing_totp_service_and_receives_single_display_recovery_codes(): void
    {
        $user = $this->publisherUser();
        $service = app(TwoFactorService::class);

        $this->actingAs($user)
            ->post(route('account.security.two-factor.begin'))
            ->assertRedirect(route('account.security.two-factor.setup'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->actingAs($user)->get(route('account.security.two-factor.setup'))
            ->assertOk()
            ->assertSee($user->two_factor_secret)
            ->assertSee('autocomplete="one-time-code"', false);

        $code = $service->currentCode($user->two_factor_secret);
        $this->actingAs($user)->post(route('account.security.two-factor.confirm'), ['code' => $code])
            ->assertRedirect(route('account.security.two-factor.recovery-codes'))
            ->assertSessionHas('recovery_codes');

        $codes = session('recovery_codes');
        $this->assertIsArray($codes);
        $this->assertCount(10, $codes);
        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertCount(10, $user->two_factor_recovery_codes);
        $this->assertNotSame($codes[0], $user->two_factor_recovery_codes[0]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.two_factor.enabled', 'actor_id' => $user->id]);
    }

    public function test_recovery_code_regeneration_requires_password_and_factor_and_invalidates_previous_set(): void
    {
        $user = $this->publisherUser();
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecret();
        $oldHashes = $service->hashRecoveryCodes(['ABCD1234-EF567890']);
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $oldHashes,
        ])->save();

        $this->actingAs($user)->post(route('account.security.two-factor.recovery-codes.regenerate'), [
            'code' => $service->currentCode($secret),
        ])->assertSessionHasErrors('current_password');
        $this->assertSame($oldHashes, $user->fresh()->two_factor_recovery_codes);

        $this->actingAs($user)->post(route('account.security.two-factor.recovery-codes.regenerate'), [
            'current_password' => self::CURRENT_PASSWORD,
            'code' => $service->currentCode($secret),
        ])->assertRedirect(route('account.security.two-factor.recovery-codes'))
            ->assertSessionHas('recovery_codes');

        $fresh = $user->fresh();
        $this->assertCount(10, $fresh->two_factor_recovery_codes);
        $this->assertNotSame($oldHashes, $fresh->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.two_factor.recovery_regenerated', 'actor_id' => $user->id]);
    }

    public function test_customer_can_disable_two_factor_only_after_password_and_factor_confirmation(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->publisherUser();
        $service = app(TwoFactorService::class);
        $secret = $service->generateSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $service->hashRecoveryCodes(['ABCD1234-EF567890']),
        ])->save();
        $this->insertSession($user, 'owned-disable-session');

        $this->actingAs($user)->delete(route('account.security.two-factor.disable'), [
            'current_password' => 'WrongPassword1!',
            'code' => $service->currentCode($secret),
        ])->assertSessionHasErrors('code');
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        $this->actingAs($user)->delete(route('account.security.two-factor.disable'), [
            'current_password' => self::CURRENT_PASSWORD,
            'code' => $service->currentCode($secret),
        ])->assertRedirect(route('account.security'));

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertDatabaseMissing('sessions', ['id' => 'owned-disable-session']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.two_factor.disabled', 'actor_id' => $user->id]);
    }

    public function test_horus_staff_two_factor_requirement_cannot_be_bypassed_or_disabled_through_new_or_legacy_endpoints(): void
    {
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($organization, RoleName::SuperAdmin, ['password' => self::CURRENT_PASSWORD]);
        $service = app(TwoFactorService::class);
        $secret = $admin->two_factor_secret;
        $code = $service->currentCode($secret);

        $this->withSession(['two_factor_passed_at' => now()->timestamp])
            ->actingAs($admin)
            ->delete(route('account.security.two-factor.disable'), [
                'current_password' => self::CURRENT_PASSWORD,
                'code' => $code,
            ])->assertSessionHasErrors('two_factor');
        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);

        $this->withSession(['two_factor_passed_at' => now()->timestamp])
            ->actingAs($admin)
            ->delete(route('two-factor.disable'), [
                'password' => self::CURRENT_PASSWORD,
                'code' => $code,
            ])->assertSessionHasErrors('two_factor');
        $this->assertNotNull($admin->fresh()->two_factor_confirmed_at);

        $unenrolled = $this->makeUser($organization, RoleName::HorusStaff, [
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        $this->actingAs($unenrolled)->get(route('account.security'))
            ->assertRedirect(route('two-factor.setup'));
    }

    public function test_session_listing_is_current_user_scoped_and_never_exposes_raw_ids_payloads_or_ip_addresses(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->publisherUser();
        $foreign = $this->publisherUser('foreign-sessions@example.test');
        $this->insertSession($user, 'owned-session-raw-token', 'Mozilla/5.0 (Macintosh) Firefox/130.0', 'OWNED-PRIVATE-PAYLOAD', '192.0.2.11');
        $this->insertSession($foreign, 'foreign-session-raw-token', 'Mozilla/5.0 (Android) Chrome/130.0', 'FOREIGN-PRIVATE-PAYLOAD', '198.51.100.40');

        $response = $this->actingAs($user)->get(route('account.security'));
        $response->assertOk()
            ->assertSee('Firefox on macOS')
            ->assertDontSee('Chrome on Android')
            ->assertDontSee('owned-session-raw-token')
            ->assertDontSee('foreign-session-raw-token')
            ->assertDontSee('OWNED-PRIVATE-PAYLOAD')
            ->assertDontSee('FOREIGN-PRIVATE-PAYLOAD')
            ->assertDontSee('192.0.2.11')
            ->assertDontSee('198.51.100.40');
    }

    public function test_user_can_revoke_one_owned_other_session_but_cannot_revoke_foreign_session(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->publisherUser();
        $foreign = $this->publisherUser('foreign-revoke@example.test');
        $this->insertSession($user, 'owned-revoke-session');
        $this->insertSession($foreign, 'foreign-revoke-session');
        $service = app(AccountSessionService::class);

        $this->actingAs($user)->delete(route('account.security.sessions.revoke', $service->referenceFor('owned-revoke-session')))
            ->assertRedirect(route('account.security'));
        $this->assertDatabaseMissing('sessions', ['id' => 'owned-revoke-session']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'account.session.revoked', 'actor_id' => $user->id]);

        $this->actingAs($user)->delete(route('account.security.sessions.revoke', $service->referenceFor('foreign-revoke-session')))
            ->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-revoke-session', 'user_id' => $foreign->id]);
    }

    public function test_sign_out_all_other_sessions_deletes_only_current_users_other_sessions(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->publisherUser();
        $foreign = $this->publisherUser('foreign-signout@example.test');
        $this->insertSession($user, 'owned-other-one');
        $this->insertSession($user, 'owned-other-two');
        $this->insertSession($foreign, 'foreign-other');

        $this->actingAs($user)->delete(route('account.security.sessions.revoke-others'))
            ->assertRedirect(route('account.security'));

        $this->assertDatabaseMissing('sessions', ['id' => 'owned-other-one']);
        $this->assertDatabaseMissing('sessions', ['id' => 'owned-other-two']);
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-other', 'user_id' => $foreign->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'account.sessions.revoked_other', 'actor_id' => $user->id]);
    }

    public function test_security_summary_uses_existing_password_and_audit_evidence_without_inventing_history(): void
    {
        $user = $this->publisherUser();
        $user->forceFill(['password_changed_at' => now()->subDay()])->save();
        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_type' => $user->getMorphClass(),
            'actor_id' => $user->id,
            'event' => 'account.profile.updated',
        ]);
        $foreign = $this->publisherUser('foreign-audit@example.test');
        AuditLog::create([
            'organization_id' => $foreign->organization_id,
            'actor_type' => $foreign->getMorphClass(),
            'actor_id' => $foreign->id,
            'event' => 'account.password.changed',
        ]);

        $this->actingAs($user)->get(route('account.security'))
            ->assertOk()
            ->assertSee('Password last changed')
            ->assertSee('Two-factor authentication')
            ->assertSee('Profile updated')
            ->assertDontSee('Password changed');
    }

    public function test_csrf_password_manager_keyboard_mobile_and_paste_contracts_remain_explicit(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $profile = file_get_contents(resource_path('views/account/profile.blade.php'));
        $security = file_get_contents(resource_path('views/account/security.blade.php'));
        $setup = file_get_contents(resource_path('views/account/two-factor-setup.blade.php'));
        $css = file_get_contents(resource_path('css/app.css'));
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('validateCsrfTokens(except: [])', $bootstrap);
        $this->assertStringContainsString('@csrf', $profile);
        $this->assertStringContainsString('@csrf', $security);
        $this->assertStringContainsString('@csrf', $setup);
        $this->assertStringContainsString('autocomplete="current-password"', $security);
        $this->assertStringContainsString('autocomplete="new-password"', $security);
        $this->assertStringContainsString('autocomplete="one-time-code"', $setup);
        $this->assertStringContainsString('min-width: 320px', $css);
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringNotContainsString('outline: none', strtolower($css));
        $this->assertStringNotContainsString('onpaste=', strtolower($profile.$security.$setup));
        $this->assertStringNotContainsString('paste', strtolower($javascript));
    }

    private function publisherUser(?string $email = null): User
    {
        return $this->makeUser(
            $this->makeOrganization(OrganizationType::Publisher),
            RoleName::PublisherAdmin,
            array_filter([
                'email' => $email,
                'password' => self::CURRENT_PASSWORD,
            ], fn (mixed $value): bool => $value !== null),
        );
    }

    private function insertSession(
        User $user,
        string $id,
        string $userAgent = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0',
        string $payload = 'PRIVATE-SESSION-PAYLOAD',
        string $ipAddress = '203.0.113.10',
    ): void {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'payload' => $payload,
            'last_activity' => now()->timestamp,
        ]);
    }
}
