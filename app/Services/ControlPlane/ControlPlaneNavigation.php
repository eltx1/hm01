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
                $this->item('Dashboard', 'dashboard', 'dashboard.admin.view', ['dashboard']),
                $this->item('Notifications', 'notifications.index', 'notifications.view_own', ['notifications.*']),
            ]),
            $this->group('Publishers & Sites', [
                $this->item('Publishers', 'admin.publishers.index', 'publishers.view', ['admin.publishers.*']),
                $this->item('Websites', 'admin.sites.index', 'sites.view', ['admin.sites.*']),
                $this->item('Organizations', 'admin.organizations.index', 'organizations.view', ['admin.organizations.*']),
                $this->item('Publisher affiliates', 'admin.publisher-affiliates.index', 'publishers.view', ['admin.publisher-affiliates.*']),
            ]),
            $this->group('Revenue & Reporting', [
                $this->item('Reports', 'admin.reporting.index', 'reporting.admin.view', ['admin.reporting.*']),
                $this->item('Finance', 'admin.finance.overview', 'finance.operations.view', ['admin.finance.*']),
                $this->item('Direct Demand', 'admin.demand.index', 'demand.view', ['admin.demand.*']),
                $this->item('Direct campaigns', 'admin.campaigns.index', 'campaigns.review', ['admin.campaigns.*']),
            ]),
            $this->group('Delivery & Compliance', [
                $this->item('GAM connections', 'admin.gam.connections.index', 'gam.connections.view', ['admin.gam.*']),
                $this->item('Ads.txt & supply chain', 'admin.compliance.supply-chain.overview', 'supply_chain.ads_txt.view', [
                    'admin.compliance.supply-chain.*', 'admin.compliance.ads-txt.*', 'admin.compliance.sellers.*', 'admin.prebid.ads-txt.*',
                ]),
                $this->item('Click protection', 'admin.click-protection.index', 'settings.view', ['admin.click-protection.*']),
                $this->item('Traffic quality', 'admin.operations.traffic-quality', 'traffic_gate.manage', ['admin.operations.traffic-quality*']),
            ]),
            $this->group('Platform', [
                $this->item('Production', 'admin.operations.index', 'operations.view', ['admin.operations.index', 'admin.operations.controls', 'admin.operations.static-delivery.*', 'admin.operations.loader.*']),
                $this->item('AI Control Center', 'admin.thoth.settings', 'thoth.settings.view', ['admin.thoth.*']),
                $this->item('Settings', 'admin.settings.index', 'settings.view', ['admin.settings.*']),
            ]),
            $this->group('Support & Access', [
                $this->item('Support tickets', 'admin.support.tickets.index', 'support.admin.view', ['admin.support.*']),
                $this->item('Advertisers', 'admin.advertisers.index', 'advertisers.view', ['admin.advertisers.*']),
                $this->item('Audit log', 'admin.audit.index', 'audit.view', ['admin.audit.*']),
                $this->item('Access control', 'admin.roles.index', 'roles.view', ['admin.roles.*']),
            ]),
        ];
    }

    private function publisher(Collection $permissions): array
    {
        return [
            $this->group('Home', [
                $this->item('Dashboard', 'dashboard', 'dashboard.publisher.view', ['dashboard']),
            ]),
            $this->group('Reports & Money', [
                // Statements, payout history, and payment details remain one
                // click away as workspace tabs inside this destination. Avoid
                // duplicating the same finance workspace in the sidebar.
                $this->item('Reports & earnings', 'publisher.finance.overview', 'finance.publisher.view_own', [
                    'publisher.finance.*', 'publisher.reporting.*',
                ]),
            ]),
            $this->group('Monetization', [
                $this->item('Websites', 'publisher.sites.index', 'sites.view', ['publisher.sites.*']),
                $this->item('Monetization health', 'publisher.monetization.index', 'sites.view', ['publisher.monetization.*']),
                $this->item('Ads.txt & compliance', 'publisher.ads-txt.index', 'publisher.ads_txt.view', ['publisher.ads-txt.*']),
            ]),
            $this->group('Account', [
                $this->item('Commercial terms', 'publisher.contracts.index', 'contracts.view', ['publisher.contracts.*']),
                $this->item('Affiliate referrals', 'publisher.affiliate.index', 'finance.publisher.view_own', ['publisher.affiliate.*']),
                $this->item('Invite team member', 'admin.invitations.create', 'users.invite', ['admin.invitations.*']),
            ]),
            $this->group('Help', [
                $this->item('Support', 'support.tickets.index', 'support.tickets.view_own', ['support.*']),
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
