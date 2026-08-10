<?php

namespace App\Services\Reporting;

use App\Enums\FinancialPeriodStatus;
use App\Enums\RevenueRuleScope;
use App\Models\Campaign;
use App\Models\DemandNetwork;
use App\Models\FinancialPeriod;
use App\Models\Publisher;
use App\Models\ReportSource;
use App\Models\RevenueRule;
use App\Models\RevenueRuleVersion;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RevenueRuleService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function createRule(array $attributes, ?User $actor): RevenueRule
    {
        if ($actor) {
            $this->authorize($actor);
        }

        return DB::transaction(function () use ($attributes, $actor): RevenueRule {
            $scope = $attributes['scope_type'] instanceof RevenueRuleScope
                ? $attributes['scope_type']
                : RevenueRuleScope::from((string) $attributes['scope_type']);
            $this->validateShares($attributes);
            $this->assertEffectiveDateOpen($attributes['effective_from']);
            $organizationId = $this->scopeOrganization($scope, $attributes['scope_id'] ?? null)
                ?? ($attributes['organization_id'] ?? $actor?->organization_id);

            $rule = RevenueRule::withoutGlobalScopes()->create([
                'organization_id' => $organizationId,
                'name' => $attributes['name'],
                'scope_type' => $scope,
                'scope_id' => $scope === RevenueRuleScope::Global ? null : ($attributes['scope_id'] ?? null),
                'is_active' => $attributes['is_active'] ?? true,
                'effective_from' => $attributes['effective_from'],
                'effective_to' => $attributes['effective_to'] ?? null,
                'priority' => (int) ($attributes['priority'] ?? 0),
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $version = $this->createVersion($rule, $attributes, $actor);
            $rule->update(['current_version_id' => $version->id]);

            if ($actor) {
                $this->audit->record('reporting.revenue_rule.created', $rule->organization_id, $actor, $rule, newValues: [
                    'scope_type' => $scope->value,
                    'scope_id' => $rule->scope_id,
                    'version' => 1,
                    'publisher_share_bp' => $version->publisher_share_bp,
                    'horus_share_bp' => $version->horus_share_bp,
                    'mcm_partner_share_bp' => $version->mcm_partner_share_bp,
                ]);
            }

            return $rule->fresh(['currentVersion', 'versions']);
        });
    }

    public function changeRule(RevenueRule $rule, array $attributes, User $actor): RevenueRuleVersion
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($rule, $attributes, $actor): RevenueRuleVersion {
            $rule = RevenueRule::withoutGlobalScopes()->with(['versions', 'currentVersion'])->lockForUpdate()->findOrFail($rule->id);
            $merged = [
                'publisher_share_bp' => $attributes['publisher_share_bp'],
                'horus_share_bp' => $attributes['horus_share_bp'],
                'mcm_partner_share_bp' => $attributes['mcm_partner_share_bp'] ?? 0,
            ];
            $this->validateShares($merged);
            $this->assertEffectiveDateOpen($attributes['effective_from']);
            if (blank($attributes['reason'] ?? null)) {
                throw ValidationException::withMessages(['reason' => 'A reason is required for every revenue-rule version.']);
            }
            if ($rule->currentVersion && (string) $attributes['effective_from'] < $rule->currentVersion->effective_from->toDateString()) {
                throw ValidationException::withMessages(['effective_from' => 'A new version cannot begin before the current version.']);
            }

            $before = $rule->currentVersion?->only([
                'version', 'publisher_share_bp', 'horus_share_bp', 'mcm_partner_share_bp',
                'effective_from', 'effective_to',
            ]) ?? [];

            $version = $this->createVersion($rule, $attributes, $actor);
            $rule->update([
                'current_version_id' => $version->id,
                'effective_from' => min($rule->effective_from->toDateString(), $version->effective_from->toDateString()),
                'effective_to' => $attributes['effective_to'] ?? $rule->effective_to,
                'updated_by' => $actor->id,
            ]);

            $this->audit->record('reporting.revenue_rule.versioned', $rule->organization_id, $actor, $rule, $before, [
                'version' => $version->version,
                'publisher_share_bp' => $version->publisher_share_bp,
                'horus_share_bp' => $version->horus_share_bp,
                'mcm_partner_share_bp' => $version->mcm_partner_share_bp,
                'effective_from' => $version->effective_from->toDateString(),
                'effective_to' => $version->effective_to?->toDateString(),
            ]);

            return $version;
        });
    }

    public function resolve(CarbonInterface|string $date, array $context, ?string $currency = null): RevenueRuleVersion
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
        $rules = RevenueRule::withoutGlobalScopes()
            ->with('versions')
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->get()
            ->filter(fn (RevenueRule $rule) => $this->matches($rule, $context))
            ->sortByDesc(fn (RevenueRule $rule) => ($rule->scope_type->specificity() * 100000) + $rule->priority);

        foreach ($rules as $rule) {
            $version = $rule->versions
                ->filter(fn (RevenueRuleVersion $version) => $version->effective_from->toDateString() <= $date
                    && (! $version->effective_to || $version->effective_to->toDateString() >= $date)
                    && (! $version->currency || ! $currency || $version->currency === $currency)
                )
                ->sortByDesc('version')
                ->first();
            if ($version) {
                return $version;
            }
        }

        return new RevenueRuleVersion([
            'version' => 0,
            'publisher_share_bp' => (int) config('reporting.default_publisher_share_bp', 7000),
            'horus_share_bp' => (int) config('reporting.default_horus_share_bp', 3000),
            'mcm_partner_share_bp' => (int) config('reporting.default_mcm_share_bp', 0),
            'effective_from' => '1970-01-01',
        ]);
    }

    private function createVersion(RevenueRule $rule, array $attributes, ?User $actor): RevenueRuleVersion
    {
        $next = ((int) $rule->versions()->max('version')) + 1;

        return RevenueRuleVersion::query()->create([
            'revenue_rule_id' => $rule->id,
            'version' => $next,
            'publisher_share_bp' => (int) $attributes['publisher_share_bp'],
            'horus_share_bp' => (int) $attributes['horus_share_bp'],
            'mcm_partner_share_bp' => (int) ($attributes['mcm_partner_share_bp'] ?? 0),
            'effective_from' => $attributes['effective_from'],
            'effective_to' => $attributes['effective_to'] ?? null,
            'currency' => filled($attributes['currency'] ?? null) ? strtoupper((string) $attributes['currency']) : null,
            'reason' => $attributes['reason'] ?? null,
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }

    private function matches(RevenueRule $rule, array $context): bool
    {
        return match ($rule->scope_type) {
            RevenueRuleScope::Global => true,
            RevenueRuleScope::Publisher => (string) ($context['publisher_id'] ?? '') === (string) $rule->scope_id,
            RevenueRuleScope::Website => (string) ($context['site_id'] ?? '') === (string) $rule->scope_id,
            RevenueRuleScope::DemandSource => in_array((string) $rule->scope_id, array_filter([
                (string) ($context['report_source_id'] ?? ''),
                (string) ($context['report_source_code'] ?? ''),
                (string) ($context['demand_network_id'] ?? ''),
            ]), true),
            RevenueRuleScope::Campaign => (string) ($context['campaign_id'] ?? '') === (string) $rule->scope_id,
        };
    }

    private function validateShares(array $attributes): void
    {
        $publisher = (int) $attributes['publisher_share_bp'];
        $horus = (int) $attributes['horus_share_bp'];
        $mcm = (int) ($attributes['mcm_partner_share_bp'] ?? 0);
        if ($publisher < 0 || $horus < 0 || $mcm < 0 || ($publisher + $horus + $mcm) !== 10000) {
            throw ValidationException::withMessages([
                'shares' => 'Publisher, Horus Media, and optional MCM partner shares must be non-negative and total exactly 10,000 basis points.',
            ]);
        }
    }

    private function assertEffectiveDateOpen(CarbonInterface|string $date): void
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : (string) $date;
        if (FinancialPeriod::query()
            ->where('status', FinancialPeriodStatus::Closed->value)
            ->whereDate('starts_on', '<=', $date)
            ->whereDate('ends_on', '>=', $date)
            ->exists()) {
            throw ValidationException::withMessages([
                'effective_from' => 'A revenue-share version cannot start inside a closed financial period.',
            ]);
        }
    }

    private function scopeOrganization(RevenueRuleScope $scope, ?string $scopeId): ?string
    {
        if ($scope === RevenueRuleScope::Global) {
            return null;
        }
        if (blank($scopeId)) {
            throw ValidationException::withMessages(['scope_id' => 'The selected rule scope requires a target.']);
        }

        return match ($scope) {
            RevenueRuleScope::Publisher => Publisher::withoutGlobalScopes()->findOrFail($scopeId)->organization_id,
            RevenueRuleScope::Website => Site::withoutGlobalScopes()->findOrFail($scopeId)->organization_id,
            RevenueRuleScope::Campaign => Campaign::withoutGlobalScopes()->findOrFail($scopeId)->organization_id,
            RevenueRuleScope::DemandSource => ReportSource::query()->whereKey($scopeId)->exists()
                || DemandNetwork::query()->whereKey($scopeId)->exists()
                    ? null
                    : throw ValidationException::withMessages(['scope_id' => 'The demand-source target does not exist.']),
            RevenueRuleScope::Global => null,
        };
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isHorusAdministrator() || ! $actor->hasPermission('finance.revenue_rules.manage')) {
            abort(403);
        }
    }
}
