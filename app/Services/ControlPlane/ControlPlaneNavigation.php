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
            OrganizationType::Publisher => $this->publisher($user, $permissions),
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
            $this->group('Overview', [
                $this->item('Action Center', 'dashboard', 'dashboard.admin.view', ['dashboard']),
                $this->item('Notifications', 'notifications.index', 'notifications.view_own', ['notifications.*']),
            ]),
            $this->group('Publishers', [
                $this->item('Publisher accounts', 'admin.publishers.index', 'publishers.view', ['admin.publishers.*']),
                $this->item('Organizations', 'admin.organizations.index', 'organizations.view', ['admin.organizations.*']),
            ]),
            $this->group('Sites & Inventory', [
                $this->item('Websites', 'admin.sites.index', 'sites.view', ['admin.sites.*']),
                $this->item('GAM connections', 'admin.gam.connections.index', 'gam.connections.view', ['admin.gam.*']),
            ]),
            $this->group('Monetization', [
                $this->item('Direct campaigns', 'admin.campaigns.index', 'campaigns.review', ['admin.campaigns.*']),
                $this->item('Native demand', 'admin.demand.index', 'demand.view', ['admin.demand.*']),
            ]),
            $this->group('Supply Chain & Compliance', [
                $this->item('Ads.txt', 'admin.compliance.ads-txt.index', 'supply_chain.ads_txt.view', ['admin.compliance.ads-txt.*']),
                $this->item('Sellers', 'admin.compliance.sellers.index', 'supply_chain.sellers.view', ['admin.compliance.sellers.*']),
            ]),
            $this->group('Reporting', [
                $this->item('Reporting sources', 'admin.reporting.index', 'reporting.admin.view', ['admin.reporting.*']),
            ]),
            $this->group('Finance', [
                $this->item('Finance Operations', 'admin.finance.overview', 'finance.operations.view', ['admin.finance.*']),
            ]),
            $this->group('Support', [
                $this->item('Support Tickets', 'admin.support.tickets.index', 'support.admin.view', ['admin.support.*']),
            ]),
            $this->group('Advertisers', [
                $this->item('Advertiser accounts', 'admin.advertisers.index', 'advertisers.view', ['admin.advertisers.*']),
            ]),
            $this->group('Operations', [
                $this->item('Production operations', 'admin.operations.index', 'operations.view', ['admin.operations.*']),
            ]),
            $this->group('Security & Audit', [
                $this->item('Access control', 'admin.roles.index', 'roles.view', ['admin.roles.*']),
            ]),
        ];
    }

    private function publisher(User $user, Collection $permissions): array
    {
        $step = $user->organization?->publisher?->onboarding_step ?? 1;

        return [
            $this->group('Overview', [
                $this->item('Publisher overview', 'dashboard', 'dashboard.publisher.view', ['dashboard']),
                $this->item('Notifications', 'notifications.index', 'notifications.view_own', ['notifications.*']),
                $this->item('Onboarding', 'publisher.onboarding.show', 'onboarding.manage', ['publisher.onboarding.*'], ['step' => $step]),
            ]),
            $this->group('Websites', [
                $this->item('Websites', 'publisher.sites.index', 'sites.view', ['publisher.sites.*']),
                $this->item('Supply Chain Compliance', 'publisher.ads-txt.index', 'publisher.ads_txt.view', ['publisher.ads-txt.*']),
            ]),
            $this->group('Reports & Earnings', [
                $this->item('Earnings & Payments', 'publisher.finance.overview', 'finance.publisher.view_own', ['publisher.finance.*', 'publisher.reporting.*']),
            ]),
            $this->group('Contracts', [
                $this->item('Contracts', 'publisher.contracts.index', 'contracts.view', ['publisher.contracts.*']),
            ]),
            $this->group('Support', [
                $this->item('Support Tickets', 'support.tickets.index', 'support.tickets.view_own', ['support.*']),
            ]),
            $this->group('Team', [
                $this->item('Invite a team member', 'admin.invitations.create', 'users.invite', ['admin.invitations.*']),
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
