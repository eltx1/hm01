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
        'branding.manage' => ['Manage organization branding', 'organizations'],
        'sites.view' => ['View publisher websites', 'sites'],
        'sites.manage' => ['Manage publisher websites', 'sites'],
        'sites.review' => ['Review publisher websites', 'sites'],
        'sites.serving.manage' => ['Manage website serving modes', 'sites'],
        'gam.connections.view' => ['View Google Ad Manager connections', 'gam'],
        'gam.connections.manage' => ['Manage Google Ad Manager connections', 'gam'],
        'gam.connections.test' => ['Test Google Ad Manager connections', 'gam'],
        'gam.connections.assign' => ['Assign GAM connections to websites', 'gam'],
        'inventory.view' => ['View ad units and placements', 'inventory'],
        'inventory.manage' => ['Manage ad units, placements, sizes, and targeting', 'inventory'],
        'inventory.sync' => ['Synchronize inventory with Google Ad Manager', 'inventory'],
        'configs.view' => ['Preview static advertising configurations', 'inventory'],
        'configs.manage' => ['Manage loader and GPT configuration settings', 'inventory'],
        'configs.publish' => ['Publish and roll back static advertising configurations', 'inventory'],
        'contracts.view' => ['View publisher contracts', 'contracts'],
        'contracts.manage' => ['Manage publisher contracts', 'contracts'],
        'publisher_payments.manage' => ['Manage publisher payment profiles', 'finance'],
        'onboarding.manage' => ['Complete publisher onboarding', 'publishers'],
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

        $inventory = ['inventory.view', 'inventory.manage', 'inventory.sync', 'configs.view', 'configs.manage', 'configs.publish'];
        $names = match ($role) {
            RoleName::OperationsAdmin => array_merge(['dashboard.admin.view', 'organizations.view', 'organizations.manage', 'publishers.view', 'publishers.manage', 'advertisers.view', 'advertisers.manage', 'users.view', 'users.manage', 'users.invite', 'roles.view', 'audit.view', 'internal_notes.view', 'branding.manage', 'sites.view', 'sites.manage', 'sites.review', 'sites.serving.manage', 'gam.connections.view', 'gam.connections.manage', 'gam.connections.test', 'gam.connections.assign', 'contracts.view', 'contracts.manage', 'publisher_payments.manage', 'support.manage'], $inventory),
            RoleName::AdOpsAdmin => array_merge(['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'users.view', 'audit.view', 'sites.view', 'sites.manage', 'sites.review', 'sites.serving.manage', 'gam.connections.view', 'gam.connections.manage', 'gam.connections.test', 'gam.connections.assign'], $inventory),
            RoleName::FinanceAdmin => ['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'audit.view', 'internal_notes.view', 'sites.view', 'gam.connections.view', 'contracts.view', 'contracts.manage', 'publisher_payments.manage', 'finance.publisher.view', 'finance.internal_margin.view', 'billing.advertiser.view'],
            RoleName::SupportAgent => ['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'users.view', 'audit.view', 'internal_notes.view', 'sites.view', 'gam.connections.view', 'inventory.view', 'configs.view', 'contracts.view', 'support.manage'],
            RoleName::PublisherAdmin => ['dashboard.publisher.view', 'users.view', 'users.manage', 'users.invite', 'branding.manage', 'sites.view', 'sites.manage', 'contracts.view', 'publisher_payments.manage', 'onboarding.manage', 'finance.publisher.view', 'support.manage'],
            RoleName::PublisherViewer => ['dashboard.publisher.view', 'sites.view', 'contracts.view', 'finance.publisher.view'],
            RoleName::AdvertiserAdmin => ['dashboard.advertiser.view', 'users.view', 'users.manage', 'users.invite', 'branding.manage', 'billing.advertiser.view', 'support.manage'],
            RoleName::AdvertiserViewer => ['dashboard.advertiser.view', 'billing.advertiser.view'],
            RoleName::PartnerAdmin => ['users.view', 'users.manage', 'users.invite', 'branding.manage', 'support.manage'],
            RoleName::PartnerViewer => [],
            default => [],
        };

        return $permissions->only($names)->values()->all();
    }
}
