<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

final class TrafficGateAccessSeeder extends Seeder
{
    public function run(): void
    {
        $manage = Permission::query()->updateOrCreate(
            ['name' => 'traffic_gate.manage'],
            ['display_name' => 'Manage Client Traffic Gate', 'group' => 'operations'],
        );
        $emergency = Permission::query()->updateOrCreate(
            ['name' => 'traffic_gate.emergency_disable'],
            ['display_name' => 'Emergency disable Client Traffic Gate', 'group' => 'operations'],
        );

        Role::query()->whereNull('organization_id')
            ->whereIn('name', [RoleName::SuperAdmin->value, RoleName::OperationsAdmin->value, RoleName::AdOpsAdmin->value])
            ->get()
            ->each(function (Role $role) use ($manage, $emergency): void {
                $role->permissions()->syncWithoutDetaching([$manage->id]);
                if (in_array($role->name, [RoleName::SuperAdmin->value, RoleName::OperationsAdmin->value], true)) {
                    $role->permissions()->syncWithoutDetaching([$emergency->id]);
                }
            });
    }
}
