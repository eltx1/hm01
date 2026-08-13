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
        'dashboard.partner.view' => ['View partner dashboard', 'dashboard'],
        'notifications.view_own' => ['View and manage own notifications', 'notifications'],
        'organizations.view' => ['View organizations', 'organizations'],
        'organizations.manage' => ['Manage organizations', 'organizations'],
        'publishers.view' => ['View publishers', 'publishers'],
        'publishers.manage' => ['Manage publishers', 'publishers'],
        'publisher_quality.review' => ['Record human Publisher quality decisions', 'publishers'],
        'publisher_quality.ai.run' => ['Run THOTH Publisher quality advisories', 'publishers'],
        'thoth.settings.view' => ['View THOTH AI settings and health', 'settings'],
        'thoth.settings.manage' => ['Manage THOTH AI provider and runtime settings', 'settings'],
        'thoth.credentials.manage' => ['Replace encrypted THOTH provider credentials', 'settings'],
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
        'demand.view' => ['View Direct Demand networks and reporting', 'demand'],
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
        'finance.publisher.view_own' => ['View own Publisher financial information', 'finance'],
        'finance.publisher.payment_profile.manage' => ['Manage own Publisher payment profile', 'finance'],
        'finance.publisher.invoice.upload' => ['Upload invoices for own Publisher statements', 'finance'],
        'finance.payment_profiles.verify' => ['Verify Publisher payment profiles', 'finance'],
        'finance.internal_margin.view' => ['View internal revenue margins', 'finance'],
        'finance.operations.view' => ['View Finance Operations control center', 'finance'],
        'finance.statements.review' => ['Review Publisher invoices and statements', 'finance'],
        'finance.payments.view' => ['View Publisher payouts', 'finance'],
        'finance.payments.create' => ['Create Publisher payouts', 'finance'],
        'finance.payments.approve' => ['Approve Publisher payouts', 'finance'],
        'finance.payments.settle' => ['Schedule, process, settle, hold, or fail Publisher payouts', 'finance'],
        'finance.periods.close' => ['Close ready financial periods', 'finance'],
        'finance.periods.override' => ['Override financial-period close blockers', 'finance'],
        'finance.adjustments.create' => ['Create Publisher revenue adjustments', 'finance'],
        'finance.adjustments.approve' => ['Approve or reject Publisher revenue adjustments', 'finance'],
        'finance.reconciliation.manage' => ['Manage finance reconciliation remediation', 'finance'],
        'finance.revenue_rules.manage' => ['Manage versioned revenue rules', 'finance'],
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
        'support.tickets.create' => ['Create organization support tickets', 'support'],
        'support.tickets.view_own' => ['View organization support tickets', 'support'],
        'support.tickets.reply_own' => ['Reply to organization support tickets', 'support'],
        'support.admin.view' => ['View the Horus Support Center', 'support'],
        'support.admin.reply' => ['Send Horus Support replies', 'support'],
        'support.admin.assign' => ['Assign Horus Support tickets', 'support'],
        'support.admin.manage' => ['Manage Support priority and lifecycle', 'support'],
        'support.internal_notes.view' => ['View and create internal Support notes', 'support'],
        'supply_chain.ads_txt.view' => ['View network ads.txt compliance', 'supply_chain'],
        'supply_chain.ads_txt.manage' => ['Manage canonical ads.txt records', 'supply_chain'],
        'supply_chain.ads_txt.verify' => ['Run network ads.txt verification', 'supply_chain'],
        'supply_chain.sellers.view' => ['View sellers.json and schain compliance', 'supply_chain'],
        'supply_chain.sellers.manage' => ['Manage seller declaration lifecycle', 'supply_chain'],
        'supply_chain.sellers.review' => ['Review seller identities', 'supply_chain'],
        'publisher.ads_txt.view' => ['View own ads.txt compliance', 'supply_chain'],
        'publisher.ads_txt.verify_own' => ['Verify own website ads.txt', 'supply_chain'],
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

        // Settings permissions are maintained by their dedicated Task 11 seeder.
        // Reapply them after the canonical role sync so an identity-only reseed cannot
        // silently strip global Settings access from the intended high-trust roles.
        $this->call(SettingsAccessSeeder::class);
    }

    private function permissionsFor(RoleName $role, Collection $permissions): array
    {
        if ($role === RoleName::SuperAdmin) {
            return $permissions->values()->all();
        }

        $inventory = ['inventory.view', 'inventory.manage', 'inventory.sync', 'configs.view', 'configs.manage', 'configs.publish'];
        $demandAdmin = ['demand.view', 'demand.manage', 'demand.test', 'demand.deploy', 'demand.reports'];
        $campaignAdmin = ['campaigns.view', 'campaigns.manage', 'campaigns.review', 'campaigns.deploy', 'campaigns.reports', 'creatives.manage', 'creatives.review', 'billing.advertiser.view', 'billing.advertiser.manage'];
        $reportingAdmin = ['reporting.admin.view', 'reporting.sources.manage', 'reporting.import', 'reporting.revenue.manage', 'reporting.adjustments.manage', 'reporting.adjustments.approve', 'reporting.periods.close', 'reporting.payments.manage'];
        $financeOperations = ['finance.operations.view', 'finance.statements.review', 'finance.payments.view', 'finance.payments.create', 'finance.payments.approve', 'finance.payments.settle', 'finance.periods.close', 'finance.adjustments.create', 'finance.adjustments.approve', 'finance.reconciliation.manage', 'finance.revenue_rules.manage'];
        $adsTxtAdmin = ['supply_chain.ads_txt.view', 'supply_chain.ads_txt.manage', 'supply_chain.ads_txt.verify'];
        $sellersAdmin = ['supply_chain.sellers.view', 'supply_chain.sellers.manage', 'supply_chain.sellers.review'];
        $names = match ($role) {
            RoleName::OperationsAdmin => array_merge(['dashboard.admin.view', 'organizations.view', 'organizations.manage', 'publishers.view', 'publishers.manage', 'advertisers.view', 'advertisers.manage', 'users.view', 'users.manage', 'users.invite', 'roles.view', 'audit.view', 'internal_notes.view', 'branding.manage', 'sites.view', 'sites.manage', 'sites.review', 'sites.serving.manage', 'gam.connections.view', 'gam.connections.manage', 'gam.connections.test', 'gam.connections.assign', 'contracts.view', 'contracts.manage', 'publisher_payments.manage', 'finance.publisher.view', 'finance.payment_profiles.verify', 'finance.internal_margin.view', 'operations.view', 'operations.manage', 'support.manage', 'support.admin.view', 'support.admin.reply', 'support.admin.assign', 'support.admin.manage', 'support.internal_notes.view'], $inventory, $demandAdmin, $campaignAdmin, $reportingAdmin, $financeOperations, $adsTxtAdmin, $sellersAdmin),
            RoleName::AdOpsAdmin => array_merge(['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'users.view', 'audit.view', 'sites.view', 'sites.manage', 'sites.review', 'sites.serving.manage', 'gam.connections.view', 'gam.connections.manage', 'gam.connections.test', 'gam.connections.assign'], $inventory, $demandAdmin, $campaignAdmin, ['reporting.admin.view', 'reporting.sources.manage', 'reporting.import', 'operations.view'], $adsTxtAdmin, $sellersAdmin),
            RoleName::FinanceAdmin => array_merge(['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'audit.view', 'internal_notes.view', 'sites.view', 'gam.connections.view', 'contracts.view', 'contracts.manage', 'publisher_payments.manage', 'finance.publisher.view', 'finance.payment_profiles.verify', 'finance.internal_margin.view', 'billing.advertiser.view', 'billing.advertiser.manage', 'campaigns.view', 'campaigns.reports', 'demand.view', 'demand.reports', 'reporting.admin.view', 'reporting.sources.manage', 'reporting.import', 'reporting.revenue.manage', 'reporting.adjustments.manage', 'reporting.adjustments.approve', 'reporting.periods.close', 'reporting.payments.manage', 'supply_chain.ads_txt.view', 'supply_chain.sellers.view'], $financeOperations),
            RoleName::SupportAgent => ['dashboard.admin.view', 'publishers.view', 'advertisers.view', 'users.view', 'audit.view', 'internal_notes.view', 'sites.view', 'gam.connections.view', 'inventory.view', 'configs.view', 'contracts.view', 'campaigns.view', 'campaigns.reports', 'demand.view', 'demand.reports', 'reporting.admin.view', 'support.manage', 'support.admin.view', 'support.admin.reply', 'support.admin.assign', 'support.admin.manage', 'support.internal_notes.view', 'supply_chain.ads_txt.view', 'supply_chain.sellers.view'],
            RoleName::PublisherAdmin => ['dashboard.publisher.view', 'users.view', 'users.manage', 'users.invite', 'branding.manage', 'sites.view', 'sites.manage', 'contracts.view', 'onboarding.manage', 'finance.publisher.view_own', 'finance.publisher.payment_profile.manage', 'finance.publisher.invoice.upload', 'demand.view', 'demand.reports', 'reporting.publisher.view', 'reporting.publisher.invoice', 'support.manage', 'support.tickets.create', 'support.tickets.view_own', 'support.tickets.reply_own', 'publisher.ads_txt.view', 'publisher.ads_txt.verify_own'],
            RoleName::PublisherViewer => ['dashboard.publisher.view', 'sites.view', 'contracts.view', 'finance.publisher.view_own', 'demand.view', 'demand.reports', 'reporting.publisher.view', 'support.tickets.view_own', 'publisher.ads_txt.view'],
            RoleName::AdvertiserAdmin => ['dashboard.advertiser.view', 'users.view', 'users.manage', 'users.invite', 'branding.manage', 'campaigns.view', 'campaigns.manage', 'campaigns.reports', 'creatives.manage', 'billing.advertiser.view', 'billing.advertiser.manage', 'reporting.advertiser.view', 'support.manage', 'support.tickets.create', 'support.tickets.view_own', 'support.tickets.reply_own'],
            RoleName::AdvertiserViewer => ['dashboard.advertiser.view', 'campaigns.view', 'campaigns.reports', 'billing.advertiser.view', 'reporting.advertiser.view', 'support.tickets.view_own'],
            RoleName::PartnerAdmin => ['dashboard.partner.view', 'users.view', 'users.manage', 'users.invite', 'branding.manage', 'demand.view', 'demand.reports', 'reporting.admin.view', 'support.manage', 'support.tickets.create', 'support.tickets.view_own', 'support.tickets.reply_own'],
            RoleName::PartnerViewer => ['dashboard.partner.view', 'demand.view', 'demand.reports', 'support.tickets.view_own'],
            default => [],
        };

        if ($role === RoleName::OperationsAdmin) {
            $names = array_merge($names, ['publisher_quality.review', 'publisher_quality.ai.run', 'thoth.settings.view', 'thoth.settings.manage']);
        }
        if ($role === RoleName::AdOpsAdmin) {
            $names = array_merge($names, ['publisher_quality.review', 'publisher_quality.ai.run', 'thoth.settings.view']);
        }

        $names[] = 'notifications.view_own';

        return $permissions->only(array_unique($names))->values()->all();
    }
}
