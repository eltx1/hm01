<?php

namespace App\Services\Reporting;

use App\Enums\FinancialReadinessStatus;
use App\Enums\FinancialReportingMethod;
use App\Enums\ReconciliationStatus;
use App\Enums\ReportConnectionStatus;
use App\Enums\ReportFinality;
use App\Enums\ReportImportStatus;
use App\Models\BidderAccount;
use App\Models\DemandAccount;
use App\Models\FinancialPeriod;
use App\Models\MonetizationFinancialBinding;
use App\Models\ReconciliationRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class MonetizationFinancialReadinessService
{
    public function __construct(private readonly SiteGamFinancialCoverage $siteReports) {}

    /** @return array{status: string, ready: bool, reasons: array<int, array{code: string, message: string}>, binding: ?MonetizationFinancialBinding} */
    public function status(DemandAccount|BidderAccount $subject, ?string $expectedCurrency = null, ?FinancialPeriod $period = null): array
    {
        $type = $subject instanceof DemandAccount ? 'DEMAND_ACCOUNT' : 'BIDDER_ACCOUNT';
        $binding = MonetizationFinancialBinding::withoutGlobalScopes()
            ->with(['source', 'connection'])
            ->where('subject_type', $type)
            ->where('subject_id', $subject->id)
            ->first();

        if (! $binding || ! $binding->is_enabled || ! $binding->source?->is_enabled || ! $binding->connection || ! $binding->connection->is_enabled) {
            return $this->result(FinancialReadinessStatus::NotConfigured, 'MISSING_ACTIVE_FINANCIAL_BINDING', 'No active canonical financial source is bound to this monetization account.', $binding);
        }
        if ($expectedCurrency && strtoupper($binding->currency) !== strtoupper($expectedCurrency)) {
            return $this->result(FinancialReadinessStatus::CurrencyMismatch, 'FINANCIAL_SOURCE_CURRENCY_MISMATCH', "The binding currency {$binding->currency} does not match {$expectedCurrency}.", $binding);
        }
        if ($binding->reporting_method === FinancialReportingMethod::Estimate) {
            return $this->result(FinancialReadinessStatus::EstimateOnly, 'ESTIMATE_ONLY_METHOD', 'Estimate-only reporting cannot produce payout-eligible revenue.', $binding);
        }
        if (! $binding->is_finalized_capable) {
            $code = $binding->reporting_method === FinancialReportingMethod::Api
                ? 'PROVIDER_API_NOT_CONFIGURED'
                : 'METHOD_NOT_FINALIZED_CAPABLE';
            $status = $binding->reporting_method === FinancialReportingMethod::Api
                ? FinancialReadinessStatus::NotConfigured
                : FinancialReadinessStatus::EstimateOnly;

            return $this->result($status, $code, 'The selected reporting method is not configured and approved for finalized revenue.', $binding);
        }
        if ($binding->connection->status === ReportConnectionStatus::Error) {
            return $this->result(FinancialReadinessStatus::Failed, 'REPORT_CONNECTION_FAILED', 'The financial report connection is in an error state.', $binding);
        }
        if (! $binding->connection->last_successful_import_at) {
            return $this->result(FinancialReadinessStatus::NotConfigured, 'NO_SUCCESSFUL_IMPORT', 'No successful import has been recorded for this financial source.', $binding);
        }
        if (! $binding->connection->last_finalized_import_at) {
            return $this->result(FinancialReadinessStatus::ReconciliationRequired, 'NO_FINALIZED_IMPORT', 'No payout-eligible finalized import has been recorded.', $binding);
        }

        $reconciliation = ReconciliationRun::withoutGlobalScopes()
            ->where('report_source_connection_id', $binding->connection->id)
            ->when($period, fn ($query) => $query
                ->whereDate('period_start', '<=', $period->ends_on)
                ->whereDate('period_end', '>=', $period->starts_on))
            ->latest('created_at')
            ->first();
        if (! $reconciliation) {
            return $this->result(FinancialReadinessStatus::ReconciliationRequired, 'MISSING_RECONCILIATION', 'Finalized source data has not completed reconciliation.', $binding);
        }
        if (in_array($reconciliation->status, [ReconciliationStatus::Failed], true)) {
            return $this->result(FinancialReadinessStatus::Failed, 'RECONCILIATION_FAILED', 'Financial-source reconciliation failed.', $binding);
        }
        if (! in_array($reconciliation->status, [ReconciliationStatus::Matched, ReconciliationStatus::Resolved], true)) {
            return $this->result(FinancialReadinessStatus::ReconciliationRequired, 'RECONCILIATION_UNRESOLVED', 'Financial-source reconciliation is unfinished or requires remediation.', $binding);
        }

        if ($period) {
            $eligibleRows = $binding->connection->imports()
                ->where('finality', ReportFinality::Finalized->value)
                ->where('settlement_eligible', true)
                ->whereDate('period_start', '<=', $period->ends_on)
                ->whereDate('period_end', '>=', $period->starts_on)
                ->exists();
            if (! $eligibleRows) {
                return $this->result(FinancialReadinessStatus::Stale, 'NO_PERIOD_COVERAGE', 'No payout-eligible finalized import covers this financial period.', $binding);
            }
        } elseif ($binding->connection->last_finalized_import_at->lt(now()->subDays((int) config('reporting.financial_source_stale_days', 3)))) {
            return $this->result(FinancialReadinessStatus::Stale, 'FINALIZED_DATA_STALE', 'The last finalized source data is stale.', $binding);
        }

        return [
            'status' => FinancialReadinessStatus::Ready->value,
            'ready' => true,
            'reasons' => [],
            'binding' => $binding,
            'last_successful_import_at' => $binding->connection->last_successful_import_at,
            'last_finalized_data_at' => $binding->connection->last_finalized_import_at,
            'reconciliation_status' => $reconciliation->status->value,
        ];
    }

    /** @return Collection<int, array{subject_type: string, subject_id: string, subject_name: string, status: string, reasons: array}> */
    public function blockersForPeriod(FinancialPeriod $period): Collection
    {
        $subjects = collect();
        DemandAccount::withoutGlobalScopes()
            ->where('is_enabled', true)
            ->where('approval_status', 'APPROVED')
            ->whereDate('created_at', '<=', $period->ends_on)
            ->whereHas('sites', fn ($query) => $query->where('is_enabled', true))
            ->get()
            ->each(fn (DemandAccount $account) => $subjects->push($account));
        BidderAccount::withoutGlobalScopes()
            ->where('enabled', true)
            ->whereDate('created_at', '<=', $period->ends_on)
            ->whereHas('siteMappings', fn ($query) => $query->where('enabled', true))
            ->get()
            ->each(fn (BidderAccount $account) => $subjects->push($account));

        $subjectBlockers = $subjects->map(function (DemandAccount|BidderAccount $subject) use ($period): ?array {
            $siteIds = $subject instanceof DemandAccount
                ? $subject->sites()->where('is_enabled', true)->pluck('site_id')
                : $subject->siteMappings()->where('enabled', true)->pluck('site_id');
            $configuredCurrency = strtoupper((string) (
                $subject->financialBinding?->currency
                ?? ($subject instanceof DemandAccount ? data_get($subject->configuration, 'currency') : null)
                ?? config('reporting.default_currency', 'USD')
            ));
            if ($configuredCurrency !== strtoupper($period->currency)) {
                return null;
            }

            // Site-level GAM reporting may substitute for a provider-specific
            // financial source only after an explicit finance attestation on the
            // subject's binding. Presence of a Site GAM report alone never proves
            // that Direct JS, VAST or standalone Prebid revenue is included there.
            $siteGamDeclared = (bool) data_get(
                $subject->financialBinding?->configuration,
                'site_gam_included',
                false,
            );
            if ($siteGamDeclared) {
                $siteGamComplete = $subject->financialBinding?->is_enabled
                    && $siteIds->isNotEmpty()
                    && $siteIds->every(fn ($id) => $this->siteReports->coversSite($id, $period));

                if ($siteGamComplete) {
                    return null;
                }

                return [
                    'subject_type' => $subject instanceof DemandAccount ? 'DEMAND_ACCOUNT' : 'BIDDER_ACCOUNT',
                    'subject_id' => $subject->id,
                    'subject_name' => $subject->name,
                    'status' => FinancialReadinessStatus::Stale->value,
                    'reasons' => [[
                        'code' => 'SITE_GAM_DECLARED_COVERAGE_INCOMPLETE',
                        'message' => 'This provider declares Site GAM as its canonical financial source, but complete finalized Site GAM coverage is missing for one or more active websites.',
                    ]],
                ];
            }
            $result = $this->status($subject, $period->currency, $period);
            if ($result['ready'] && ! $this->hasCompletePeriodImportCoverage($subject, $result['binding'], $period)) {
                $result = [
                    ...$result,
                    'status' => FinancialReadinessStatus::Stale->value,
                    'ready' => false,
                    'reasons' => [[
                        'code' => 'INCOMPLETE_PERIOD_COVERAGE',
                        'message' => 'The canonical provider financial source does not have finalized import coverage for every active day in this period.',
                    ]],
                ];
            }
            if ($result['ready']) {
                return null;
            }

            return [
                'subject_type' => $subject instanceof DemandAccount ? 'DEMAND_ACCOUNT' : 'BIDDER_ACCOUNT',
                'subject_id' => $subject->id,
                'subject_name' => $subject->name,
                'status' => $result['status'],
                'reasons' => $result['reasons'],
            ];
        })->filter()->values();

        // An attested provider and its Site GAM binding represent one canonical
        // financial source. If that coverage is incomplete, surface the provider
        // blocker once instead of counting the same missing Site GAM day twice.
        $attestedSiteIds = $subjects->filter(function (DemandAccount|BidderAccount $subject) use ($period): bool {
            $binding = $subject->financialBinding;
            if (! $binding?->is_enabled
                || ! (bool) data_get($binding->configuration, 'site_gam_included', false)
                || strtoupper((string) $binding->currency) !== strtoupper((string) $period->currency)) {
                return false;
            }

            return true;
        })->flatMap(fn (DemandAccount|BidderAccount $subject) => $subject instanceof DemandAccount
            ? $subject->sites()->where('is_enabled', true)->pluck('site_id')
            : $subject->siteMappings()->where('enabled', true)->pluck('site_id')
        )->filter()->unique()->values();

        $siteBlockers = $this->siteReports->blockers($period)
            ->reject(fn (array $blocker): bool => $attestedSiteIds->contains((string) ($blocker['subject_id'] ?? '')))
            ->values();

        return $subjectBlockers->concat($siteBlockers)->values();
    }

    private function hasCompletePeriodImportCoverage(
        DemandAccount|BidderAccount $subject,
        MonetizationFinancialBinding $binding,
        FinancialPeriod $period,
    ): bool {
        $from = CarbonImmutable::parse($period->starts_on)->startOfDay();
        $to = CarbonImmutable::parse($period->ends_on)->endOfDay();

        if ($subject->created_at) {
            $from = $from->max(CarbonImmutable::parse($subject->created_at)->startOfDay());
        }
        $mappingStart = $subject instanceof DemandAccount
            ? $subject->sites()->where('is_enabled', true)->min('created_at')
            : $subject->siteMappings()->where('enabled', true)->min('created_at');
        if ($mappingStart) {
            $from = $from->max(CarbonImmutable::parse($mappingStart)->startOfDay());
        }
        if ($binding->effective_from) {
            $from = $from->max(CarbonImmutable::parse($binding->effective_from)->startOfDay());
        }
        if ($binding->effective_to) {
            $to = $to->min(CarbonImmutable::parse($binding->effective_to)->endOfDay());
        }
        if ($from->gt($to)) {
            return true;
        }

        $imports = $binding->connection->imports()
            ->where('status', ReportImportStatus::Completed->value)
            ->where('finality', ReportFinality::Finalized->value)
            ->where('settlement_eligible', true)
            ->whereDate('period_start', '<=', $to->toDateString())
            ->whereDate('period_end', '>=', $from->toDateString())
            ->get(['period_start', 'period_end']);

        for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            $date = $day->toDateString();
            $covered = $imports->contains(fn ($import) =>
                $import->period_start->toDateString() <= $date
                && $import->period_end->toDateString() >= $date
            );
            if (! $covered) {
                return false;
            }
        }

        return true;
    }

    private function result(FinancialReadinessStatus $status, string $code, string $message, ?MonetizationFinancialBinding $binding): array
    {
        $reconciliationStatus = $binding?->connection
            ? ReconciliationRun::withoutGlobalScopes()
                ->where('report_source_connection_id', $binding->connection->id)
                ->latest('created_at')
                ->value('status')
            : null;

        return [
            'status' => $status->value,
            'ready' => false,
            'reasons' => [['code' => $code, 'message' => $message]],
            'binding' => $binding,
            'last_successful_import_at' => $binding?->connection?->last_successful_import_at,
            'last_finalized_data_at' => $binding?->connection?->last_finalized_import_at,
            'reconciliation_status' => $reconciliationStatus,
        ];
    }
}
