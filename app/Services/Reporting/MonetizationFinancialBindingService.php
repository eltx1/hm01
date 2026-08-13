<?php

namespace App\Services\Reporting;

use App\Enums\FinancialReportingMethod;
use App\Enums\MonetizationSubjectType;
use App\Enums\ReportSourceCode;
use App\Models\BidderAccount;
use App\Models\DemandAccount;
use App\Models\MonetizationFinancialBinding;
use App\Models\ReportSource;
use App\Models\ReportSourceConnection;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MonetizationFinancialBindingService
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function bind(
        DemandAccount|BidderAccount $subject,
        ReportSource $source,
        FinancialReportingMethod $method,
        string $currency,
        string $timezone,
        User $actor,
        array $configuration = [],
        bool $enabled = true,
    ): MonetizationFinancialBinding {
        if (! $actor->isHorusAdministrator() || ! $actor->hasPermission('reporting.sources.manage')) {
            abort(403);
        }

        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw ValidationException::withMessages(['currency' => 'Currency must be a three-letter ISO code.']);
        }
        if ($this->containsSensitiveKey($configuration)) {
            throw ValidationException::withMessages(['configuration' => 'Financial binding configuration must contain non-secret metadata only.']);
        }

        $this->assertSourceMatchesSubject($subject, $source);

        $type = $subject instanceof DemandAccount
            ? MonetizationSubjectType::DemandAccount
            : MonetizationSubjectType::BidderAccount;
        $finalizedCapable = $this->supportsFinalizedMethod($source, $method)
            && $this->providerMethodConfigured($subject, $method);

        return DB::transaction(function () use (
            $subject, $source, $method, $currency, $timezone, $actor, $configuration,
            $enabled, $type, $finalizedCapable
        ): MonetizationFinancialBinding {
            $connection = ReportSourceConnection::withoutGlobalScopes()->updateOrCreate(
                [
                    'report_source_id' => $source->id,
                    'connection_type' => $type->value,
                    'connection_id' => $subject->id,
                ],
                [
                    'organization_id' => $subject->organization_id,
                    'name' => $subject->name,
                    'account_identifier' => $subject instanceof DemandAccount ? $subject->account_identifier : $subject->publisher_id,
                    'currency' => $currency,
                    'timezone' => $timezone,
                    'status' => 'ACTIVE',
                    'is_enabled' => $enabled,
                    'configuration' => $configuration ?: null,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ],
            );

            $binding = MonetizationFinancialBinding::withoutGlobalScopes()->updateOrCreate(
                ['subject_type' => $type->value, 'subject_id' => $subject->id],
                [
                    'organization_id' => $subject->organization_id,
                    'report_source_id' => $source->id,
                    'report_source_connection_id' => $connection->id,
                    'reporting_method' => $method,
                    'currency' => $currency,
                    'timezone' => $timezone,
                    'is_enabled' => $enabled,
                    'is_finalized_capable' => $finalizedCapable,
                    'configuration' => $configuration ?: null,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ],
            );

            $this->audit->record('finance.monetization_financial_source.bound', $subject->organization_id, $actor, $binding, newValues: [
                'subject_type' => $type->value,
                'subject_id' => $subject->id,
                'report_source' => $source->code->value,
                'reporting_method' => $method->value,
                'currency' => $currency,
                'timezone' => $timezone,
                'is_enabled' => $enabled,
                'is_finalized_capable' => $finalizedCapable,
                'configuration_keys' => array_keys($configuration),
            ]);

            return $binding->load(['source', 'connection']);
        });
    }

    private function supportsFinalizedMethod(ReportSource $source, FinancialReportingMethod $method): bool
    {
        if ($method === FinancialReportingMethod::Estimate || $source->code->value === 'PREBID_ESTIMATES') {
            return false;
        }

        return in_array(
            $method->value,
            (array) config('reporting.sources.'.$source->code->value.'.finalized_methods', []),
            true,
        );
    }

    private function assertSourceMatchesSubject(DemandAccount|BidderAccount $subject, ReportSource $source): void
    {
        if ($subject instanceof DemandAccount) {
            $subject->loadMissing('network');
            $expected = ReportSourceCode::tryFrom($subject->network->code->value) ?? ReportSourceCode::CustomCsv;
            if (! in_array($source->code, [$expected, ReportSourceCode::CustomCsv], true)) {
                throw ValidationException::withMessages(['report_source_id' => 'The selected source does not represent this Demand provider.']);
            }

            return;
        }

        $subject->loadMissing('bidder');
        $allowed = $subject->bidder?->code === 'onetag'
            ? [ReportSourceCode::OneTag, ReportSourceCode::PrebidEstimates, ReportSourceCode::CustomCsv]
            : [ReportSourceCode::PrebidEstimates, ReportSourceCode::CustomCsv];
        if (! in_array($source->code, $allowed, true)) {
            throw ValidationException::withMessages(['report_source_id' => 'The selected source does not represent this bidder provider.']);
        }
    }

    private function providerMethodConfigured(Model $subject, FinancialReportingMethod $method): bool
    {
        if ($method !== FinancialReportingMethod::Api) {
            return true;
        }
        if ($subject instanceof BidderAccount) {
            return false;
        }

        return filled(data_get($subject->configuration, 'api_base_url'))
            && filled(data_get($subject->configuration, 'report_path'));
    }

    private function containsSensitiveKey(array $configuration): bool
    {
        foreach ($configuration as $key => $value) {
            $normalized = strtolower((string) $key);
            foreach (['password', 'secret', 'token', 'api_key', 'private_key', 'credential'] as $sensitive) {
                if (str_contains($normalized, $sensitive)) {
                    return true;
                }
            }
            if (is_array($value) && $this->containsSensitiveKey($value)) {
                return true;
            }
        }

        return false;
    }
}
