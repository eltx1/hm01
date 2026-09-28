<?php

namespace Tests\Feature;

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use App\Services\ControlPlane\ControlPlaneNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithIdentity;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use InteractsWithIdentity, RefreshDatabase;

    public function test_super_admin_runtime_and_navigation_remain_full_access_when_permission_pivot_drifts(): void
    {
        $this->seedIdentity();
        $horus = $this->makeOrganization(OrganizationType::HorusMedia);
        $super = $this->makeUser($horus, RoleName::SuperAdmin);
        $superRole = Role::whereNull('organization_id')->where('name', RoleName::SuperAdmin->value)->firstOrFail();

        $superRole->permissions()->detach();
        $super->unsetRelation('roles');

        $this->assertTrue($super->isSuperAdministrator());
        $this->assertTrue($super->hasPermission('settings.manage'));
        $this->assertTrue($super->hasPermission('roles.view'));

        $groups = app(ControlPlaneNavigation::class)->for($super);
        $labels = collect($groups)->flatMap(fn (array $group) => collect($group['items'])->pluck('label'));

        $this->assertTrue($labels->contains('Settings'));
        $this->assertTrue($labels->contains('Access control'));
    }

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
