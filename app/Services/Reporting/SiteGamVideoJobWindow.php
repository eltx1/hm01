<?php

namespace App\Services\Reporting;

use App\Models\ReportImportJob;
use App\Models\SiteGamVideoReportBinding;

/** Retire only operational jobs outside ownership, never financial facts. */
final class SiteGamVideoJobWindow
{
    public function retire(SiteGamVideoReportBinding $binding): void
    {
        $connection = $binding->connection;
        $first = max($binding->starts_on->toDateString(), (string) data_get($connection->configuration, 'site_report_scope.effective_from', $binding->starts_on->toDateString()));
        $jobs = ReportImportJob::withoutGlobalScopes()->where('report_source_connection_id', $connection->id)
            ->whereIn('status', ['PENDING', 'FAILED'])
            ->where(function ($query) use ($binding, $first): void {
                $query->whereDate('period_start', '<', $first);
                if ($binding->ends_on) $query->orWhereDate('period_end', '>', $binding->ends_on->toDateString());
                if ($binding->cancelled_at) $query->orWhereRaw('1 = 1');
            });
        if (! $jobs->exists()) return;
        $jobs->update(['status' => 'DUPLICATE', 'next_retry_at' => null, 'error_message' => null, 'completed_at' => now()]);
        $configuration = $connection->fresh()->configuration ?? [];
        // Fresh owned-day jobs replace straddling windows. Fact upserts retain
        // the existing connection/date/dimension identity and never add money.
        unset($configuration['google_jobs'], $configuration['sync_due']);
        $connection->update(['configuration' => $configuration]);
    }
}
