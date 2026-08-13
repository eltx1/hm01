<?php

namespace App\Services\ControlPlane\Actions;

use App\Models\MonetizationHealthState;
use App\Models\User;
use App\Services\ControlPlane\Contracts\ActionCenterProvider;

final class MonetizationActions implements ActionCenterProvider
{
    public function actions(User $user): array
    {
        if (! $user->hasPermission('operations.view')) {
            return [];
        }

        $states = MonetizationHealthState::withoutGlobalScopes()
            ->where('status', 'BROKEN')
            ->get(['state_key']);

        $count = fn (string $key): int => $states->where('state_key', $key)->count();
        $prefix = fn (string $prefix): int => $states->filter(fn ($state) => str_starts_with($state->state_key, $prefix))->count();

        return [
            $this->item('monetization-no-engine', 'Sites with no active monetization engine', $count('no_active_engine'), 'All serving engines are unavailable for one or more sites.', 2, 'danger'),
            $this->item('monetization-renderer-conflict', 'Renderer ownership conflicts', $count('renderer_conflict'), 'A placement is configured for incompatible renderers and is failing closed.', 3, 'danger'),
            $this->item('monetization-prebid-config', 'Header Bidding configuration issues', $count('prebid_configuration'), 'Standalone or bridge Prebid configuration requires attention.', 6),
            $this->item('monetization-direct-rejected', 'Rejected Direct Demand mappings', $count('direct_mapping_rejected'), 'A Direct Demand site or placement mapping was rejected.', 7),
            $this->item('monetization-direct-unsafe', 'Unsafe Direct Demand tag recipes', $count('unsafe_tag_recipe'), 'An approved Direct Demand placement has no safely materialized recipe.', 4, 'danger'),
            $this->item('monetization-provider-suspended', 'Suspended Direct Demand accounts', $count('provider_account_suspended'), 'A provider account used by a website is suspended.', 4, 'danger'),
            $this->item('monetization-report-stale', 'Stale or missing monetization reports', $prefix('report:'), 'An active demand source lacks fresh aggregated financial reporting.', 8),
        ];
    }

    private function item(string $key, string $label, int $count, string $description, int $priority, string $severity = 'warning'): array
    {
        return compact('key', 'label', 'count', 'description', 'priority', 'severity') + [
            'route' => 'admin.operations.index',
            'parameters' => [],
        ];
    }
}
