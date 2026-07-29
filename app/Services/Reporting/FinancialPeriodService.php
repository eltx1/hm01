<?php

namespace App\Services\Reporting;

use App\Enums\FinancialPeriodStatus;
use App\Enums\ReportFinality;
use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\MonthlyReport;
use App\Models\Publisher;
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
    ) {
    }

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
        if ($period->status !== FinancialPeriodStatus::Open) {
            throw ValidationException::withMessages([
                'period' => "Financial period {$period->period_key} is {$period->status->value} and cannot be changed automatically.",
            ]);
        }

        return $period;
    }

    public function close(FinancialPeriod $period, ?User $actor): FinancialPeriod
    {
        return DB::transaction(function () use ($period, $actor): FinancialPeriod {
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($period->status === FinancialPeriodStatus::Closed) {
                return $period;
            }
            $period->update([
                'status' => FinancialPeriodStatus::Closing,
                'closing_started_at' => now(),
            ]);

            $daily = DailyReport::withoutGlobalScopes()
                ->with('dimension')
                ->where('financial_period_id', $period->id)
                ->where('finality', ReportFinality::Finalized->value)
                ->get();

            foreach ($daily->groupBy(fn (DailyReport $report) =>
                $report->report_source_connection_id.'|'.$report->report_dimension_id
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

            $this->audit->record('reporting.financial_period.closed', $actor?->organization_id, $actor, $period, newValues: [
                'period_key' => $period->period_key,
                'currency' => $period->currency,
                'totals' => $totals,
                'snapshot_hash' => $period->snapshot_hash,
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
