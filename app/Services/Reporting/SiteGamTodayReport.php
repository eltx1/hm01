<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Enums\ReportImportStatus;
use App\Models\DailyReport;
use App\Models\Site;
use Carbon\CarbonImmutable;

final class SiteGamTodayReport
{
    public function forSite(Site $site): ?array
    {
        $site->loadMissing('currentGamReportBinding.connection.source', 'currentGamReportBinding.gamConnection');
        $binding = $site->currentGamReportBinding;
        $connection = $binding?->connection;
        if (! $connection || $connection->connection_type !== 'SITE_GAM_AD_UNIT'
            || $binding->organization_id !== $site->organization_id
            || $connection->organization_id !== $site->organization_id) {
            return null;
        }

        $today = CarbonImmutable::now($connection->timezone)->toDateString();
        $rows = DailyReport::withoutGlobalScopes()
            ->where('organization_id', $site->organization_id)
            ->where('report_source_connection_id', $connection->id)
            ->where('currency', $connection->currency)
            ->whereDate('report_date', $today)
            ->where('finality', ReportFinality::Estimated->value)
            ->whereHas('dimension', fn ($query) => $query->where('organization_id', $site->organization_id)->where('site_id', $site->id))
            ->whereHas('import', fn ($query) => $query->where('status', ReportImportStatus::Completed->value))
            ->with('import')->get();
        $lastImport = $rows->pluck('import.completed_at')->filter()->sortDesc()->first();

        return [
            'date' => $today,
            'timezone' => $connection->timezone,
            'currency' => $connection->currency,
            'available' => $rows->isNotEmpty(),
            'updated_at' => $lastImport?->copy()->setTimezone($connection->timezone)->format('Y-m-d H:i:s'),
            'refresh_enabled' => $connection->is_enabled && $connection->source?->is_enabled
                && $binding->gamConnection?->is_enabled && $connection->status->value !== 'DISABLED',
            'ad_requests' => (int) $rows->sum('ad_requests'),
            'impressions' => (int) $rows->sum('impressions'),
            'clicks' => (int) $rows->sum('clicks'),
            'gross_revenue_minor' => (int) $rows->sum('gross_revenue_minor'),
            'publisher_earnings_minor' => (int) $rows->sum('publisher_earnings_minor'),
        ];
    }
}
