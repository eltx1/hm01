<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class SuspensionTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_suspended_user_cannot_login(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin, ['password' => 'correct-password', 'status' => UserStatus::Suspended, 'suspended_at' => now()]);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'failure_reason' => 'account_inactive']);
    }

    public function test_suspending_user_is_audited(): void
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $admin = $this->makeUser($horus, RoleName::SuperAdmin);
        $target = $this->makeUser($this->makeOrganization(OrganizationType::Advertiser), RoleName::AdvertiserAdmin);

        $this->actingAs($admin)->patch(route('admin.users.suspend', $target))->assertRedirect();
        $this->assertSame(UserStatus::Suspended, $target->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.suspended', 'actor_id' => $admin->id, 'auditable_id' => $target->id]);
    }
}
