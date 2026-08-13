<?php

namespace App\Services\Reporting;

use App\Enums\AdjustmentStatus;
use App\Enums\FinancialPeriodStatus;
use App\Enums\ReconciliationStatus;
use App\Enums\ReportFinality;
use App\Enums\ReportImportStatus;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\MonthlyReport;
use App\Models\Publisher;
use App\Models\ReconciliationRun;
use App\Models\ReportImportJob;
use App\Models\RevenueAdjustment;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinancialPeriodService
{
    public function __construct(
        private readonly PublisherStatementService $statements,
        private readonly AuditRecorder $audit,
        private readonly MonetizationFinancialReadinessService $monetizationReadiness,
    ) {}

    public function periodFor(CarbonInterface|string $date, string $currency, ?string $organizationId = null): FinancialPeriod
    {
        $date = CarbonImmutable::parse($date);
        $key = $date->format('Y-m');

        return FinancialPeriod::query()->firstOrCreate(
            ['organization_id' => $organizationId, 'period_key' => $key, 'currency' => strtoupper($currency)],
            [
                'starts_on' => $date->startOfMonth()->toDateString(),
                'ends_on' => $date->endOfMonth()->toDateString(),
                'status' => FinancialPeriodStatus::Open,
            ],
        );
    }

    public function assertOpen(CarbonInterface|string $date, string $currency, ?string $organizationId = null): FinancialPeriod
    {
        $period = $this->periodFor($date, $currency, $organizationId);

        return DB::transaction(function () use ($period): FinancialPeriod {
            $locked = FinancialPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($locked->status !== FinancialPeriodStatus::Open) {
                throw ValidationException::withMessages([
                    'period' => "Financial period {$locked->period_key} is {$locked->status->value} and cannot be changed automatically.",
                ]);
            }

            return $locked;
        });
    }

    /** @return array{ready: bool, blockers: array<int, array{code: string, message: string, count: int}>, counts: array<string, int>} */
    public function readiness(FinancialPeriod $period): array
    {
        $period = FinancialPeriod::query()->findOrFail($period->id);
        $finalizedRows = DailyReport::withoutGlobalScopes()
            ->where('financial_period_id', $period->id)
            ->where('finality', ReportFinality::Finalized->value)
            ->where('settlement_eligible', true)
            ->count();
        $ineligibleFinalizedRows = DailyReport::withoutGlobalScopes()
            ->where('financial_period_id', $period->id)
            ->where('finality', ReportFinality::Finalized->value)
            ->where('settlement_eligible', false)
            ->count();
        $nonFinalRows = DailyReport::withoutGlobalScopes()
            ->where('financial_period_id', $period->id)
            ->where('finality', '!=', ReportFinality::Finalized->value)
            ->count();
        $importQuery = ReportImportJob::withoutGlobalScopes()
            ->whereDate('period_start', '<=', $period->ends_on)
            ->whereDate('period_end', '>=', $period->starts_on)
            ->where(function ($query) use ($period): void {
                $query->where('financial_period_id', $period->id)
                    ->orWhereHas('connection', fn ($connection) => $connection->where('currency', $period->currency));
            });
        $failedImports = (clone $importQuery)->where('status', ReportImportStatus::Failed->value)->count();
        $activeImports = (clone $importQuery)->whereIn('status', [
            ReportImportStatus::Pending->value,
            ReportImportStatus::Processing->value,
        ])->count();
        $reconciliationIssues = ReconciliationRun::withoutGlobalScopes()
            ->whereDate('period_start', '<=', $period->ends_on)
            ->whereDate('period_end', '>=', $period->starts_on)
            ->where(function ($query) use ($period): void {
                $query->whereHas('import', fn ($import) => $import->where('financial_period_id', $period->id))
                    ->orWhereHas('connection', fn ($connection) => $connection->where('currency', $period->currency));
            })
            ->whereIn('status', [
                ReconciliationStatus::Pending->value,
                ReconciliationStatus::Running->value,
                ReconciliationStatus::Warning->value,
                ReconciliationStatus::Failed->value,
            ])->count();
        $pendingAdjustments = RevenueAdjustment::withoutGlobalScopes()
            ->where('financial_period_id', $period->id)
            ->where('status', AdjustmentStatus::Pending->value)
            ->count();
        $currencyMismatchRows = DailyReport::withoutGlobalScopes()
            ->where('financial_period_id', $period->id)
            ->where('currency', '!=', $period->currency)
            ->count();
        $currencyMismatchAdjustments = RevenueAdjustment::withoutGlobalScopes()
            ->where('financial_period_id', $period->id)
            ->where('currency', '!=', $period->currency)
            ->count();

        $monetizationBlockers = $this->monetizationReadiness->blockersForPeriod($period);

        $counts = compact(
            'finalizedRows',
            'ineligibleFinalizedRows',
            'nonFinalRows',
            'failedImports',
            'activeImports',
            'reconciliationIssues',
            'pendingAdjustments',
            'currencyMismatchRows',
            'currencyMismatchAdjustments',
        );
        $blockers = [];
        if (! $period->ends_on->lt(today())) {
            $blockers[] = ['code' => 'PERIOD_NOT_ENDED', 'message' => 'The financial period has not ended.', 'count' => 1];
        }
        if ($finalizedRows === 0) {
            $blockers[] = ['code' => 'MISSING_FINALIZED_DATA', 'message' => 'No finalized daily source data exists for this currency and period.', 'count' => 1];
        }
        if ($nonFinalRows > 0) {
            $blockers[] = ['code' => 'NON_FINAL_REPORTING', 'message' => 'Estimated or otherwise non-final daily rows remain.', 'count' => $nonFinalRows];
        }
        if ($ineligibleFinalizedRows > 0) {
            $blockers[] = ['code' => 'SETTLEMENT_INELIGIBLE_FINALIZED_ROWS', 'message' => 'Finalized-labelled rows exist whose source is not eligible for financial settlement.', 'count' => $ineligibleFinalizedRows];
        }
        if ($monetizationBlockers->isNotEmpty()) {
            $blockers[] = [
                'code' => 'MONETIZATION_SOURCE_COVERAGE',
                'message' => 'Active production monetization accounts have missing, stale, failed, estimate-only, or unreconciled financial coverage.',
                'count' => $monetizationBlockers->count(),
                'subjects' => $monetizationBlockers->all(),
            ];
            $counts['monetizationCoverageBlockers'] = $monetizationBlockers->count();
        } else {
            $counts['monetizationCoverageBlockers'] = 0;
        }
        if ($failedImports > 0) {
            $blockers[] = ['code' => 'FAILED_IMPORTS', 'message' => 'Failed report imports remain unresolved.', 'count' => $failedImports];
        }
        if ($activeImports > 0) {
            $blockers[] = ['code' => 'IMPORTS_IN_PROGRESS', 'message' => 'Report imports are still pending or processing.', 'count' => $activeImports];
        }
        if ($reconciliationIssues > 0) {
            $blockers[] = ['code' => 'RECONCILIATION_ISSUES', 'message' => 'Unmatched, failed, or unfinished reconciliations remain.', 'count' => $reconciliationIssues];
        }
        if ($pendingAdjustments > 0) {
            $blockers[] = ['code' => 'PENDING_ADJUSTMENTS', 'message' => 'Revenue adjustments still await a decision.', 'count' => $pendingAdjustments];
        }
        if ($currencyMismatchRows + $currencyMismatchAdjustments > 0) {
            $blockers[] = [
                'code' => 'CURRENCY_MISMATCH',
                'message' => 'Reporting rows or adjustments do not match the financial period currency.',
                'count' => $currencyMismatchRows + $currencyMismatchAdjustments,
            ];
        }

        return ['ready' => $blockers === [], 'blockers' => $blockers, 'counts' => $counts];
    }

    public function close(FinancialPeriod $period, ?User $actor, ?string $overrideReason = null): FinancialPeriod
    {
        if ($actor && (! $actor->isHorusAdministrator() || ! $actor->hasPermission('finance.periods.close'))) {
            abort(403);
        }

        return DB::transaction(function () use ($period, $actor, $overrideReason): FinancialPeriod {
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($period->status === FinancialPeriodStatus::Closed) {
                return $period;
            }
            $readiness = $this->readiness($period);
            $overrideReason = filled($overrideReason) ? trim((string) $overrideReason) : null;
            if (! $readiness['ready']) {
                if ($overrideReason === null) {
                    throw ValidationException::withMessages([
                        'readiness' => collect($readiness['blockers'])
                            ->map(fn (array $blocker): string => $blocker['code'].': '.$blocker['message'])
                            ->all(),
                    ]);
                }
                if (! $actor || ! $actor->hasPermission('finance.periods.override')) {
                    abort(403);
                }
                if (mb_strlen($overrideReason) < 12) {
                    throw ValidationException::withMessages(['override_reason' => 'A specific override reason of at least 12 characters is required.']);
                }
                $this->audit->record('finance.financial_period.close_overridden', $period->organization_id ?? $actor->organization_id, $actor, $period, newValues: [
                    'period_key' => $period->period_key,
                    'currency' => $period->currency,
                    'reason' => $overrideReason,
                    'blockers' => $readiness['blockers'],
                ]);
            }
            $period->update([
                'status' => FinancialPeriodStatus::Closing,
                'closing_started_at' => now(),
                'readiness_snapshot' => $readiness,
                'close_override_reason' => $overrideReason,
                'close_override_at' => $overrideReason ? now() : null,
                'close_override_by' => $overrideReason ? $actor?->id : null,
            ]);

            $daily = DailyReport::withoutGlobalScopes()
                ->with('dimension')
                ->where('financial_period_id', $period->id)
                ->where('finality', ReportFinality::Finalized->value)
                ->where('settlement_eligible', true)
                ->get();

            foreach ($daily->groupBy(fn (DailyReport $report) => $report->report_source_connection_id.'|'.$report->report_dimension_id
            ) as $group) {
                $first = $group->first();
                $metrics = $this->sumMetrics($group->all());
                $snapshot = [
                    'period' => $period->period_key,
                    'connection' => $first->report_source_connection_id,
                    'dimension' => $first->report_dimension_id,
                    'metrics' => $metrics,
                    'row_ids' => $group->pluck('id')->sort()->values()->all(),
                ];
                MonthlyReport::withoutGlobalScopes()->updateOrCreate(
                    [
                        'financial_period_id' => $period->id,
                        'report_source_connection_id' => $first->report_source_connection_id,
                        'report_dimension_id' => $first->report_dimension_id,
                    ],
                    array_merge($metrics, [
                        'organization_id' => $first->organization_id,
                        'period_key' => $period->period_key,
                        'currency' => $period->currency,
                        'settlement_eligible' => true,
                        'revenue_rule_version_id' => $first->revenue_rule_version_id,
                        'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
                    ]),
                );
            }

            $publisherIds = $daily->pluck('dimension.publisher_id')->filter()->unique();
            foreach (Publisher::withoutGlobalScopes()->whereIn('id', $publisherIds)->get() as $publisher) {
                $this->statements->generate($period, $publisher, $actor);
            }

            $totals = $this->sumMetrics($daily->all());
            $approvedAdjustments = RevenueAdjustment::withoutGlobalScopes()
                ->where('financial_period_id', $period->id)
                ->where('status', 'APPROVED')
                ->get();
            $adjustments = (int) $approvedAdjustments->sum('amount_minor');
            $publisherImpact = (int) $approvedAdjustments->sum(fn ($adjustment) => (int) data_get($adjustment->metadata, 'publisher_impact_minor', 0));
            $horusImpact = (int) $approvedAdjustments->sum(fn ($adjustment) => (int) data_get($adjustment->metadata, 'horus_impact_minor', 0));
            $mcmImpact = (int) $approvedAdjustments->sum(fn ($adjustment) => (int) data_get($adjustment->metadata, 'mcm_partner_impact_minor', 0));
            $totals['other_adjustments_minor'] += $adjustments;
            $totals['net_revenue_minor'] = max(0, $totals['net_revenue_minor'] - $adjustments);
            $totals['publisher_earnings_minor'] = max(0, $totals['publisher_earnings_minor'] - $publisherImpact);
            $totals['horus_earnings_minor'] = max(0, $totals['horus_earnings_minor'] - $horusImpact);
            $totals['mcm_partner_earnings_minor'] = max(0, $totals['mcm_partner_earnings_minor'] - $mcmImpact);
            $totals['approved_adjustments_minor'] = $adjustments;
            $snapshot = [
                'period' => $period->period_key,
                'currency' => $period->currency,
                'totals' => $totals,
                'monthly_rows' => MonthlyReport::withoutGlobalScopes()
                    ->where('financial_period_id', $period->id)->pluck('snapshot_hash')->sort()->values()->all(),
            ];

            $period->update([
                'status' => FinancialPeriodStatus::Closed,
                'closed_at' => now(),
                'closed_by' => $actor?->id,
                'totals' => $totals,
                'snapshot_hash' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            ]);

            $this->audit->record('finance.financial_period.closed', $period->organization_id ?? $actor?->organization_id, $actor, $period, newValues: [
                'period_key' => $period->period_key,
                'currency' => $period->currency,
                'totals' => $totals,
                'snapshot_hash' => $period->snapshot_hash,
                'readiness' => $readiness,
                'overridden' => $overrideReason !== null,
            ]);

            return $period->refresh();
        });
    }

    private function sumMetrics(array $reports): array
    {
        $fields = [
            'ad_requests', 'matched_requests', 'unfilled_requests', 'impressions', 'clicks',
            'gross_revenue_minor', 'demand_partner_deductions_minor',
            'invalid_traffic_adjustments_minor', 'other_adjustments_minor',
            'net_revenue_minor', 'publisher_earnings_minor', 'horus_earnings_minor',
            'mcm_partner_earnings_minor', 'video_starts', 'completed_views',
        ];
        $totals = array_fill_keys($fields, 0);
        $viewableWeighted = 0;
        $viewabilityBase = 0;
        foreach ($reports as $report) {
            foreach ($fields as $field) {
                $totals[$field] += (int) $report->{$field};
            }
            if ($report->viewability_bp !== null && $report->impressions > 0) {
                $viewableWeighted += ((int) $report->viewability_bp) * ((int) $report->impressions);
                $viewabilityBase += (int) $report->impressions;
            }
        }
        $rates = app(RevenueCalculator::class)->rates($totals);
        $totals = array_merge($totals, $rates);
        $totals['viewability_bp'] = $viewabilityBase > 0 ? (int) round($viewableWeighted / $viewabilityBase) : null;

        return $totals;
    }
}
