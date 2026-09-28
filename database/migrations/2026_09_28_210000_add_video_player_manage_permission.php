<?php

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
