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

    public function test_account_center_requires_authentication_and_does_not_replace_staff_login(): void
    {
        $this->get('/account')->assertRedirect(route('login'));
        $this->get('/account/profile')->assertRedirect(route('login'));
        $this->get('/account/security')->assertRedirect(route('login'));

        $this->assertSame('/admin/login', route('admin.login', absolute: false));
        $this->assertSame('/account', route('account.index', absolute: false));
    }

    public function test_own_profile_name_can_change_without_email_organization_role_permission_or_foreign_user_mutation(): void
    {
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $user = $this->makeUser($organization, RoleName::PublisherAdmin, [
            'name' => 'Original Name',
            'email' => 'owner@example.test',
        ]);
        $foreign = $this->makeUser($organization, RoleName::PublisherViewer, ['name' => 'Foreign Name']);
        $roleIds = $user->roles()->pluck('roles.id')->all();
        $attackerOrganization = $this->makeOrganization(OrganizationType::Advertiser);

        $this->actingAs($user)->patch(route('account.profile.update'), [
            'name' => 'Updated Name',
            'email' => 'attacker@example.test',
            'organization_id' => $attackerOrganization->id,
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

        $this->actingAs($user)->patch('/account/profile/'.$foreign->id, ['name' => 'Nope'])->assertNotFound();
    }

    public function test_profile_page_has_read_only_email_and_no_access_control_fields(): void
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

    public function test_password_change_requires_current_password_uses_existing_policy_invalidates_other_sessions_and_audits_safely(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->publisherUser();
        $foreign = $this->publisherUser('foreign-password@example.test');
        $this->insertSession($user, 'owned-password-session');
        $this->insertSession($foreign, 'foreign-password-session');

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
        $safeAudit = strtolower(json_encode([$audit->old_values, $audit->new_values, $audit->metadata], JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(strtolower(self::CURRENT_PASSWORD), $safeAudit);
        $this->assertStringNotContainsString(strtolower(self::NEW_PASSWORD), $safeAudit);
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

    public function test_publisher_two_factor_enablement_reuses_existing_totp_and_secure_recovery_code_storage(): void
    {
        $user = $this->publisherUser();
        $service = app(TwoFactorService::class);

        $this->actingAs($user)->post(route('account.security.two-factor.begin'))
            ->assertRedirect(route('account.security.two-factor.setup'));
        $user->refresh();
        $this->assertNotNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);

        $this->actingAs($user)->get(route('account.security.two-factor.setup'))
            ->assertOk()
            ->assertSee($user->two_factor_secret)
            ->assertSee('autocomplete="one-time-code"', false);

        $this->actingAs($user)->post(route('account.security.two-factor.confirm'), [
            'code' => $service->currentCode($user->two_factor_secret),
        ])->assertRedirect(route('account.security.two-factor.recovery-codes'))
            ->assertSessionHas('recovery_codes');

        $plainCodes = session('recovery_codes');
        $this->assertIsArray($plainCodes);
        $this->assertCount(10, $plainCodes);
        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertCount(10, $user->two_factor_recovery_codes);
        $this->assertNotSame($plainCodes[0], $user->two_factor_recovery_codes[0]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.two_factor.enabled', 'actor_id' => $user->id]);
    }

    public function test_recovery_code_regeneration_requires_password_and_factor_on_new_and_legacy_paths(): void
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
        $code = $service->currentCode($secret);

        $this->actingAs($user)->post(route('account.security.two-factor.recovery-codes.regenerate'), ['code' => $code])
            ->assertSessionHasErrors('current_password');
        $this->assertSame($oldHashes, $user->fresh()->two_factor_recovery_codes);

        $this->actingAs($user)->post(route('two-factor.recovery-codes.regenerate'), ['code' => $code])
            ->assertSessionHasErrors('password');
        $this->assertSame($oldHashes, $user->fresh()->two_factor_recovery_codes);

        $this->actingAs($user)->post(route('account.security.two-factor.recovery-codes.regenerate'), [
            'current_password' => self::CURRENT_PASSWORD,
            'code' => $code,
        ])->assertRedirect(route('account.security.two-factor.recovery-codes'))
            ->assertSessionHas('recovery_codes');

        $this->assertCount(10, $user->fresh()->two_factor_recovery_codes);
        $this->assertNotSame($oldHashes, $user->fresh()->two_factor_recovery_codes);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.two_factor.recovery_regenerated', 'actor_id' => $user->id]);
    }

    public function test_customer_two_factor_disable_requires_password_and_factor_and_revokes_other_sessions(): void
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
        $code = $service->currentCode($secret);

        $this->actingAs($user)->delete(route('account.security.two-factor.disable'), [
            'current_password' => 'WrongPassword1!',
            'code' => $code,
        ])->assertSessionHasErrors('code');
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        $this->actingAs($user)->delete(route('account.security.two-factor.disable'), [
            'current_password' => self::CURRENT_PASSWORD,
            'code' => $code,
        ])->assertRedirect(route('account.security'));

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertDatabaseMissing('sessions', ['id' => 'owned-disable-session']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.two_factor.disabled', 'actor_id' => $user->id]);
    }

    public function test_horus_staff_two_factor_cannot_be_disabled_and_account_center_cannot_bypass_required_enrollment(): void
    {
        $organization = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($organization, RoleName::SuperAdmin, ['password' => self::CURRENT_PASSWORD]);
        $service = app(TwoFactorService::class);
        $code = $service->currentCode($admin->two_factor_secret);

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

        $unenrolled = $this->makeUser($organization, RoleName::OperationsAdmin, [
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        $this->actingAs($unenrolled)->get(route('account.security'))
            ->assertRedirect(route('two-factor.setup'));
    }

    public function test_session_listing_is_isolated_and_never_exposes_raw_session_ids_payloads_or_ip(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->publisherUser();
        $foreign = $this->publisherUser('foreign-sessions@example.test');
        $this->insertSession($user, 'owned-session-raw-token', 'Mozilla/5.0 (Macintosh) Firefox/130.0', 'OWNED-PRIVATE-PAYLOAD', '192.0.2.11');
        $this->insertSession($foreign, 'foreign-session-raw-token', 'Mozilla/5.0 (Android) Chrome/130.0', 'FOREIGN-PRIVATE-PAYLOAD', '198.51.100.40');

        $this->actingAs($user)->get(route('account.security'))
            ->assertOk()
            ->assertSee('Firefox on macOS')
            ->assertDontSee('Chrome on Android')
            ->assertDontSee('owned-session-raw-token')
            ->assertDontSee('foreign-session-raw-token')
            ->assertDontSee('OWNED-PRIVATE-PAYLOAD')
            ->assertDontSee('FOREIGN-PRIVATE-PAYLOAD')
            ->assertDontSee('192.0.2.11')
            ->assertDontSee('198.51.100.40');
    }

    public function test_one_owned_session_can_be_revoked_but_foreign_session_reference_is_denied(): void
    {
        config(['session.driver' => 'database']);
        $user = $this->publisherUser();
        $foreign = $this->publisherUser('foreign-revoke@example.test');
        $this->insertSession($user, 'owned-revoke-session');
        $this->insertSession($foreign, 'foreign-revoke-session');
        $sessions = app(AccountSessionService::class);

        $ownReference = $sessions->referenceFor('owned-revoke-session');
        $this->assertNotSame('owned-revoke-session', $ownReference);
        $this->actingAs($user)->delete(route('account.security.sessions.revoke', $ownReference))
            ->assertRedirect(route('account.security'));
        $this->assertDatabaseMissing('sessions', ['id' => 'owned-revoke-session']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'account.session.revoked', 'actor_id' => $user->id]);

        $foreignReference = $sessions->referenceFor('foreign-revoke-session');
        $this->actingAs($user)->delete(route('account.security.sessions.revoke', $foreignReference))->assertNotFound();
        $this->assertDatabaseHas('sessions', ['id' => 'foreign-revoke-session', 'user_id' => $foreign->id]);
    }

    public function test_sign_out_all_other_sessions_is_strictly_current_user_scoped(): void
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

    public function test_security_summary_uses_existing_password_change_and_safe_audit_evidence_only(): void
    {
        $user = $this->publisherUser();
        $user->forceFill(['password_changed_at' => now()->subDay()])->save();
        AuditLog::create([
            'organization_id' => $user->organization_id,
            'actor_type' => $user->getMorphClass(),
            'actor_id' => $user->id,
            'event' => 'auth.two_factor.enabled',
        ]);
        $foreign = $this->publisherUser('foreign-audit@example.test');
        AuditLog::create([
            'organization_id' => $foreign->organization_id,
            'actor_type' => $foreign->getMorphClass(),
            'actor_id' => $foreign->id,
            'event' => 'auth.two_factor.disabled',
        ]);

        $this->actingAs($user)->get(route('account.security'))
            ->assertOk()
            ->assertSee('Password last changed')
            ->assertSee('Two-factor authentication enabled')
            ->assertDontSee('Two-factor authentication disabled');
    }

    public function test_csrf_accessibility_mobile_password_manager_and_paste_contracts_are_preserved(): void
    {
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));
        $profile = file_get_contents(resource_path('views/account/profile.blade.php'));
        $security = file_get_contents(resource_path('views/account/security.blade.php'));
        $setup = file_get_contents(resource_path('views/account/two-factor-setup.blade.php'));
        $css = file_get_contents(resource_path('css/app.css'));
        $javascript = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('validateCsrfTokens(except: [])', $bootstrap);
        $this->assertStringContainsString('@csrf', $profile.$security.$setup);
        $this->assertStringContainsString('for="account-name"', $profile);
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
        $attributes = ['password' => self::CURRENT_PASSWORD];
        if ($email !== null) {
            $attributes['email'] = $email;
        }

        return $this->makeUser(
            $this->makeOrganization(OrganizationType::Publisher),
            RoleName::PublisherAdmin,
            $attributes,
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
