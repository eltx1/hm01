<?php

namespace App\Services\Reporting;

use Illuminate\Support\Facades\DB;
use App\Models\FinancialPeriod;

/** Aggregate-only production evidence: never emit identities or monetary values. */
final class FinancePipelineDiagnostics
{
    public function snapshot(): array
    {
        return FinancialPeriod::query()->orderByDesc('starts_on')->limit(24)->get()
            ->map(function (FinancialPeriod $period): array {
                $readiness = $period->isClosed() ? ['ready' => true, 'blockers' => [], 'counts' => []]
                    : app(FinancialPeriodService::class)->readiness($period);
                $groups = DB::table('daily_reports as d')
                    ->leftJoin('report_source_connections as c', 'c.id', '=', 'd.report_source_connection_id')
                    ->leftJoin('report_dimensions as dim', 'dim.id', '=', 'd.report_dimension_id')
                    ->where('d.financial_period_id', $period->id)
                    ->selectRaw('c.connection_type, d.finality, d.settlement_eligible, COUNT(*) AS rows_count,
                        SUM(CASE WHEN dim.publisher_id IS NOT NULL THEN 1 ELSE 0 END) AS publisher_rows,
                        MIN(d.report_date) AS first_date, MAX(d.report_date) AS last_date')
                    ->groupBy('c.connection_type', 'd.finality', 'd.settlement_eligible')->get();

                return [
                    'period' => $period->period_key,
                    'currency' => $period->currency,
                    'status' => $period->status->value,
                    'ready' => $readiness['ready'],
                    'counts' => $readiness['counts'],
                    'statement_count' => $period->statements()->count(),
                    'blockers' => collect($readiness['blockers'])->map(fn (array $blocker): array => [
                        'code' => $blocker['code'],
                        'count' => $blocker['count'],
                        'subject_reasons' => collect($blocker['subjects'] ?? [])->flatMap(fn (array $subject) =>
                            collect($subject['reasons'] ?? [])->map(fn (array $reason) =>
                                ($subject['subject_type'] ?? 'UNKNOWN').':'.$reason['code']))->countBy()->all(),
                    ])->all(),
                    'fact_groups' => $groups->mapWithKeys(fn ($group) => [
                        ($group->connection_type ?? 'UNKNOWN').'|'.$group->finality.'|'.($group->settlement_eligible ? 'ELIGIBLE' : 'INELIGIBLE') => [
                            'rows' => (int) $group->rows_count,
                            'publisher_rows' => (int) $group->publisher_rows,
                            'first_date' => $group->first_date,
                            'last_date' => $group->last_date,
                        ],
                    ])->all(),
                ];
            })->all();
    }
}
