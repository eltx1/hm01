<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Role;
use App\Services\Identity\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_secure_single_use_invitation_creates_scoped_user(): void
    {
        Notification::fake();
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $admin = $this->makeUser($organization, RoleName::PublisherAdmin);
        $role = Role::where('name', RoleName::PublisherViewer->value)->firstOrFail();
        [$invitation, $token] = app(InvitationService::class)->issue($organization, 'viewer@example.com', $role, $admin);

        $user = app(InvitationService::class)->accept($token, 'Viewer', 'secure-password-123');

        $this->assertSame($organization->id, $user->organization_id);
        $this->assertTrue($user->hasRole(RoleName::PublisherViewer->value));
        $this->assertNotNull($invitation->fresh()->accepted_at);
        $this->expectException(HttpException::class);
        app(InvitationService::class)->accept($token, 'Again', 'secure-password-123');
    }

    public function test_publisher_team_invitation_form_is_scoped_and_hides_internal_roles(): void
    {
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher, 'Scoped Publisher');
        $admin = $this->makeUser($organization, RoleName::PublisherAdmin);

        $this->actingAs($admin)->get(route('admin.invitations.create'))
            ->assertOk()
            ->assertSee('Invite team member')
            ->assertSee('Scoped Publisher')
            ->assertSee('Publisher Admin')
            ->assertSee('Publisher Viewer')
            ->assertDontSee('Organization ID')
            ->assertDontSee('Super Admin')
            ->assertDontSee('Finance Admin');
    }

    public function test_publisher_cannot_invite_horus_administrator_role(): void
    {
        Notification::fake();
        $this->seedIdentity();
        $organization = $this->makeOrganization(OrganizationType::Publisher);
        $admin = $this->makeUser($organization, RoleName::PublisherAdmin);
        $superAdmin = Role::where('name', RoleName::SuperAdmin->value)->firstOrFail();

        $this->expectException(ValidationException::class);
        app(InvitationService::class)->issue($organization, 'unsafe@example.com', $superAdmin, $admin);
    }
}
