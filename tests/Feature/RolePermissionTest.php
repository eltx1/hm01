<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_only_authorized_horus_admin_can_change_permissions_and_change_is_audited(): void
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $super = $this->makeUser($horus, RoleName::SuperAdmin);
        $role = Role::where('name', RoleName::SupportAgent->value)->firstOrFail();
        $permission = Permission::where('name', 'roles.view')->firstOrFail();

        $this->actingAs($super)->withSession(['two_factor_passed_at' => now()->timestamp])->put(route('admin.roles.permissions.sync', $role), ['permissions' => [$permission->id]])->assertRedirect();
        $this->assertDatabaseHas('role_permissions', ['role_id' => $role->id, 'permission_id' => $permission->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'permission.role.updated', 'actor_id' => $super->id]);

        $publisher = $this->makeUser($this->makeOrganization(OrganizationType::Publisher), RoleName::PublisherAdmin);
        $this->actingAs($publisher)->put(route('admin.roles.permissions.sync', $role), ['permissions' => []])->assertForbidden();
    }
}
