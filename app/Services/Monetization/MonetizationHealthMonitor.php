<?php

namespace App\Services\Monetization;

use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Enums\NotificationCategory;
use App\Enums\NotificationSeverity;
use App\Models\DemandPlacement;
use App\Models\DemandSite;
use App\Models\MonetizationHealthState;
use App\Models\Site;
use App\Services\Demand\DemandConfigurationBuilder;
use App\Services\Notifications\HorusNotificationService;
use Illuminate\Support\Collection;

final class MonetizationHealthMonitor
{
    public function __construct(
        private readonly SiteServingOverviewService $overview,
        private readonly DemandConfigurationBuilder $directConfig,
        private readonly HorusNotificationService $notifications,
    ) {}

    /** @return array<int,array<string,mixed>> */
    public function observe(Site $site, bool $notify = true): array
    {
        $current = $this->states($site);

        foreach ($current as $state) {
            $stored = MonetizationHealthState::withoutGlobalScopes()->firstOrNew([
                'site_id' => $site->id,
                'state_key' => $state['key'],
            ]);
            $previous = $stored->exists ? (string) $stored->status : null;
            $fingerprint = hash('sha256', json_encode($state['details'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

            if ($stored->exists && $previous === $state['status'] && $stored->fingerprint === $fingerprint) {
                $stored->forceFill(['observed_at' => now()])->saveQuietly();
                continue;
            }

            $stored->fill([
                'organization_id' => $site->organization_id,
                'status' => $state['status'],
                'fingerprint' => $fingerprint,
                'details' => $state['details'] ?? [],
                'observed_at' => now(),
            ])->save();

            if ($notify && $previous !== null && $previous !== $state['status']) {
                $this->notifyTransition($site, $state, $previous);
            }
        }

        return $current;
    }

    /** @return array<int,array<string,mixed>> */
    public function states(Site $site): array
    {
        $overview = $this->overview->forSite($site);
        $states = [];

        $states[] = $this->state('prebid_configuration',
            $site->prebid_enabled && $overview['prebid']['health'] === 'ACTION_REQUIRED' ? 'BROKEN' : 'HEALTHY',
            ['health' => $overview['prebid']['health'], 'resolved_mode' => $overview['prebid']['resolved_mode']]);

        $directMappings = DemandSite::withoutGlobalScopes()
            ->where('site_id', $site->id)
            ->with(['account.network', 'placements.widgets'])
            ->get();
        $rejected = $directMappings->filter(fn ($mapping) => $mapping->approval_status === DemandApprovalStatus::Rejected)
            ->count()
            + $directMappings->sum(fn ($mapping) => $mapping->placements->where('approval_status', DemandApprovalStatus::Rejected)->count());
        $suspendedAccounts = $directMappings
            ->filter(fn ($mapping) => $mapping->account?->approval_status === DemandApprovalStatus::Suspended)
            ->count();

        $states[] = $this->state('direct_mapping_rejected', $rejected > 0 ? 'BROKEN' : 'HEALTHY', ['count' => $rejected]);
        $states[] = $this->state('provider_account_suspended', $suspendedAccounts > 0 ? 'BROKEN' : 'HEALTHY', ['count' => $suspendedAccounts]);

        $expectedDirectCodes = $directMappings->flatMap(function ($mapping): Collection {
            if (! $mapping->is_enabled || $mapping->approval_status !== DemandApprovalStatus::Approved
                || ! $mapping->account?->is_enabled || $mapping->account?->approval_status !== DemandApprovalStatus::Approved
                || ! $mapping->account?->network?->is_enabled) {
                return collect();
            }

            return $mapping->placements->filter(function (DemandPlacement $placement) use ($mapping): bool {
                if (! $placement->is_enabled || $placement->approval_status !== DemandApprovalStatus::Approved || ! $placement->placement) {
                    return false;
                }
                $mode = $placement->integration_mode ?? $mapping->integration_mode ?? $mapping->account->integration_mode;

                return ! in_array($mode, [DemandIntegrationMode::GamThirdPartyCreative, DemandIntegrationMode::GamLineItem], true);
            })->pluck('placement.code');
        })->filter()->unique()->values();
        $directPayload = $site->native_demand_enabled ? $this->directConfig->build($site) : ['placements' => []];
        $renderableCodes = collect(array_keys((array) ($directPayload['placements'] ?? [])));
        $unsafeCount = $expectedDirectCodes->diff($renderableCodes)->count();
        $states[] = $this->state('unsafe_tag_recipe', $unsafeCount > 0 ? 'BROKEN' : 'HEALTHY', ['count' => $unsafeCount]);

        $conflicts = collect($overview['placement_matrix'])->where('status', 'CONFLICT')->count();
        $states[] = $this->state('renderer_conflict', $conflicts > 0 ? 'BROKEN' : 'HEALTHY', ['count' => $conflicts]);

        $engineAvailable = collect(['gam', 'prebid', 'direct_js'])->contains(function (string $engine) use ($overview): bool {
            $status = data_get($overview, $engine.'.status');
            $health = data_get($overview, $engine.'.health');

            return $status === 'ON' && ! in_array($health, ['ACTION_REQUIRED', 'PAUSED', 'NOT_CONFIGURED'], true);
        });
        $states[] = $this->state('no_active_engine', $engineAvailable ? 'HEALTHY' : 'BROKEN', [
            'master' => $overview['master']['status'],
            'gam' => $overview['gam']['status'],
            'prebid' => $overview['prebid']['status'],
            'direct_js' => $overview['direct_js']['status'],
        ]);

        foreach ($overview['reporting']['sources'] as $source) {
            $states[] = $this->state('report:'.$source['key'], $source['status'] === 'FRESH' ? 'HEALTHY' : 'BROKEN', [
                'engine' => $source['engine'],
                'label' => $source['label'],
                'report_status' => $source['status'],
                'last_report_date' => $source['last_report_date'],
            ]);
        }

        return $states;
    }

    private function state(string $key, string $status, array $details): array
    {
        return compact('key', 'status', 'details');
    }

    private function notifyTransition(Site $site, array $state, string $previous): void
    {
        $broken = $state['status'] === 'BROKEN';
        $key = $state['key'];
        $critical = $key === 'no_active_engine';
        $label = match (true) {
            $key === 'no_active_engine' => 'All monetization engines',
            $key === 'renderer_conflict' => 'Renderer ownership',
            $key === 'provider_account_suspended' => 'Direct Demand provider account',
            str_starts_with($key, 'report:') => 'Financial reporting source',
            $key === 'prebid_configuration' => 'Standalone/Header Bidding configuration',
            $key === 'unsafe_tag_recipe' => 'Direct Demand tag recipe',
            default => 'Direct Demand mapping',
        };

        $this->notifications->notify($this->notifications->horusRecipients('operations.view'), [
            'category' => NotificationCategory::Operations,
            'type' => $broken ? 'MONETIZATION_HEALTH_BROKEN' : 'MONETIZATION_HEALTH_RECOVERED',
            'severity' => $broken ? ($critical ? NotificationSeverity::Critical : NotificationSeverity::Warning) : NotificationSeverity::Success,
            'title' => $label.($broken ? ' requires attention' : ' recovered'),
            'message' => $site->display_name.($broken ? ' has a material monetization health condition.' : ' returned to a healthy state.'),
            'event_key' => 'monetization:'.$site->id.':'.$key.':'.$previous.':'.$state['status'].':'.now()->getTimestamp(),
            'related_type' => 'SITE',
            'related_id' => $site->id,
            'action_route' => 'admin.sites.show',
            'action_parameters' => ['site' => $site->id],
        ]);
    }
}
