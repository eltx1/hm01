<?php

namespace App\Services\ControlPlane;

use App\Enums\OrganizationType;
use App\Models\User;
use Illuminate\Support\Collection;

final class ControlPlaneNavigation
{
    public function for(User $user): array
    {
        $user->loadMissing('roles.permissions');
        $permissions = $user->roles->flatMap->permissions->pluck('name')->unique();

        $groups = match ($user->organization?->type) {
            OrganizationType::HorusMedia => $this->administrator($permissions),
            OrganizationType::Publisher => $this->publisher($permissions),
            OrganizationType::Advertiser => $this->advertiser($permissions),
            OrganizationType::Partner => $this->partner($permissions),
            default => [],
        };

        return array_values(array_filter(array_map(function (array $group) use ($permissions): array {
            $group['items'] = array_values(array_filter(
                $group['items'],
                fn (array $item): bool => $permissions->contains($item['permission']),
            ));

            return $group;
        }, $groups), fn (array $group): bool => $group['items'] !== []));
    }

    private function administrator(Collection $permissions): array
    {
        return [
            $this->group('Home', [
                $this->item('Action Center', 'dashboard', 'dashboard.admin.view', ['dashboard']),
                $this->item('Notifications', 'notifications.index', 'notifications.view_own', ['notifications.*']),
            ]),
            $this->group('Publishers & Sites', [
                $this->item('Publishers', 'admin.publishers.index', 'publishers.view', ['admin.publishers.*']),
                $this->item('Websites', 'admin.sites.index', 'sites.view', ['admin.sites.*']),
                $this->item('Organizations', 'admin.organizations.index', 'organizations.view', ['admin.organizations.*']),
                $this->item('Publisher Affiliates', 'admin.publisher-affiliates.index', 'publishers.view', ['admin.publisher-affiliates.*']),
            ]),
            $this->group('Monetization', [
                $this->item('Quick Monetize', 'admin.demand.quick.create', 'demand.manage', ['admin.demand.quick.*']),
                $this->item('Direct Demand', 'admin.demand.index', 'demand.view', [
                    'admin.demand.index', 'admin.demand.master', 'admin.demand.accounts.*', 'admin.demand.networks.*',
                    'admin.demand.tags.*', 'admin.demand.credentials.*', 'admin.demand.reports.*',
                ]),
                $this->item('Direct Campaigns', 'admin.campaigns.index', 'campaigns.review', ['admin.campaigns.*']),
                $this->item('GAM Connections', 'admin.gam.connections.index', 'gam.connections.view', ['admin.gam.*']),
            ]),
            $this->group('Reporting & Finance', [
                $this->item('Reporting', 'admin.reporting.index', 'reporting.admin.view', ['admin.reporting.*']),
                $this->item('Finance', 'admin.finance.overview', 'finance.operations.view', ['admin.finance.*']),
            ]),
            $this->group('Quality & Compliance', [
                $this->item('Ads.txt & Supply Chain', 'admin.compliance.supply-chain.overview', 'supply_chain.ads_txt.view', [
                    'admin.compliance.supply-chain.*', 'admin.compliance.ads-txt.*', 'admin.compliance.sellers.*', 'admin.prebid.ads-txt.*',
                ]),
                $this->item('Traffic Quality', 'admin.operations.traffic-quality', 'traffic_gate.manage', ['admin.operations.traffic-quality*']),
                $this->item('Click Protection', 'admin.click-protection.index', 'settings.view', ['admin.click-protection.*']),
                $this->item('THOTH AI', 'admin.thoth.settings', 'thoth.settings.view', ['admin.thoth.*']),
            ]),
            $this->group('Operations & Access', [
                $this->item('Production Operations', 'admin.operations.index', 'operations.view', ['admin.operations.index', 'admin.operations.controls', 'admin.operations.static-delivery.*', 'admin.operations.loader.*']),
                $this->item('Global Settings', 'admin.settings.index', 'settings.view', ['admin.settings.*']),
                $this->item('Audit Log', 'admin.audit.index', 'audit.view', ['admin.audit.*']),
                $this->item('Access Control', 'admin.roles.index', 'roles.view', ['admin.roles.*']),
            ]),
            $this->group('Business & Support', [
                $this->item('Advertisers', 'admin.advertisers.index', 'advertisers.view', ['admin.advertisers.*']),
                $this->item('Support Tickets', 'admin.support.tickets.index', 'support.admin.view', ['admin.support.*']),
            ]),
        ];
    }

    private function publisher(Collection $permissions): array
    {
        return [
            $this->group('Home', [
                $this->item('Home', 'dashboard', 'dashboard.publisher.view', ['dashboard']),
                $this->item('Notifications', 'notifications.index', 'notifications.view_own', ['notifications.*']),
            ]),
            $this->group('Monetization', [
                $this->item('Websites', 'publisher.sites.index', 'sites.view', ['publisher.sites.*']),
                $this->item('Monetization Status', 'publisher.monetization.index', 'sites.view', ['publisher.monetization.*']),
                $this->item('Ads.txt & Compliance', 'publisher.ads-txt.index', 'publisher.ads_txt.view', ['publisher.ads-txt.*']),
            ]),
            $this->group('Earnings', [
                $this->item('Earnings & Payments', 'publisher.finance.overview', 'finance.publisher.view_own', ['publisher.finance.*', 'publisher.reporting.*']),
                $this->item('Commercial Terms', 'publisher.contracts.index', 'contracts.view', ['publisher.contracts.*']),
                $this->item('Affiliate Referrals', 'publisher.affiliate.index', 'finance.publisher.view_own', ['publisher.affiliate.*']),
            ]),
            $this->group('Help & Team', [
                $this->item('Support', 'support.tickets.index', 'support.tickets.view_own', ['support.*']),
                $this->item('Invite Teammate', 'admin.invitations.create', 'users.invite', ['admin.invitations.*']),
            ]),
        ];
    }

    private function advertiser(Collection $permissions): array
    {
        return [
            $this->group('Overview', [
                $this->item('Advertiser overview', 'dashboard', 'dashboard.advertiser.view', ['dashboard']),
                $this->item('Notifications', 'notifications.index', 'notifications.view_own', ['notifications.*']),
            ]),
            $this->group('Campaigns', [
                $this->item('Campaigns', 'advertiser.campaigns.index', 'campaigns.view', ['advertiser.campaigns.*']),
            ]),
            $this->group('Reporting', [
                $this->item('Performance reports', 'advertiser.reporting.index', 'reporting.advertiser.view', ['advertiser.reporting.*']),
            ]),
            $this->group('Support', [
                $this->item('Support Tickets', 'support.tickets.index', 'support.tickets.view_own', ['support.*']),
            ]),
        ];
    }

    private function partner(Collection $permissions): array
    {
        return [
            $this->group('Overview', [
                $this->item('Partner overview', 'dashboard', 'dashboard.partner.view', ['dashboard']),
                $this->item('Notifications', 'notifications.index', 'notifications.view_own', ['notifications.*']),
            ]),
            $this->group('Support', [
                $this->item('Support Tickets', 'support.tickets.index', 'support.tickets.view_own', ['support.*']),
            ]),
        ];
    }

    private function group(string $label, array $items): array
    {
        return compact('label', 'items');
    }

    private function item(string $label, string $route, string $permission, array $active, array $parameters = []): array
    {
        return compact('label', 'route', 'permission', 'active', 'parameters');
    }
}
