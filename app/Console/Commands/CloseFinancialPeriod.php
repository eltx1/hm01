<?php

namespace App\Console\Commands;

use App\Models\FinancialPeriod;
use App\Services\Reporting\FinancialPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CloseFinancialPeriod extends Command
{
    protected $signature = 'reporting:close-period
        {period? : YYYY-MM, defaults to the previous month}
        {--currency=USD}
        {--force : Confirm the financial close}';

    protected $description = 'Finalize daily reports, monthly aggregates, and publisher statements for a financial period.';

    public function handle(FinancialPeriodService $periods): int
    {
        if (! $this->option('force')) {
            $this->error('Financial close requires --force.');
            return self::FAILURE;
        }
        $month = $this->argument('period') ?: now()->subMonthNoOverflow()->format('Y-m');
        $date = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01');
        $period = FinancialPeriod::query()->firstOrCreate(
            ['organization_id' => null, 'period_key' => $month, 'currency' => strtoupper((string) $this->option('currency'))],
            [
                'starts_on' => $date->startOfMonth()->toDateString(),
                'ends_on' => $date->endOfMonth()->toDateString(),
                'status' => 'OPEN',
            ],
        );
        $periods->close($period, null);
        $this->info("Closed {$month} {$period->currency}.");

        return self::SUCCESS;
    }
}
