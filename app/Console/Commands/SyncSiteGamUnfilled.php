<?php

namespace App\Console\Commands;

use App\Models\DailyReport;
use App\Models\SiteGamReportBinding;
use App\Models\SiteGamUnfilledReport;
use App\Services\Reporting\SiteGamUnfilledSynchronizer;
use App\Services\Reporting\PerformanceMetrics;
use Illuminate\Console\Command;

/** Bounded deployment verification/recovery; never invokes financial imports. */
class SyncSiteGamUnfilled extends Command
{
    protected $signature = 'reporting:sync-unit-unfilled {--wait=0 : Maximum seconds to wait for Google, capped at 180}';
    protected $description = 'Synchronize original SOAP Unfilled impressions at ad-unit scope without financial writes.';

    public function handle(SiteGamUnfilledSynchronizer $sync): int
    {
        $wait = filter_var($this->option('wait'), FILTER_VALIDATE_INT);
        if ($wait === false || $wait < 0 || $wait > 180) return self::INVALID;
        $bindings = SiteGamReportBinding::withoutGlobalScopes()->with(['connection', 'gamConnection'])
            ->whereHas('connection', fn ($q) => $q->where('is_enabled', true)->where('status', '!=', 'DISABLED'))
            ->whereHas('gamConnection', fn ($q) => $q->where('is_enabled', true))->orderBy('id')->limit(26)->get();
        if ($bindings->count() > 25) {
            $this->line(json_encode(['status' => 'CAPACITY_EXCEEDED']));
            return self::FAILURE;
        }
        $deadline = microtime(true) + $wait;
        $results = []; $done = [];
        do {
            foreach ($bindings as $index => $binding) {
                if (isset($done[$index])) continue;
                try { $result = $sync->sync($binding); }
                catch (\Throwable) { $result = ['status' => 'SOURCE_UNAVAILABLE', 'stored_days' => 0]; }
                $results[$index] = $result['status'];
                if (! in_array($result['status'], ['PENDING', 'COMPLETED'], true)) $done[$index] = true;
                if ($wait > 0 && microtime(true) >= $deadline) break;
            }
            if (count($done) === $bindings->count() || microtime(true) >= $deadline) break;
            sleep(3);
        } while (true);
        $observed = SiteGamUnfilledReport::withoutGlobalScopes()->whereIn('report_source_connection_id', $bindings->pluck('report_source_connection_id'))->count();
        // Read back the same summary projection used by reports. Bound verification
        // to 31 observed days per binding; do not expose counters or financial data.
        $projected = 0; $checks = [];
        foreach ($bindings as $binding) {
            $observations = SiteGamUnfilledReport::withoutGlobalScopes()
                ->where('organization_id', $binding->organization_id)
                ->where('report_source_connection_id', $binding->report_source_connection_id)
                ->orderByDesc('report_date')->limit(31)->get()->keyBy(fn ($row) => $row->report_date->toDateString());
            $facts = DailyReport::withoutGlobalScopes()->where('organization_id', $binding->organization_id)
                ->where('report_source_connection_id', $binding->report_source_connection_id)
                ->whereIn('report_date', $observations->keys())->with(['dimension', 'connection.source'])->get();
            $status = 'NO_FACTS';
            foreach ($facts->groupBy(fn ($row) => $row->report_date->toDateString()) as $day => $rows) {
                $value = app(PerformanceMetrics::class)->summarize($rows, 'gross_revenue_minor')['unfilled_impressions'];
                if ($value !== $observations[$day]->unfilled_impressions) { $status = 'MISMATCH'; break; }
                $projected++; $status = 'PASS';
            }
            $checks[] = $status;
        }
        $this->line(json_encode(['schema_version' => 1, 'scope' => SiteGamUnfilledSynchronizer::SCOPE,
            'metric' => SiteGamUnfilledSynchronizer::COLUMN, 'bindings' => $bindings->count(),
            'statuses' => array_values($results), 'observed_days' => $observed,
            'projected_days' => $projected, 'projection_checks' => $checks, 'financial_writes' => false], JSON_THROW_ON_ERROR));
        return in_array('SOURCE_UNAVAILABLE', $results, true) ? self::FAILURE : self::SUCCESS;
    }
}
