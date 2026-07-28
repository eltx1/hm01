<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\LoginEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_active_verified_user_can_login_and_logout(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin, ['password' => 'correct-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'correct-password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('login_events', ['user_id' => $user->id, 'successful' => true]);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_failed_login_is_tracked_without_disclosing_account(): void
    {
        $this->seedIdentity();
        $user = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherViewer);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertSame(1, $user->fresh()->failed_login_count);
        $this->assertSame('invalid_credentials', LoginEvent::latest()->value('failure_reason'));
    }
}
