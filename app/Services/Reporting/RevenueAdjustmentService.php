<?php

namespace App\Services\Reporting;

use App\Enums\AdjustmentStatus;
use App\Enums\FinancialPeriodStatus;
use App\Models\FinancialPeriod;
use App\Models\RevenueAdjustment;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevenueAdjustmentService
{
    public function __construct(
        private readonly RevenueRuleService $rules,
        private readonly RevenueCalculator $calculator,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function create(array $attributes, User $actor): RevenueAdjustment
    {
        $period = FinancialPeriod::query()->findOrFail($attributes['financial_period_id']);
        if ($period->status === FinancialPeriodStatus::Closed) {
            throw ValidationException::withMessages(['period' => 'Adjustments cannot be added to a closed financial period. Use the next open period as a correction.']);
        }
        if ((int) $attributes['amount_minor'] <= 0) {
            throw ValidationException::withMessages(['amount_minor' => 'Adjustment deductions must be a positive minor-unit amount.']);
        }

        $adjustment = RevenueAdjustment::withoutGlobalScopes()->create([
            'organization_id' => $attributes['organization_id'] ?? $actor->organization_id,
            'financial_period_id' => $period->id,
            'report_source_connection_id' => $attributes['report_source_connection_id'] ?? null,
            'publisher_id' => $attributes['publisher_id'] ?? null,
            'site_id' => $attributes['site_id'] ?? null,
            'campaign_id' => $attributes['campaign_id'] ?? null,
            'effective_on' => $attributes['effective_on'],
            'type' => $attributes['type'],
            'amount_minor' => (int) $attributes['amount_minor'],
            'currency' => strtoupper($attributes['currency']),
            'status' => AdjustmentStatus::Pending,
            'reason' => $attributes['reason'],
            'metadata' => $attributes['metadata'] ?? null,
            'created_by' => $actor->id,
        ]);

        $this->audit->record('reporting.revenue_adjustment.created', $adjustment->organization_id, $actor, $adjustment, newValues: [
            'type' => $adjustment->type,
            'amount_minor' => $adjustment->amount_minor,
            'currency' => $adjustment->currency,
            'reason' => $adjustment->reason,
        ]);

        return $adjustment;
    }

    public function approve(RevenueAdjustment $adjustment, User $actor): RevenueAdjustment
    {
        return DB::transaction(function () use ($adjustment, $actor): RevenueAdjustment {
            $adjustment = RevenueAdjustment::withoutGlobalScopes()->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->period?->status === FinancialPeriodStatus::Closed) {
                throw ValidationException::withMessages(['period' => 'A closed period cannot be changed automatically.']);
            }
            $rule = $this->rules->resolve($adjustment->effective_on, [
                'publisher_id' => $adjustment->publisher_id,
                'site_id' => $adjustment->site_id,
                'campaign_id' => $adjustment->campaign_id,
                'report_source_id' => $adjustment->connection?->report_source_id,
            ], $adjustment->currency);
            // A deduction from zero is clamped. Preserve explicit share impacts for statement correction.
            $publisherImpact = intdiv((int) $adjustment->amount_minor * (int) $rule->publisher_share_bp, 10000);
            $mcmImpact = intdiv((int) $adjustment->amount_minor * (int) $rule->mcm_partner_share_bp, 10000);
            $horusImpact = (int) $adjustment->amount_minor - $publisherImpact - $mcmImpact;

            $metadata = array_merge((array) $adjustment->metadata, [
                'revenue_rule_version_id' => $rule->id,
                'publisher_impact_minor' => $publisherImpact,
                'horus_impact_minor' => $horusImpact,
                'mcm_partner_impact_minor' => $mcmImpact,
                'formula' => 'approved deduction allocated by the active revenue-share version',
            ]);
            $adjustment->update([
                'status' => AdjustmentStatus::Approved,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'metadata' => $metadata,
            ]);

            $this->audit->record('reporting.revenue_adjustment.approved', $adjustment->organization_id, $actor, $adjustment, [
                'status' => AdjustmentStatus::Pending->value,
            ], [
                'status' => AdjustmentStatus::Approved->value,
                'amount_minor' => $adjustment->amount_minor,
                'publisher_impact_minor' => $publisherImpact,
                'horus_impact_minor' => $horusImpact,
                'mcm_partner_impact_minor' => $mcmImpact,
            ]);

            return $adjustment->refresh();
        });
    }

    public function reject(RevenueAdjustment $adjustment, User $actor): RevenueAdjustment
    {
        $adjustment->update([
            'status' => AdjustmentStatus::Rejected,
            'rejected_by' => $actor->id,
            'rejected_at' => now(),
        ]);
        $this->audit->record('reporting.revenue_adjustment.rejected', $adjustment->organization_id, $actor, $adjustment, newValues: [
            'status' => AdjustmentStatus::Rejected->value,
        ]);

        return $adjustment->refresh();
    }
}
