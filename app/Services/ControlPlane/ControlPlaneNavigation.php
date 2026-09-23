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
            $this->group('Workspace', [
                $this->item('Home', 'dashboard', 'dashboard.admin.view', ['dashboard']),
                $this->item('Publishers', 'admin.publishers.index', 'publishers.view', ['admin.publishers.*']),
                $this->item('Websites', 'admin.sites.index', 'sites.view', ['admin.sites.*']),
                $this->item('Quick Monetize', 'admin.demand.quick.create', 'demand.manage', ['admin.demand.quick.*']),
            ]),
            $this->group('Revenue', [
                $this->item('Reports', 'admin.reporting.index', 'reporting.admin.view', ['admin.reporting.*']),
                $this->item('Finance', 'admin.finance.overview', 'finance.operations.view', ['admin.finance.*']),
            ]),
            $this->group('Monetization', [
                $this->item('Demand partners', 'admin.demand.index', 'demand.view', ['admin.demand.index', 'admin.demand.accounts.*', 'admin.demand.networks.*', 'admin.demand.tags.*', 'admin.demand.credentials.*', 'admin.demand.reports.*', 'admin.sites.demand.*']),
                $this->item('Direct campaigns', 'admin.campaigns.index', 'campaigns.review', ['admin.campaigns.*']),
            ]),
            $this->group('More tools', [
                $this->item('Publisher affiliates', 'admin.publisher-affiliates.index', 'publishers.view', ['admin.publisher-affiliates.*']),
                $this->item('Organizations', 'admin.organizations.index', 'organizations.view', ['admin.organizations.*']),
                $this->item('Supply Chain & Ads.txt', 'admin.compliance.supply-chain.overview', 'supply_chain.ads_txt.view', [
                    'admin.compliance.supply-chain.*', 'admin.compliance.ads-txt.*', 'admin.compliance.sellers.*', 'admin.prebid.ads-txt.*',
                ]),
                $this->item('Click Protection', 'admin.click-protection.index', 'settings.view', ['admin.click-protection.*']),
                $this->item('GAM connections', 'admin.gam.connections.index', 'gam.connections.view', ['admin.gam.*']),
                $this->item('Support Tickets', 'admin.support.tickets.index', 'support.admin.view', ['admin.support.*']),
                $this->item('Advertisers', 'admin.advertisers.index', 'advertisers.view', ['admin.advertisers.*']),
                $this->item('Production operations', 'admin.operations.index', 'operations.view', ['admin.operations.index', 'admin.operations.controls', 'admin.operations.static-delivery.*', 'admin.operations.loader.*']),
                $this->item('Traffic Quality', 'admin.operations.traffic-quality', 'traffic_gate.manage', ['admin.operations.traffic-quality*']),
                $this->item('AI Control Center', 'admin.thoth.settings', 'thoth.settings.view', ['admin.thoth.*']),
                $this->item('Global settings', 'admin.settings.index', 'settings.view', ['admin.settings.*']),
                $this->item('Audit Log', 'admin.audit.index', 'audit.view', ['admin.audit.*']),
                $this->item('Access control', 'admin.roles.index', 'roles.view', ['admin.roles.*']),
            ], collapsible: true),
        ];
    }

    private function publisher(Collection $permissions): array
    {
        return [
            $this->group('Publisher', [
                $this->item('Home', 'dashboard', 'dashboard.publisher.view', ['dashboard']),
                $this->item('Websites', 'publisher.sites.index', 'sites.view', ['publisher.sites.*']),
                $this->item('Monetization', 'publisher.monetization.index', 'sites.view', ['publisher.monetization.*']),
                $this->item('Earnings & Payments', 'publisher.finance.overview', 'finance.publisher.view_own', ['publisher.finance.*', 'publisher.reporting.*']),
                $this->item('Support', 'support.tickets.index', 'support.tickets.view_own', ['support.*']),
            ]),
            $this->group('More', [
                $this->item('Ads.txt & Compliance', 'publisher.ads-txt.index', 'publisher.ads_txt.view', ['publisher.ads-txt.*']),
                $this->item('Affiliate referrals', 'publisher.affiliate.index', 'finance.publisher.view_own', ['publisher.affiliate.*']),
                $this->item('Commercial terms', 'publisher.contracts.index', 'contracts.view', ['publisher.contracts.*']),
                $this->item('Invite team member', 'admin.invitations.create', 'users.invite', ['admin.invitations.*']),
            ], collapsible: true),
        ];
    }

    private function advertiser(Collection $permissions): array
    {
        return [
            $this->group('Advertiser', [
                $this->item('Home', 'dashboard', 'dashboard.advertiser.view', ['dashboard']),
                $this->item('Campaigns', 'advertiser.campaigns.index', 'campaigns.view', ['advertiser.campaigns.*']),
                $this->item('Reports', 'advertiser.reporting.index', 'reporting.advertiser.view', ['advertiser.reporting.*']),
                $this->item('Support', 'support.tickets.index', 'support.tickets.view_own', ['support.*']),
            ]),
        ];
    }

    private function partner(Collection $permissions): array
    {
        return [
            $this->group('Partner', [
                $this->item('Home', 'dashboard', 'dashboard.partner.view', ['dashboard']),
                $this->item('Support', 'support.tickets.index', 'support.tickets.view_own', ['support.*']),
            ]),
        ];
    }

    private function group(string $label, array $items, bool $collapsible = false): array
    {
        return compact('label', 'items', 'collapsible');
    }

    private function item(string $label, string $route, string $permission, array $active, array $parameters = []): array
    {
        return compact('label', 'route', 'permission', 'active', 'parameters');
    }
}
