<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Enums\ReportImportStatus;
use Illuminate\Database\Eloquent\Builder;

/** Explicit read-only reporting policy; financial callers retain finalized-only data. */
final class ReportDisplayQuery
{
    public static function constrain(Builder $query, bool $includeEstimates = false): Builder
    {
        if (! $includeEstimates) {
            return $query->where('daily_reports.finality', ReportFinality::Finalized->value);
        }

        // Dates remain the source's literal reporting dates. In particular, a
        // selected yesterday must not disappear at midnight while it awaits
        // finalization. Never promote a fact's finality based on its age.
        return $query->whereIn('daily_reports.finality', [ReportFinality::Estimated->value, ReportFinality::Finalized->value])
            ->whereHas('import', fn (Builder $import) => $import->withoutGlobalScopes()
                ->where('status', ReportImportStatus::Completed->value)
                ->whereColumn('report_import_jobs.report_source_connection_id', 'daily_reports.report_source_connection_id'));
    }
}
