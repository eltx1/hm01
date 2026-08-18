<?php

use App\Enums\RoleName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            'traffic_gate.manage' => 'Manage Client Traffic Gate',
            'traffic_gate.emergency_disable' => 'Emergency disable Client Traffic Gate',
        ];

        foreach ($definitions as $name => $displayName) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'name' => $name,
                    'display_name' => $displayName,
                    'group' => 'operations',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $manageId = DB::table('permissions')->where('name', 'traffic_gate.manage')->value('id');
        $emergencyId = DB::table('permissions')->where('name', 'traffic_gate.emergency_disable')->value('id');
        $roles = DB::table('roles')->whereNull('organization_id')
            ->whereIn('name', [RoleName::SuperAdmin->value, RoleName::OperationsAdmin->value, RoleName::AdOpsAdmin->value])
            ->get(['id', 'name']);

        foreach ($roles as $role) {
            if ($manageId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $manageId]);
            }
            if ($emergencyId && in_array($role->name, [RoleName::SuperAdmin->value, RoleName::OperationsAdmin->value], true)) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $emergencyId]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->whereIn('name', ['traffic_gate.manage', 'traffic_gate.emergency_disable'])
            ->pluck('id');
        if ($ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }
    }
};
