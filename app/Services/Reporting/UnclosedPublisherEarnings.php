<?php

namespace App\Services\Reporting;

use App\Models\DailyReport;
use App\Models\Publisher;
use Illuminate\Support\Collection;

final class UnclosedPublisherEarnings
{
    /** Net publisher earnings only; these are not approved statement balances. */
    public function periods(?Publisher $publisher = null): Collection
    {
        return DailyReport::withoutGlobalScopes()
            ->join('financial_periods', 'financial_periods.id', '=', 'daily_reports.financial_period_id')
            ->where('financial_periods.status', 'OPEN')
            ->whereDate('financial_periods.ends_on', '<', today())
            ->whereHas('dimension', fn ($q) => $q->whereNotNull('publisher_id')
                ->when($publisher, fn ($q) => $q->where('publisher_id', $publisher->id)))
            ->when($publisher, fn ($q) => $q->where('daily_reports.organization_id', $publisher->organization_id))
            ->selectRaw('financial_periods.period_key, daily_reports.currency,
                SUM(CASE WHEN daily_reports.finality = ? AND daily_reports.settlement_eligible = ? THEN daily_reports.publisher_earnings_minor ELSE 0 END) AS finalized_earnings_minor,
                SUM(CASE WHEN daily_reports.finality != ? OR daily_reports.settlement_eligible = ? THEN daily_reports.publisher_earnings_minor ELSE 0 END) AS estimated_earnings_minor', ['FINALIZED', true, 'FINALIZED', false])
            ->groupBy('financial_periods.period_key', 'daily_reports.currency')
            ->orderByDesc('financial_periods.period_key')->get()
            ->map(fn ($row) => [
                'period_key' => $row->period_key,
                'currency' => $row->currency,
                'finalized_earnings_minor' => (int) $row->finalized_earnings_minor,
                'estimated_earnings_minor' => (int) $row->estimated_earnings_minor,
            ]);
    }
}
