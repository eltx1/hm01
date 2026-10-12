<?php

namespace App\Console\Commands;

use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Services\Reporting\FinancialPeriodService;
use Illuminate\Console\Command;
use Throwable;

class CloseReadyFinancialPeriods extends Command
{
    protected $signature = 'reporting:close-ready-periods {--force : Apply ready closes} {--dry-run : Inspect without changing financial records}';

    protected $description = 'Catch up ended financial periods in chronological order, retaining all readiness gates.';

    public function handle(FinancialPeriodService $service): int
    {
        if (! $this->option('force') && ! $this->option('dry-run')) {
            $this->error('Use --dry-run to inspect or --force to close ready periods.');
            return self::FAILURE;
        }

        $blocked = [];
        $failed = false;
        foreach (FinancialPeriod::query()->where('status', 'OPEN')->whereDate('ends_on', '<', today())
            ->orderBy('starts_on')->orderBy('id')->get() as $period) {
            // An empty placeholder is not an accounting period to settle.
            if (! DailyReport::withoutGlobalScopes()->where('financial_period_id', $period->id)->exists()) {
                continue;
            }
            $key = ($period->organization_id ?? 'global').'|'.$period->currency;
            if (isset($blocked[$key])) {
                $this->line("{$period->period_key} {$period->currency}: awaiting earlier period.");
                continue;
            }
            try {
                $readiness = $service->readiness($period);
                if (! $readiness['ready']) {
                    $blocked[$key] = true;
                    $this->line("{$period->period_key} {$period->currency}: ".implode(', ', array_column($readiness['blockers'], 'code')));
                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line("{$period->period_key} {$period->currency}: READY (dry run).");
                } else {
                    // close rechecks readiness under its transaction lock and is idempotent.
                    $service->close($period, null);
                    $this->info("Closed {$period->period_key} {$period->currency}.");
                }
            } catch (Throwable $exception) {
                $blocked[$key] = true;
                $failed = true;
                report($exception);
                $this->error("{$period->period_key} {$period->currency}: close failed; see private application log.");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
