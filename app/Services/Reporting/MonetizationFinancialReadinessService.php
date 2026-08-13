<?php

namespace App\Services\Reporting;

use App\Enums\FinancialReadinessStatus;
use App\Enums\FinancialReportingMethod;
use App\Enums\ReconciliationStatus;
use App\Enums\ReportConnectionStatus;
use App\Enums\ReportFinality;
use App\Models\BidderAccount;
use App\Models\DemandAccount;
use App\Models\FinancialPeriod;
use App\Models\MonetizationFinancialBinding;
use App\Models\ReconciliationRun;
use Illuminate\Support\Collection;

final class MonetizationFinancialReadinessService
{
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

        return $subjects->map(function (DemandAccount|BidderAccount $subject) use ($period): ?array {
            $configuredCurrency = strtoupper((string) (
                $subject->financialBinding?->currency
                ?? ($subject instanceof DemandAccount ? data_get($subject->configuration, 'currency') : null)
                ?? config('reporting.default_currency', 'USD')
            ));
            if ($configuredCurrency !== strtoupper($period->currency)) {
                return null;
            }
            $result = $this->status($subject, $period->currency, $period);
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
