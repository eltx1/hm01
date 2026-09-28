<?php

use App\Enums\OrganizationType;
use App\Enums\RoleName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $name = 'video_player.manage';

        if (! DB::table('permissions')->where('name', $name)->exists()) {
            DB::table('permissions')->insert([
                'id' => (string) Str::ulid(),
                'name' => $name,
                'display_name' => 'Manage platform video player',
                'group' => 'settings',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $permissionId = DB::table('permissions')->where('name', $name)->value('id');
        if (! $permissionId) {
            return;
        }

        $roleIds = DB::table('roles')
            ->whereNull('organization_id')
            ->whereIn('name', [
                RoleName::SuperAdmin->value,
                RoleName::OperationsAdmin->value,
                RoleName::AdOpsAdmin->value,
            ])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        // Repair any historical permission drift on the platform-owner role.
        // SUPER_ADMIN must include every permission currently registered,
        // including permissions introduced by dedicated feature migrations.
        $superAdminRoleId = DB::table('roles')
            ->whereNull('organization_id')
            ->where('name', RoleName::SuperAdmin->value)
            ->value('id');

        if ($superAdminRoleId) {
            foreach (DB::table('permissions')->pluck('id') as $registeredPermissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $superAdminRoleId,
                    'permission_id' => $registeredPermissionId,
                ]);
            }

            // The bootstrap command records the original platform owner in the
            // immutable audit trail. If a later role edit accidentally detached
            // SUPER_ADMIN from that account, restore the role only for audited
            // bootstrap owners that still belong to the Horus Media organization.
            $bootstrapOwnerIds = DB::table('audit_logs')
                ->where('event', 'bootstrap.super_admin.created')
                ->whereNotNull('auditable_id')
                ->pluck('auditable_id')
                ->filter()
                ->unique();

            foreach ($bootstrapOwnerIds as $userId) {
                $isActiveHorusOwner = DB::table('users')
                    ->join('organizations', 'organizations.id', '=', 'users.organization_id')
                    ->where('users.id', $userId)
                    ->whereNull('users.deleted_at')
                    ->whereNull('organizations.deleted_at')
                    ->where('organizations.type', OrganizationType::HorusMedia->value)
                    ->exists();

                if (! $isActiveHorusOwner) {
                    continue;
                }

                DB::table('user_roles')->insertOrIgnore([
                    'user_id' => $userId,
                    'role_id' => $superAdminRoleId,
                    'assigned_by' => $userId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'video_player.manage')->value('id');
        if (! $permissionId) {
            return;
        }

        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();
    }
};
