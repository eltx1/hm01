<?php

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class IdentityAccessSeeder extends Seeder
{
    public const PERMISSIONS = [
        'dashboard.admin.view' => ['View administrator dashboard', 'dashboard'],
        'dashboard.publisher.view' => ['View publisher dashboard', 'dashboard'],
        'dashboard.advertiser.view' => ['View advertiser dashboard', 'dashboard'],
        'organizations.view' => ['View organizations', 'organizations'],
        'organizations.manage' => ['Manage organizations', 'organizations'],
        'publishers.view' => ['View publishers', 'publishers'],
        'publishers.manage' => ['Manage publishers', 'publishers'],
        'advertisers.view' => ['View advertisers', 'advertisers'],
        'advertisers.manage' => ['Manage advertisers', 'advertisers'],
        'users.view' => ['View users', 'identity'],
        'users.manage' => ['Manage users', 'identity'],
        'users.invite' => ['Invite users', 'identity'],
        'users.impersonate' => ['Impersonate users', 'identity'],
        'roles.view' => ['View roles and permissions', 'identity'],
        'roles.manage' => ['Manage roles and permissions', 'identity'],
        'audit.view' => ['View audit events', 'security'],
        'internal_notes.view' => ['View Horus Media internal notes', 'security'],
        'finance.publisher.view' => ['View publisher financial information', 'finance'],
        'finance.internal_margin.view' => ['View internal revenue margins', 'finance'],
        'billing.advertiser.view' => ['View advertiser billing', 'finance'],
        'support.manage' => ['Manage support activity', 'support'],
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->mapWithKeys(function (array $definition, string $name) {
            $permission = Permission::query()->updateOrCreate(
                ['name' => $name],
                ['display_name' => $definition[0], 'group' => $definition[1]],
            );

            return [$name => $permission->id];
        });

        foreach (RoleName::cases() as $roleName) {
            $role = Role::query()->updateOrCreate(
                ['organization_id' => null, 'name' => $roleName->value],
                ['display_name' => str($roleName->value)->replace('_', ' ')->title(), 'is_system' => true],
            );
            $role->permissions()->sync($this->permissionsFor($roleName, $permissions));
        }
    }

    private function permissionsFor(RoleName $role, Collection $permissions): array
    {
        if ($role === RoleName::SuperAdmin) {
            return $permissions->values()->all();
        }

        $names = match ($role) {
            RoleName::OperationsAdmin => ['dashboard.admin.view', 'organizations.view', 'organizations.manage', 'publishers.view', 'publishers.manage', 'advertisers.view', 'advertisers.manage', 'users.view', 'users.manage', 'users.invite', 'roles.view', 'audit.view', 'internal_notes.view', 'support.manage'],
            RoleName::AdOpsAdmin => ['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'users.view', 'audit.view'],
            RoleName::FinanceAdmin => ['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'audit.view', 'internal_notes.view', 'finance.publisher.view', 'finance.internal_margin.view', 'billing.advertiser.view'],
            RoleName::SupportAgent => ['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'users.view', 'audit.view', 'internal_notes.view', 'support.manage'],
            RoleName::PublisherAdmin => ['dashboard.publisher.view', 'users.view', 'users.manage', 'users.invite', 'finance.publisher.view', 'support.manage'],
            RoleName::PublisherViewer => ['dashboard.publisher.view', 'finance.publisher.view'],
            RoleName::AdvertiserAdmin => ['dashboard.advertiser.view', 'users.view', 'users.manage', 'users.invite', 'billing.advertiser.view', 'support.manage'],
            RoleName::AdvertiserViewer => ['dashboard.advertiser.view', 'billing.advertiser.view'],
            RoleName::PartnerAdmin => ['users.view', 'users.manage', 'users.invite', 'support.manage'],
            RoleName::PartnerViewer => [],
            default => [],
        };

        return $permissions->only($names)->values()->all();
    }
}
