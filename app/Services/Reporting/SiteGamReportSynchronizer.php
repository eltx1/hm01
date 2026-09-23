<?php

namespace App\Services\Reporting;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Enums\ReportImportStatus;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\ReportImportJob;
use App\Models\SiteGamReportBinding;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;

final class SiteGamReportSynchronizer
{
    public function __construct(
        private readonly ReportImportService $imports,
        private readonly AuditRecorder $audit,
    ) {}

    public function sync(SiteGamReportBinding $binding): array
    {
        $binding->loadMissing('connection.source', 'gamConnection');
        $connection = $this->ensureCanonicalCurrency($binding);
        if (! $connection?->is_enabled || ! $connection->source->is_enabled || $connection->status->value === 'DISABLED'
            || ! $binding->gamConnection?->is_enabled) {
            return [];
        }
        $now = CarbonImmutable::now($connection->timezone);
        $first = CarbonImmutable::parse($binding->starts_on->toDateString(), $connection->timezone);
        if ($cutover = data_get($connection->configuration, 'canonical_currency_start_on')) {
            $first = $first->max(CarbonImmutable::parse((string) $cutover, $connection->timezone));
        }
        $results = [];
        // Finish yesterday's in-flight request even when the calendar range has moved on.
        $pending = $connection->imports()->whereIn('status', ['PENDING', 'FAILED'])
            ->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('created_at')->limit(2)->get();
        foreach ($pending as $job) {
            if (! in_array($job->fresh()->status, [ReportImportStatus::Pending, ReportImportStatus::Failed], true)) {
                continue;
            }

            $retryFrom = CarbonImmutable::parse($job->period_start, $connection->timezone)->max($first);
            $retryTo = CarbonImmutable::parse($job->period_end, $connection->timezone);
            if ($retryTo->lt($first)) {
                $job->update([
                    'status' => ReportImportStatus::Duplicate,
                    'next_retry_at' => null,
                    'completed_at' => now(),
                    'error_message' => null,
                ]);
                continue;
            }
            if (! $this->open($retryFrom->toDateString(), $connection->currency)) {
                continue;
            }

            $intraday = $retryTo->toDateString() >= $now->toDateString();
            $finality = $job->granularity === ReportGranularity::Hourly
                ? ($intraday ? ReportFinality::Estimated : ReportFinality::Finalized) : $job->finality;
            $result = $this->imports->runConnection(
                $connection,
                $retryFrom,
                $retryTo,
                ReportGranularity::Daily,
                $finality,
            );
            $key = $intraday ? 'intraday_'.$retryFrom->toDateString()
                : 'daily_'.$retryFrom->toDateString().'_'.$retryTo->toDateString();
            $this->next($connection, $key, $result, $intraday ? 60 : 360);
            $results[] = $result;
        }
        $last = $binding->ends_on ? $now->subDay()->min(CarbonImmutable::parse($binding->ends_on->toDateString(), $connection->timezone)) : $now->subDay();
        for ($month = $first->startOfMonth(); $month->lte($last); $month = $month->addMonth()) {
            if (! $this->open($month->toDateString(), $connection->currency)) {
                continue;
            }
            $from = $first->max($month)->startOfDay();
            $to = $last->min($month->endOfMonth())->endOfDay();
            if ($to->lt($from)) {
                continue;
            }
            $key = 'daily_'.$from->toDateString().'_'.$to->toDateString();
            if ($this->due($connection->fresh()->configuration ?? [], $key)) {
                $job = $this->imports->runConnection($connection, $from, $to, ReportGranularity::Daily, ReportFinality::Finalized);
                $this->next($connection, $key, $job, 360);
                $results[] = $job;
            }
        }
        if ($first->lte($now) && (! $binding->ends_on || $binding->ends_on->toDateString() >= $now->toDateString())
            && $this->open($now->toDateString(), $connection->currency)) {
            $key = 'intraday_'.$now->toDateString();
            if ($this->due($connection->fresh()->configuration ?? [], $key)) {
                $job = $this->imports->runConnection($connection, $now->startOfDay(), $now->endOfDay(), ReportGranularity::Daily, ReportFinality::Estimated);
                $this->next($connection, $key, $job, 60);
                $results[] = $job;
            }
        }

        return $results;
    }

    private function ensureCanonicalCurrency(SiteGamReportBinding $binding)
    {
        $connection = $binding->connection;
        $canonical = strtoupper((string) config('reporting.canonical_currency', 'USD'));
        if (! $connection || $connection->currency === $canonical) {
            return $connection;
        }

        $networkCurrency = strtoupper((string) $connection->currency);
        $closedThrough = DailyReport::withoutGlobalScopes()
            ->where('report_source_connection_id', $connection->id)
            ->whereHas('period', fn ($query) => $query->where('status', '!=', 'OPEN'))
            ->max('report_date');

        $cutover = CarbonImmutable::parse($binding->starts_on->toDateString(), $connection->timezone);
        if ($closedThrough) {
            $cutover = $cutover->max(CarbonImmutable::parse($closedThrough, $connection->timezone)->addDay());
        }

        $configuration = (array) ($connection->configuration ?? []);
        $configuration['network_currency'] = (string) data_get($configuration, 'network_currency', $networkCurrency);
        $configuration['report_currency'] = $canonical;
        $configuration['canonical_currency_start_on'] = $cutover->toDateString();
        unset($configuration['google_jobs'], $configuration['sync_due']);

        $connection->update([
            'currency' => $canonical,
            'configuration' => $configuration,
            'last_successful_import_at' => null,
            'last_finalized_import_at' => null,
            'last_error' => null,
        ]);

        $this->audit->record(
            'reporting.site_gam.currency_normalized',
            $binding->organization_id,
            null,
            $binding,
            ['report_currency' => $networkCurrency],
            ['report_currency' => $canonical],
            ['network_currency' => $networkCurrency, 'cutover_start_on' => $cutover->toDateString()],
        );

        return $connection->fresh(['source']);
    }

    private function open(string $day, string $currency): bool
    {
        return ! FinancialPeriod::query()->where('currency', $currency)->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day)->where('status', '!=', 'OPEN')->exists();
    }

    private function due(array $configuration, string $key): bool
    {
        return empty($configuration['sync_due'][$key]) || CarbonImmutable::parse($configuration['sync_due'][$key])->lte(now());
    }

    private function next($connection, string $key, ReportImportJob $job, int $minutes): void
    {
        $configuration = $connection->fresh()->configuration ?? [];
        $delay = match ($job->status) {
            ReportImportStatus::Completed => $minutes,
            ReportImportStatus::Pending => 1,
            default => (int) config('reporting.retry_delay_minutes', 30),
        };
        $configuration['sync_due'][$key] = now()->addMinutes($delay)->toIso8601String();
        // Keep a bounded operational checkpoint, not an ever-growing request history.
        $configuration['sync_due'] = array_slice($configuration['sync_due'], -70, null, true);
        $connection->update(['configuration' => $configuration]);
    }
}
