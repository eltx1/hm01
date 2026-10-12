<?php

namespace App\Services\Reporting;

use App\Models\DailyReport;
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
                $facts = DailyReport::withoutGlobalScopes()->where('financial_period_id', $period->id)
                    ->with(['connection', 'dimension'])->get();

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
                    'fact_groups' => $facts->groupBy(fn ($fact) => ($fact->connection?->connection_type ?? 'UNKNOWN')
                        .'|'.$fact->finality->value.'|'.($fact->settlement_eligible ? 'ELIGIBLE' : 'INELIGIBLE'))
                        ->map(fn ($group) => [
                            'rows' => $group->count(),
                            'publisher_rows' => $group->filter(fn ($fact) => $fact->dimension?->publisher_id !== null)->count(),
                            'first_date' => $group->min('report_date')?->toDateString(),
                            'last_date' => $group->max('report_date')?->toDateString(),
                        ])->all(),
                ];
            })->all();
    }
}
