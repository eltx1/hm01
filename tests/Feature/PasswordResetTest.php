<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_password_can_be_reset_with_valid_token_and_is_audited(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Advertiser), RoleName::AdvertiserAdmin);
        $token = Password::createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'New-Secure-Password-2026!', 'password_confirmation' => 'New-Secure-Password-2026!'])->assertRedirect('/login');

        $this->assertTrue(Hash::check('New-Secure-Password-2026!', $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password.reset', 'actor_id' => $user->id]);
    }
}
