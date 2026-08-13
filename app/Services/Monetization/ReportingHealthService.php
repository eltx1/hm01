<?php

namespace App\Services\Monetization;

use App\Enums\DemandApprovalStatus;
use App\Enums\DemandIntegrationMode;
use App\Models\BidderSiteMapping;
use App\Models\DailyReport;
use App\Models\DemandSite;
use App\Models\ReportDimension;
use App\Models\Site;
use App\Services\Serving\SiteEngineStateResolver;
use Illuminate\Support\Collection;

final class ReportingHealthService
{
    private const FRESH_DAYS = 3;

    public function __construct(private readonly SiteEngineStateResolver $engines) {}

    /** @return array{status:string,reason:string,last_update:mixed,sources:array<int,array<string,mixed>>} */
    public function forSite(Site $site): array
    {
        $state = $this->engines->resolve($site);
        $required = collect();

        if ($state->gamEnabled && $state->gamConnection) {
            $required->push([
                'key' => 'GAM:'.$state->gamConnection->id,
                'engine' => 'GAM',
                'kind' => 'gam_connection_id',
                'entity_id' => $state->gamConnection->id,
                'label' => $state->gamConnection->name,
            ]);
        }

        if ($state->prebidEnabled) {
            BidderSiteMapping::withoutGlobalScopes()
                ->where('site_id', $site->id)
                ->where('enabled', true)
                ->with(['account.bidder'])
                ->get()
                ->filter(fn ($mapping) => $mapping->account?->enabled && $mapping->account?->bidder?->enabled)
                ->each(function ($mapping) use ($required): void {
                    $bidder = $mapping->account->bidder;
                    $required->push([
                        'key' => 'PREBID:'.$bidder->id,
                        'engine' => 'PREBID',
                        'kind' => 'bidder_id',
                        'entity_id' => $bidder->id,
                        'label' => $bidder->display_name ?: $bidder->code,
                    ]);
                });
        }

        if ($state->directJsEnabled) {
            DemandSite::withoutGlobalScopes()
                ->where('site_id', $site->id)
                ->where('is_enabled', true)
                ->where('approval_status', DemandApprovalStatus::Approved->value)
                ->with(['account.network', 'placements'])
                ->get()
                ->filter(function ($mapping): bool {
                    if (! $mapping->account?->is_enabled
                        || $mapping->account?->approval_status !== DemandApprovalStatus::Approved
                        || ! $mapping->account?->network?->is_enabled) {
                        return false;
                    }

                    return $mapping->placements->contains(function ($placement) use ($mapping): bool {
                        if (! $placement->is_enabled || $placement->approval_status !== DemandApprovalStatus::Approved) {
                            return false;
                        }
                        $mode = $placement->integration_mode ?? $mapping->integration_mode ?? $mapping->account->integration_mode;

                        return ! in_array($mode, [DemandIntegrationMode::GamThirdPartyCreative, DemandIntegrationMode::GamLineItem], true);
                    });
                })
                ->each(function ($mapping) use ($required): void {
                    $network = $mapping->account->network;
                    $required->push([
                        'key' => 'DIRECT_JS:'.$network->id,
                        'engine' => 'DIRECT_JS',
                        'kind' => 'demand_network_id',
                        'entity_id' => $network->id,
                        'label' => $network->name,
                    ]);
                });
        }

        $required = $required->unique('key')->values();
        if ($required->isEmpty()) {
            return [
                'status' => 'NOT_CONFIGURED',
                'reason' => 'No active monetization source currently requires financial reporting.',
                'last_update' => null,
                'sources' => [],
            ];
        }

        $dimensionIds = ReportDimension::withoutGlobalScopes()->where('site_id', $site->id)->pluck('id');
        $reports = $dimensionIds->isEmpty()
            ? collect()
            : DailyReport::withoutGlobalScopes()
                ->whereIn('report_dimension_id', $dimensionIds)
                ->where('report_date', '>=', now()->subDays(10)->startOfDay())
                ->with(['dimension', 'connection.source'])
                ->orderByDesc('report_date')
                ->orderByDesc('updated_at')
                ->limit(2500)
                ->get();

        $sources = $required->map(fn (array $source): array => $this->sourceHealth($source, $reports))->all();
        $sourceCollection = collect($sources);
        $status = match (true) {
            $sourceCollection->contains(fn (array $source) => in_array($source['status'], ['ERROR', 'STALE'], true)) => 'DEGRADED',
            $sourceCollection->contains(fn (array $source) => $source['status'] === 'MISSING') => 'PENDING',
            default => 'ACTIVE',
        };

        return [
            'status' => $status,
            'reason' => match ($status) {
                'ACTIVE' => 'Aggregated financial reporting is fresh for every active monetization source.',
                'DEGRADED' => 'At least one active demand source has stale or unhealthy aggregated financial reporting.',
                default => 'At least one active demand source has not produced aggregated financial reporting yet.',
            },
            'last_update' => $sourceCollection->pluck('last_successful_import_at')->filter()->sortDesc()->first(),
            'sources' => $sources,
        ];
    }

    /** @param Collection<int, DailyReport> $reports */
    private function sourceHealth(array $source, Collection $reports): array
    {
        $latest = $reports->first(function (DailyReport $report) use ($source): bool {
            return (string) data_get($report, 'dimension.'.$source['kind']) === (string) $source['entity_id'];
        });

        if (! $latest) {
            return $source + [
                'status' => 'MISSING',
                'report_source' => null,
                'connection_name' => null,
                'last_report_date' => null,
                'last_successful_import_at' => null,
            ];
        }

        $lastSuccess = $latest->connection?->last_successful_import_at;
        $connectionStatus = $latest->connection?->status?->value;
        $connectionError = in_array($connectionStatus, ['ERROR', 'DISABLED'], true) || ! ($latest->connection?->is_enabled ?? false);
        $stale = $latest->report_date->lt(now()->subDays(self::FRESH_DAYS)->startOfDay())
            || $lastSuccess === null
            || $lastSuccess->lt(now()->subDays(self::FRESH_DAYS));

        return $source + [
            'status' => $connectionError ? 'ERROR' : ($stale ? 'STALE' : 'FRESH'),
            'report_source' => $latest->connection?->source?->code?->value,
            'connection_name' => $latest->connection?->name,
            'last_report_date' => $latest->report_date->toDateString(),
            'last_successful_import_at' => $lastSuccess?->toIso8601String(),
        ];
    }
}
