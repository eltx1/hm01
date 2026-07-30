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
        'demand.view' => ['View native and alternative demand networks', 'demand'],
        'demand.manage' => ['Manage demand connectors, accounts, websites, placements, and widgets', 'demand'],
        'demand.test' => ['Test and synchronize approved demand accounts', 'demand'],
        'demand.deploy' => ['Deploy and control demand objects in Google Ad Manager', 'demand'],
        'demand.reports' => ['Import and view aggregated demand reports', 'demand'],
        'campaigns.view' => ['View direct campaigns', 'campaigns'],
        'campaigns.manage' => ['Create and edit direct campaigns', 'campaigns'],
        'campaigns.review' => ['Review and approve direct campaigns', 'campaigns'],
        'campaigns.deploy' => ['Deploy and control campaigns in GAM', 'campaigns'],
        'campaigns.reports' => ['View and synchronize campaign reports', 'campaigns'],
        'creatives.manage' => ['Upload and replace campaign creatives', 'campaigns'],
        'creatives.review' => ['Review campaign creatives', 'campaigns'],
        'contracts.view' => ['View publisher contracts', 'contracts'],
        'contracts.manage' => ['Manage publisher contracts', 'contracts'],
        'publisher_payments.manage' => ['Manage publisher payment profiles', 'finance'],
        'onboarding.manage' => ['Complete publisher onboarding', 'publishers'],
        'finance.publisher.view' => ['View publisher financial information', 'finance'],
        'finance.internal_margin.view' => ['View internal revenue margins', 'finance'],
        'billing.advertiser.view' => ['View advertiser billing', 'finance'],
        'billing.advertiser.manage' => ['Manage advertiser billing profiles and invoices', 'finance'],
        'reporting.admin.view' => ['View unified Horus reporting and financial dashboards', 'reporting'],
        'reporting.sources.manage' => ['Manage report source connections and status', 'reporting'],
        'reporting.import' => ['Import, retry, and reconcile aggregated reports', 'reporting'],
        'reporting.revenue.manage' => ['Manage versioned revenue-share rules', 'finance'],
        'reporting.adjustments.manage' => ['Create revenue adjustments', 'finance'],
        'reporting.adjustments.approve' => ['Approve or reject revenue adjustments', 'finance'],
        'reporting.periods.close' => ['Close financial periods and generate statements', 'finance'],
        'reporting.payments.manage' => ['Create, approve, and record publisher payments', 'finance'],
        'reporting.publisher.view' => ['View own publisher reports and statements', 'reporting'],
        'reporting.publisher.invoice' => ['Upload publisher invoices for own statements', 'finance'],
        'reporting.advertiser.view' => ['View own advertiser campaign cost reports', 'reporting'],
        'operations.view' => ['View production operations and failures', 'operations'],
        'operations.manage' => ['Manage production controls, retries, and rollbacks', 'operations'],
        'support.manage' => ['Manage support activity', 'support'],
    ];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)->mapWithKeys(function (array $definition, string $name) {
            $permission = Permission::query()->updateOrCreate(['name' => $name], ['display_name' => $definition[0], 'group' => $definition[1]]);
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
        if ($role === RoleName::SuperAdmin) return $permissions->values()->all();

        $inventory = ['inventory.view', 'inventory.manage', 'inventory.sync', 'configs.view', 'configs.manage', 'configs.publish'];
        $demandAdmin = ['demand.view', 'demand.manage', 'demand.test', 'demand.deploy', 'demand.reports'];
        $campaignAdmin = ['campaigns.view', 'campaigns.manage', 'campaigns.review', 'campaigns.deploy', 'campaigns.reports', 'creatives.manage', 'creatives.review', 'billing.advertiser.view', 'billing.advertiser.manage'];
        $reportingAdmin = ['reporting.admin.view', 'reporting.sources.manage', 'reporting.import', 'reporting.revenue.manage', 'reporting.adjustments.manage', 'reporting.adjustments.approve', 'reporting.periods.close', 'reporting.payments.manage'];
        $names = match ($role) {
            RoleName::OperationsAdmin => array_merge(['dashboard.admin.view', 'organizations.view', 'organizations.manage', 'publishers.view', 'publishers.manage', 'advertisers.view', 'advertisers.manage', 'users.view', 'users.manage', 'users.invite', 'roles.view', 'audit.view', 'internal_notes.view', 'branding.manage', 'sites.view', 'sites.manage', 'sites.review', 'sites.serving.manage', 'gam.connections.view', 'gam.connections.manage', 'gam.connections.test', 'gam.connections.assign', 'contracts.view', 'contracts.manage', 'publisher_payments.manage', 'operations.view', 'operations.manage', 'support.manage'], $inventory, $demandAdmin, $campaignAdmin, $reportingAdmin),
            RoleName::AdOpsAdmin => array_merge(['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'users.view', 'audit.view', 'sites.view', 'sites.manage', 'sites.review', 'sites.serving.manage', 'gam.connections.view', 'gam.connections.manage', 'gam.connections.test', 'gam.connections.assign'], $inventory, $demandAdmin, $campaignAdmin, ['reporting.admin.view', 'reporting.sources.manage', 'reporting.import', 'operations.view']),
            RoleName::FinanceAdmin => ['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'audit.view', 'internal_notes.view', 'sites.view', 'gam.connections.view', 'contracts.view', 'contracts.manage', 'publisher_payments.manage', 'finance.publisher.view', 'finance.internal_margin.view', 'billing.advertiser.view', 'billing.advertiser.manage', 'campaigns.view', 'campaigns.reports', 'demand.view', 'demand.reports', ...$reportingAdmin],
            RoleName::SupportAgent => ['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'users.view', 'audit.view', 'internal_notes.view', 'sites.view', 'gam.connections.view', 'inventory.view', 'configs.view', 'contracts.view', 'campaigns.view', 'campaigns.reports', 'demand.view', 'demand.reports', 'reporting.admin.view', 'support.manage'],
            RoleName::PublisherAdmin => ['dashboard.publisher.view', 'users.view', 'users.manage', 'users.invite', 'branding.manage', 'sites.view', 'sites.manage', 'contracts.view', 'publisher_payments.manage', 'onboarding.manage', 'finance.publisher.view', 'demand.view', 'demand.reports', 'reporting.publisher.view', 'reporting.publisher.invoice', 'support.manage'],
            RoleName::PublisherViewer => ['dashboard.publisher.view', 'sites.view', 'contracts.view', 'finance.publisher.view', 'demand.view', 'demand.reports', 'reporting.publisher.view'],
            RoleName::AdvertiserAdmin => ['dashboard.advertiser.view', 'users.view', 'users.manage', 'users.invite', 'branding.manage', 'campaigns.view', 'campaigns.manage', 'campaigns.reports', 'creatives.manage', 'billing.advertiser.view', 'billing.advertiser.manage', 'reporting.advertiser.view', 'support.manage'],
            RoleName::AdvertiserViewer => ['dashboard.advertiser.view', 'campaigns.view', 'campaigns.reports', 'billing.advertiser.view', 'reporting.advertiser.view'],
            RoleName::PartnerAdmin => ['users.view', 'users.manage', 'users.invite', 'branding.manage', 'demand.view', 'demand.reports', 'reporting.admin.view', 'support.manage'],
            RoleName::PartnerViewer => ['demand.view', 'demand.reports'],
            default => [],
        };

        return $permissions->only($names)->values()->all();
    }
}
