<?php

namespace App\Console\Commands;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Models\ReportImportJob;
use App\Models\ReportSourceConnection;
use App\Services\Reporting\ReportImportService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class RunReportingImports extends Command
{
    protected $signature = 'reporting:import
        {cadence=hourly : hourly or daily}
        {--date= : Date to import}
        {--connection= : One report source connection ULID}
        {--retry-failed : Retry eligible failed jobs first}';

    protected $description = 'Import aggregated reporting data from active GAM, native, and configured report sources.';

    public function handle(ReportImportService $imports): int
    {
        $cadence = strtolower((string) $this->argument('cadence'));
        if (! in_array($cadence, ['hourly', 'daily'], true)) {
            $this->error('Cadence must be hourly or daily.');
            return self::FAILURE;
        }

        if ($this->option('retry-failed')) {
            ReportImportJob::withoutGlobalScopes()
                ->where('status', ReportImportStatus::Failed->value)
                ->where(fn ($query) => $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
                ->with('connection.source')
                ->each(fn (ReportImportJob $job) => $imports->retry($job));
        }

        $date = CarbonImmutable::parse($this->option('date') ?: now());
        if ($cadence === 'hourly') {
            $lookback = max(1, (int) config('reporting.hourly_lookback_hours', 3));
            $from = $date->subHours($lookback)->startOfHour();
            $to = $date->endOfHour();
            $granularity = ReportGranularity::Hourly;
            $finality = ReportFinality::Estimated;
        } else {
            $lookback = max(1, (int) config('reporting.daily_lookback_days', 2));
            $from = $date->subDays($lookback)->startOfDay();
            $to = $date->subDay()->endOfDay();
            $granularity = ReportGranularity::Daily;
            $finality = ReportFinality::Finalized;
        }

        $connections = ReportSourceConnection::withoutGlobalScopes()
            ->where('is_enabled', true)
            ->where('status', '!=', 'DISABLED')
            ->where(function ($query): void {
                $query->whereDoesntHave('financialBindings')
                    ->orWhereHas('financialBindings', fn ($binding) => $binding
                        ->where('is_enabled', true)
                        ->where('reporting_method', 'API'));
            })
            ->when($this->option('connection'), fn ($query, $id) => $query->whereKey($id))
            ->with('source')
            ->get();

        $failed = 0;
        foreach ($connections as $connection) {
            $job = $imports->runConnection($connection, $from, $to, $granularity, $finality);
            $this->line("{$connection->name}: {$job->status->value} ({$job->row_count} rows)");
            if ($job->status === ReportImportStatus::Failed) {
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
