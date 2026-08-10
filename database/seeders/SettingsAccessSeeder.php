<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

final class SettingsAccessSeeder extends Seeder
{
    public function run(): void
    {
        $view = Permission::query()->updateOrCreate(
            ['name' => 'settings.view'],
            ['display_name' => 'View global settings', 'group' => 'settings'],
        );
        $manage = Permission::query()->updateOrCreate(
            ['name' => 'settings.manage'],
            ['display_name' => 'Manage global settings', 'group' => 'settings'],
        );

        foreach ([RoleName::SuperAdmin, RoleName::OperationsAdmin] as $roleName) {
            Role::query()->whereNull('organization_id')->where('name', $roleName->value)->first()?->permissions()->syncWithoutDetaching([$view->id, $manage->id]);
        }
        foreach ([RoleName::AdOpsAdmin, RoleName::FinanceAdmin] as $roleName) {
            Role::query()->whereNull('organization_id')->where('name', $roleName->value)->first()?->permissions()->syncWithoutDetaching([$view->id]);
        }
    }
}
