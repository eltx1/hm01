<?php

namespace App\Services\Reporting;

use App\Enums\FinancialReportingMethod;
use App\Enums\ReportFinality;
use App\Models\MonetizationFinancialBinding;
use App\Models\ReportSourceConnection;
use Carbon\CarbonInterface;

final class FinancialSettlementEligibilityService
{
    /** @return array{eligible: bool, reason: ?string, method: string} */
    public function forImport(
        ReportSourceConnection $connection,
        ReportFinality $requestedFinality,
        string $importType,
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
    ): array
    {
        $connection->loadMissing('source');
        $method = $this->methodForImport($importType);
        if ($requestedFinality !== ReportFinality::Finalized) {
            return ['eligible' => false, 'reason' => 'IMPORT_NOT_FINALIZED', 'method' => $method->value];
        }
        if ($connection->source->code->value === 'PREBID_ESTIMATES') {
            return ['eligible' => false, 'reason' => 'PREBID_ESTIMATES_NEVER_SETTLEMENT_ELIGIBLE', 'method' => $method->value];
        }
        if (! $connection->is_enabled || ! $connection->source->is_enabled) {
            return ['eligible' => false, 'reason' => 'FINANCIAL_SOURCE_DISABLED', 'method' => $method->value];
        }

        if (in_array($connection->connection_type, ['DEMAND_ACCOUNT', 'BIDDER_ACCOUNT'], true)) {
            $binding = MonetizationFinancialBinding::withoutGlobalScopes()
                ->where('subject_type', $connection->connection_type)
                ->where('subject_id', $connection->connection_id)
                ->where('report_source_connection_id', $connection->id)
                ->first();
            if (! $binding || ! $binding->is_enabled) {
                return ['eligible' => false, 'reason' => 'FINANCIAL_SOURCE_BINDING_NOT_CONFIGURED', 'method' => $method->value];
            }
            if ($binding->reporting_method !== $method) {
                return ['eligible' => false, 'reason' => 'REPORTING_METHOD_MISMATCH', 'method' => $method->value];
            }
            if (! $binding->is_finalized_capable) {
                return ['eligible' => false, 'reason' => 'REPORTING_METHOD_NOT_FINALIZED_CAPABLE', 'method' => $method->value];
            }
            if (strtoupper($connection->currency) !== strtoupper($binding->currency)) {
                return ['eligible' => false, 'reason' => 'BINDING_CURRENCY_MISMATCH', 'method' => $method->value];
            }
            if ($binding->effective_from && $to && $binding->effective_from->gt($to)) {
                return ['eligible' => false, 'reason' => 'BINDING_NOT_YET_EFFECTIVE', 'method' => $method->value];
            }
            if ($binding->effective_to && $from && $binding->effective_to->lt($from)) {
                return ['eligible' => false, 'reason' => 'BINDING_EXPIRED', 'method' => $method->value];
            }

            return ['eligible' => true, 'reason' => null, 'method' => $method->value];
        }

        $allowed = (array) config('reporting.sources.'.$connection->source->code->value.'.finalized_methods', []);
        if (! in_array($method->value, $allowed, true) && ! ($importType === 'SYSTEM' && in_array('API', $allowed, true))) {
            return ['eligible' => false, 'reason' => 'SOURCE_METHOD_NOT_FINALIZED_CAPABLE', 'method' => $method->value];
        }

        return ['eligible' => true, 'reason' => null, 'method' => $method->value];
    }

    private function methodForImport(string $importType): FinancialReportingMethod
    {
        return match (true) {
            str_contains($importType, 'CSV') => FinancialReportingMethod::Csv,
            $importType === 'MANUAL' => FinancialReportingMethod::Manual,
            str_contains($importType, 'ESTIMATE') => FinancialReportingMethod::Estimate,
            default => FinancialReportingMethod::Api,
        };
    }
}
