<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Enums\ReportSourceCode;
use App\Models\DailyReport;
use App\Models\Site;
use App\Models\SiteGamVideoReportBinding;
use Carbon\CarbonImmutable;

/** Read-only current Video estimates, separate from finalized financial reports. */
final class SiteGamVideoTodayReport
{
    public function forSite(Site $site): ?array
    {
        $site->loadMissing('currentGamVideoReportBinding.connection.source', 'currentGamVideoReportBinding.gamConnection');
        $binding = $site->currentGamVideoReportBinding;
        $connection = $binding?->connection;
        if (! $connection || $connection->connection_type !== 'SITE_GAM_VIDEO_AD_UNIT'
            || $connection->source?->code !== ReportSourceCode::GamVideoAdUnit
            || $connection->connection_id !== $binding->id
            || $binding->site_id !== $site->id || $binding->active_site_id !== $site->id
            || $binding->organization_id !== $site->organization_id
            || $connection->organization_id !== $site->organization_id
            || $binding->cancelled_at !== null || $connection->currency !== 'USD'
            || (string) $binding->gamConnection?->network_code !== $binding->network_code
            || ! is_string($connection->timezone) || trim($connection->timezone) === '') {
            return null;
        }

        try {
            new \DateTimeZone($connection->timezone);
            $today = CarbonImmutable::now($connection->timezone)->toDateString();
        } catch (\Exception) {
            return null;
        }
        if ($binding->starts_on->toDateString() > $today
            || ($binding->ends_on && $binding->ends_on->toDateString() < $today)) {
            return null;
        }

        $scope = $this->currentScope($binding, $today);

        // Imports replace the fact for a source/day/dimension. Read those latest
        // facts, never sum prior snapshots, other bindings, or finalized amounts.
        $rows = $scope ? DailyReport::withoutGlobalScopes()
            ->where('organization_id', $site->organization_id)
            ->where('report_source_connection_id', $connection->id)
            ->where('currency', 'USD')
            ->whereDate('report_date', $today)
            ->where('finality', ReportFinality::Estimated->value)
            ->whereHas('dimension', fn ($query) => $query->where('organization_id', $site->organization_id)
                ->where('site_id', $site->id)->where('gam_connection_id', $binding->gam_connection_id)
                ->where('external_dimensions->gam_ad_unit_id', $binding->ad_unit_id)
                ->where('external_dimensions->gam_report_site', $scope['hostname'])
                ->where('external_dimensions->gam_report_scope', $scope['fingerprint']))
            ->whereHas('import', fn ($query) => $query->where('organization_id', $site->organization_id)
                ->where('report_source_connection_id', $connection->id)
                ->where('granularity', ReportGranularity::Daily->value)
                ->where('finality', ReportFinality::Estimated->value)
                ->where('status', ReportImportStatus::Completed->value))
            ->with(['import', 'dimension.site', 'connection.source'])->get() : collect();
        $snapshotImport = $rows->pluck('import.completed_at')->filter()->sortDesc()->first();

        return [
            ...app(VideoPerformanceService::class)->summary($rows, false, 'USD'),
            'date' => $today,
            'scope_current' => $scope !== null,
            'timezone' => $connection->timezone,
            'updated_at' => $snapshotImport?->copy()->setTimezone($connection->timezone)->format('Y-m-d H:i:s'),
            'refresh_enabled' => $connection->is_enabled && $connection->source->is_enabled
                && $binding->gamConnection?->is_enabled && $connection->status->value !== 'DISABLED',
        ];
    }

    private function currentScope(SiteGamVideoReportBinding $binding, string $today): ?array
    {
        $scope = data_get($binding->connection->configuration, 'site_report_scope');
        if (! is_array($scope)) return null;

        try {
            // Validation only: ensure() would write a forward cutover. A report
            // page must never change source configuration or financial facts.
            app(SiteGamReportScope::class)->assertCurrent($binding, $scope);
        } catch (\RuntimeException) {
            return null;
        }

        return $scope['effective_from'] <= $today ? $scope : null;
    }
}
