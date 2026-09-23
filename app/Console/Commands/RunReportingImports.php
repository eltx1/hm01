<?php

namespace App\Console\Commands;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Models\GamConnection;
use App\Models\ReportImportJob;
use App\Models\ReportSourceConnection;
use App\Services\Reporting\ReportImportService;
use App\Services\Reporting\ReportingBridge;
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

    public function handle(ReportImportService $imports, ReportingBridge $bridge): int
    {
        $cadence = strtolower((string) $this->argument('cadence'));
        if (! in_array($cadence, ['hourly', 'daily'], true)) {
            $this->error('Cadence must be hourly or daily.');
            return self::FAILURE;
        }

        $selectedConnectionId = filled($this->option('connection'))
            ? (string) $this->option('connection')
            : null;

        // Upgrade any legacy full-network GAM source before retries or imports.
        // This guarantees an existing AED/EUR connection reaches Google as a
        // canonical USD reporting source instead of failing at the connector.
        ReportSourceConnection::withoutGlobalScopes()
            ->where('connection_type', 'GAM_CONNECTION')
            ->where('is_enabled', true)
            ->where('status', '!=', 'DISABLED')
            ->when($selectedConnectionId, fn ($query, $id) => $query->whereKey($id))
            ->get(['id', 'connection_id'])
            ->each(function (ReportSourceConnection $sourceConnection) use ($bridge, &$selectedConnectionId): void {
                $gamConnectionId = (string) $sourceConnection->connection_id;
                if ($gamConnectionId === '') {
                    return;
                }
                $gam = GamConnection::withoutGlobalScopes()->find($gamConnectionId);
                if (! $gam?->is_enabled) {
                    return;
                }

                $canonical = $bridge->connectionForGam($gam);
                if ($selectedConnectionId === $sourceConnection->id) {
                    $selectedConnectionId = $canonical->id;
                }
            });

        if ($this->option('retry-failed')) {
            ReportImportJob::withoutGlobalScopes()
                ->where('status', ReportImportStatus::Failed->value)
                ->whereHas('connection', fn ($q) => $q
                    ->where('is_enabled', true)
                    ->where('status', '!=', 'DISABLED')
                    ->where('connection_type', '!=', 'SITE_GAM_AD_UNIT')
                    ->where(function ($connectionQuery): void {
                        $connectionQuery->whereNotIn('connection_type', ['DEMAND_ACCOUNT', 'BIDDER_ACCOUNT'])
                            ->orWhereHas('financialBindings', fn ($binding) => $binding->where('is_enabled', true));
                    })
                    ->whereDoesntHave('financialBindings', fn ($binding) => $binding
                        ->where('is_enabled', true)
                        ->where('configuration->site_gam_included', true)))
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
            ->where('connection_type', '!=', 'SITE_GAM_AD_UNIT')
            ->where(function ($query): void {
                $query->whereNotIn('connection_type', ['DEMAND_ACCOUNT', 'BIDDER_ACCOUNT'])
                    ->orWhereHas('financialBindings', fn ($binding) => $binding->where('is_enabled', true));
            })
            ->whereDoesntHave('financialBindings', fn ($binding) => $binding
                ->where('is_enabled', true)
                ->where('configuration->site_gam_included', true))
            ->where('status', '!=', 'DISABLED')
            ->where(function ($query): void {
                $query->whereDoesntHave('financialBindings')
                    ->orWhereHas('financialBindings', fn ($binding) => $binding
                        ->where('is_enabled', true)
                        ->where('reporting_method', 'API'));
            })
            ->when($selectedConnectionId, fn ($query, $id) => $query->whereKey($id))
            ->with('source')
            ->get();

        $failed = 0;
        foreach ($connections as $connection) {
            if ($connection->connection_type === 'GAM_CONNECTION'
                && (bool) data_get($connection->configuration, 'canonical_currency_rebackfill_required', false)) {
                $timezone = trim((string) ($connection->timezone ?: config('reporting.default_timezone', 'UTC')));
                try {
                    // Currency repair follows the real source-local clock, not
                    // an operator's optional historical --date selection.
                    $referenceNow = CarbonImmutable::now($timezone);
                } catch (\Throwable) {
                    $timezone = 'UTC';
                    $referenceNow = CarbonImmutable::now($timezone);
                }
                $backfillFromValue = (string) data_get($connection->configuration, 'canonical_currency_rebackfill_from', '');
                $backfillFrom = $backfillFromValue !== ''
                    ? CarbonImmutable::parse($backfillFromValue, $timezone)->startOfDay()
                    : $referenceNow->startOfMonth();
                $backfillTo = $referenceNow->subDay()->endOfDay();

                if ($backfillFrom->lte($backfillTo)) {
                    $backfill = $imports->runConnection(
                        $connection,
                        $backfillFrom,
                        $backfillTo,
                        ReportGranularity::Daily,
                        ReportFinality::Finalized,
                    );
                    $this->line("{$connection->name}: USD rebackfill {$backfill->status->value} ({$backfill->row_count} rows)");
                    if ($backfill->status === ReportImportStatus::Failed) {
                        $failed++;
                        continue;
                    }
                    if (! in_array($backfill->status, [
                        ReportImportStatus::Completed,
                        ReportImportStatus::Duplicate,
                    ], true)) {
                        // Keep the rebackfill marker until a source run has
                        // actually completed. A closed-period block or any
                        // other non-terminal-success state must not silently
                        // disable the repair path.
                        continue;
                    }
                }

                $configuration = (array) ($connection->refresh()->configuration ?? []);
                $configuration['canonical_currency_rebackfill_required'] = false;
                $configuration['canonical_currency_rebackfill_completed_at'] = now()->toIso8601String();
                $connection->update(['configuration' => $configuration]);
            }

            $job = $imports->runConnection($connection->refresh(), $from, $to, $granularity, $finality);
            $this->line("{$connection->name}: {$job->status->value} ({$job->row_count} rows)");
            if ($job->status === ReportImportStatus::Failed) {
                $failed++;
            }
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
