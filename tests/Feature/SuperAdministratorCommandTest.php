<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class SuperAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_super_administrator_is_created_securely_and_audited(): void
    {
        $this->artisan('horus:create-super-admin', ['email' => 'owner@horusmedia.net', '--name' => 'Horus Owner'])
            ->expectsQuestion('Password (minimum 14 characters, mixed case, number, symbol)', 'Secure-Password-2026!')
            ->expectsQuestion('Confirm password', 'Secure-Password-2026!')
            ->expectsOutput('Super administrator created. Two-factor enrollment is required on first sign-in.')
            ->assertSuccessful();

        $user = User::where('email', 'owner@horusmedia.net')->firstOrFail();
        $this->assertSame(OrganizationType::HorusMedia, Organization::firstOrFail()->type);
        $this->assertTrue($user->hasRole(RoleName::SuperAdmin->value));
        $this->assertNotNull($user->activated_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'bootstrap.super_admin.created', 'actor_id' => $user->id]);
    }

    public function test_initial_super_administrator_does_not_require_two_factor_when_disabled(): void
    {
        Config::set('security.authentication.administrator_2fa_required', false);

        $this->artisan('horus:create-super-admin', ['email' => 'owner-no-2fa@horusmedia.net', '--name' => 'Horus Owner'])
            ->expectsQuestion('Password (minimum 14 characters, mixed case, number, symbol)', 'Secure-Password-2026!')
            ->expectsQuestion('Confirm password', 'Secure-Password-2026!')
            ->expectsOutput('Super administrator created.')
            ->assertSuccessful();

        $user = User::where('email', 'owner-no-2fa@horusmedia.net')->firstOrFail();
        $this->assertNotNull($user->activated_at);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_video_permission_migration_restores_audited_bootstrap_owner_role(): void
    {
        $this->artisan('horus:create-super-admin', ['email' => 'owner-repair@horusmedia.net', '--name' => 'Horus Owner'])
            ->expectsQuestion('Password (minimum 14 characters, mixed case, number, symbol)', 'Secure-Password-2026!')
            ->expectsQuestion('Confirm password', 'Secure-Password-2026!')
            ->assertSuccessful();

        $user = User::where('email', 'owner-repair@horusmedia.net')->firstOrFail();
        $superRole = \App\Models\Role::whereNull('organization_id')
            ->where('name', RoleName::SuperAdmin->value)
            ->firstOrFail();

        $user->roles()->detach($superRole->id);
        $user->unsetRelation('roles');
        $this->assertFalse($user->hasRole(RoleName::SuperAdmin->value));

        $migration = require database_path('migrations/2026_09_28_210000_add_video_player_manage_permission.php');
        $migration->up();

        $user->unsetRelation('roles');
        $this->assertTrue($user->hasRole(RoleName::SuperAdmin->value));
        $this->assertTrue($user->hasPermission('settings.manage'));
        $this->assertTrue($user->hasPermission('roles.view'));
    }

    public function test_initial_super_administrator_rejects_a_weak_password(): void
    {
        $this->artisan('horus:create-super-admin', ['email' => 'weak-owner@horusmedia.net', '--name' => 'Weak Owner'])
            ->expectsQuestion('Password (minimum 14 characters, mixed case, number, symbol)', 'weak-password')
            ->expectsQuestion('Confirm password', 'weak-password')
            ->expectsOutput('Invalid email, name, password policy, or confirmation.')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'weak-owner@horusmedia.net']);
    }
}
