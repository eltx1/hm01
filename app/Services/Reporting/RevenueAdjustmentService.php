<?php

namespace App\Services\Reporting;

use App\Enums\AdjustmentStatus;
use App\Enums\FinancialPeriodStatus;
use App\Models\Campaign;
use App\Models\FinancialPeriod;
use App\Models\Publisher;
use App\Models\RevenueAdjustment;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevenueAdjustmentService
{
    public function __construct(
        private readonly RevenueRuleService $rules,
        private readonly AuditRecorder $audit,
    ) {}

    public function create(array $attributes, User $actor): RevenueAdjustment
    {
        $this->authorize($actor, 'finance.adjustments.create');

        return DB::transaction(function () use ($attributes, $actor): RevenueAdjustment {
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($attributes['financial_period_id']);
            if ($period->status !== FinancialPeriodStatus::Open) {
                throw ValidationException::withMessages(['period' => 'Adjustments can be added only to an open financial period.']);
            }
            if ((int) $attributes['amount_minor'] <= 0) {
                throw ValidationException::withMessages(['amount_minor' => 'Adjustment deductions must be a positive minor-unit amount.']);
            }
            $currency = strtoupper((string) $attributes['currency']);
            if ($currency !== $period->currency) {
                throw ValidationException::withMessages(['currency' => 'Adjustment currency must match the financial period currency.']);
            }
            $effectiveOn = CarbonImmutable::parse($attributes['effective_on'])->toDateString();
            if ($effectiveOn < $period->starts_on->toDateString() || $effectiveOn > $period->ends_on->toDateString()) {
                throw ValidationException::withMessages(['effective_on' => 'Adjustment date must fall inside the selected financial period.']);
            }

            $publisher = isset($attributes['publisher_id'])
                ? Publisher::withoutGlobalScopes()->findOrFail($attributes['publisher_id'])
                : null;
            $site = isset($attributes['site_id'])
                ? Site::withoutGlobalScopes()->findOrFail($attributes['site_id'])
                : null;
            $campaign = isset($attributes['campaign_id'])
                ? Campaign::withoutGlobalScopes()->findOrFail($attributes['campaign_id'])
                : null;
            if ($publisher && $site && $site->publisher_id !== $publisher->id) {
                throw ValidationException::withMessages(['site_id' => 'The selected website does not belong to the selected Publisher.']);
            }
            if (! $publisher && $site) {
                $publisher = Publisher::withoutGlobalScopes()->findOrFail($site->publisher_id);
                $attributes['publisher_id'] = $publisher->id;
            }
            $targetOrganizations = collect([
                $publisher?->organization_id,
                $site?->organization_id,
                $campaign?->organization_id,
            ])->filter()->unique();
            if ($targetOrganizations->count() > 1) {
                throw ValidationException::withMessages(['scope' => 'Adjustment targets must belong to one organization.']);
            }
            $organizationId = $targetOrganizations->first()
                ?? $period->organization_id
                ?? $actor->organization_id;

            $adjustment = RevenueAdjustment::withoutGlobalScopes()->create([
                'organization_id' => $organizationId,
                'financial_period_id' => $period->id,
                'report_source_connection_id' => $attributes['report_source_connection_id'] ?? null,
                'publisher_id' => $attributes['publisher_id'] ?? null,
                'site_id' => $attributes['site_id'] ?? null,
                'campaign_id' => $attributes['campaign_id'] ?? null,
                'effective_on' => $effectiveOn,
                'type' => $attributes['type'],
                'amount_minor' => (int) $attributes['amount_minor'],
                'currency' => $currency,
                'status' => AdjustmentStatus::Pending,
                'reason' => trim((string) $attributes['reason']),
                'metadata' => $attributes['metadata'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->audit->record('reporting.revenue_adjustment.created', $adjustment->organization_id, $actor, $adjustment, newValues: [
                'period' => $period->period_key,
                'publisher_id' => $adjustment->publisher_id,
                'site_id' => $adjustment->site_id,
                'type' => $adjustment->type,
                'amount_minor' => $adjustment->amount_minor,
                'currency' => $adjustment->currency,
                'reason' => $adjustment->reason,
            ]);

            return $adjustment;
        });
    }

    public function approve(RevenueAdjustment $adjustment, User $actor, ?string $decisionReason = null): RevenueAdjustment
    {
        $this->authorize($actor, 'finance.adjustments.approve');

        return DB::transaction(function () use ($adjustment, $actor, $decisionReason): RevenueAdjustment {
            $adjustment = RevenueAdjustment::withoutGlobalScopes()->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status === AdjustmentStatus::Approved) {
                return $adjustment;
            }
            if ($adjustment->status !== AdjustmentStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'Only a pending adjustment can be approved.']);
            }
            if ($adjustment->created_by === $actor->id) {
                throw ValidationException::withMessages(['approval' => 'The adjustment creator cannot approve the same adjustment.']);
            }
            $period = FinancialPeriod::query()->lockForUpdate()->findOrFail($adjustment->financial_period_id);
            if ($period->status !== FinancialPeriodStatus::Open) {
                throw ValidationException::withMessages(['period' => 'A non-open period cannot be changed automatically.']);
            }
            if ($adjustment->currency !== $period->currency) {
                throw ValidationException::withMessages(['currency' => 'Adjustment and period currencies no longer match.']);
            }

            $rule = $this->rules->resolve($adjustment->effective_on, [
                'publisher_id' => $adjustment->publisher_id,
                'site_id' => $adjustment->site_id,
                'campaign_id' => $adjustment->campaign_id,
                'report_source_id' => $adjustment->connection?->report_source_id,
            ], $adjustment->currency);
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
                'decision_reason' => filled($decisionReason) ? trim((string) $decisionReason) : null,
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
                'decision_reason' => $adjustment->decision_reason,
            ]);

            return $adjustment->refresh();
        });
    }

    public function reject(RevenueAdjustment $adjustment, User $actor, string $decisionReason): RevenueAdjustment
    {
        $this->authorize($actor, 'finance.adjustments.approve');
        $decisionReason = trim($decisionReason);
        if ($decisionReason === '') {
            throw ValidationException::withMessages(['decision_reason' => 'A rejection reason is required.']);
        }

        return DB::transaction(function () use ($adjustment, $actor, $decisionReason): RevenueAdjustment {
            $adjustment = RevenueAdjustment::withoutGlobalScopes()->lockForUpdate()->findOrFail($adjustment->id);
            if ($adjustment->status === AdjustmentStatus::Rejected && $adjustment->decision_reason === $decisionReason) {
                return $adjustment;
            }
            if ($adjustment->status !== AdjustmentStatus::Pending) {
                throw ValidationException::withMessages(['status' => 'Only a pending adjustment can be rejected.']);
            }
            if ($adjustment->created_by === $actor->id) {
                throw ValidationException::withMessages(['approval' => 'The adjustment creator cannot reject the same adjustment.']);
            }
            $adjustment->update([
                'status' => AdjustmentStatus::Rejected,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'decision_reason' => $decisionReason,
            ]);
            $this->audit->record('reporting.revenue_adjustment.rejected', $adjustment->organization_id, $actor, $adjustment, newValues: [
                'status' => AdjustmentStatus::Rejected->value,
                'decision_reason' => $decisionReason,
            ]);

            return $adjustment->refresh();
        });
    }

    private function authorize(User $actor, string $permission): void
    {
        if (! $actor->isHorusAdministrator() || ! $actor->hasPermission($permission)) {
            abort(403);
        }
    }
}
