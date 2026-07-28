<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SuperAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_super_administrator_is_created_securely_and_audited(): void
    {
        $this->artisan('horus:create-super-admin', ['email' => 'owner@horusmedia.net', '--name' => 'Horus Owner'])
            ->expectsQuestion('Password (minimum 12 characters)', 'secure-password-123')
            ->expectsQuestion('Confirm password', 'secure-password-123')
            ->expectsOutput('Super administrator created. Two-factor enrollment is required on first sign-in.')
            ->assertSuccessful();

        $user = User::where('email', 'owner@horusmedia.net')->firstOrFail();
        $this->assertSame(OrganizationType::HorusMedia, Organization::firstOrFail()->type);
        $this->assertTrue($user->hasRole(RoleName::SuperAdmin->value));
        $this->assertDatabaseHas('audit_logs', ['event' => 'bootstrap.super_admin.created', 'actor_id' => $user->id]);
    }
}
