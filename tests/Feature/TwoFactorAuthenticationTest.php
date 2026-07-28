<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Services\Identity\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_horus_administrator_must_pass_totp_before_authentication(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin, ['password' => 'correct-password']);
        $code = app(TwoFactorService::class)->currentCode($admin->two_factor_secret);

        $this->post('/login', ['email' => $admin->email, 'password' => 'correct-password'])->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
        $this->post(route('two-factor.verify'), ['code' => $code])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.two_factor.challenge_passed', 'actor_id' => $admin->id]);
    }

    public function test_recovery_code_is_single_use(): void
    {
        $this->seedIdentity();
        $admin = $this->makeUser($this->makeOrganization(OrganizationType::HorusMedia), RoleName::SuperAdmin);
        $service = app(TwoFactorService::class);
        $admin->forceFill(['two_factor_recovery_codes' => $service->hashRecoveryCodes(['ABCD1234-EFGH5678'])])->save();

        $this->withSession(['two_factor_user_id' => $admin->id])->post(route('two-factor.verify'), ['code' => 'ABCD1234-EFGH5678'])->assertRedirect('/');
        $this->assertSame([], $admin->fresh()->two_factor_recovery_codes);
    }
}
