<?php

namespace App\Services\Reporting;

use App\Models\DailyReport;
use App\Models\ReportSourceConnection;
use App\Models\SiteGamReportBinding;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;

final class SiteGamReportingCurrencyPolicy
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function canonical(): string
    {
        return strtoupper((string) config('reporting.canonical_currency', 'USD'));
    }

    public function normalize(
        SiteGamReportBinding $binding,
        ?string $networkCurrency = null,
        ?User $actor = null,
    ): ?ReportSourceConnection {
        $binding->loadMissing('connection.source');
        $connection = $binding->connection;
        if (! $connection) {
            return null;
        }

        $canonical = $this->canonical();
        $configuration = (array) ($connection->configuration ?? []);
        $sourceCurrency = strtoupper(trim((string) (
            $networkCurrency
            ?: data_get($configuration, 'network_currency')
            ?: $connection->currency
        )));
        if (! preg_match('/^[A-Z]{3}$/D', $sourceCurrency)) {
            $sourceCurrency = strtoupper((string) $connection->currency);
        }

        if ($connection->currency === $canonical) {
            $changed = false;
            if (data_get($configuration, 'network_currency') !== $sourceCurrency) {
                $configuration['network_currency'] = $sourceCurrency;
                $changed = true;
            }
            if (data_get($configuration, 'report_currency') !== $canonical) {
                $configuration['report_currency'] = $canonical;
                $changed = true;
            }
            if ($changed) {
                $connection->update([
                    'configuration' => $configuration,
                    'updated_by' => $actor?->id ?? $connection->updated_by,
                ]);
            }

            return $connection->fresh(['source']);
        }

        $closedThrough = DailyReport::withoutGlobalScopes()
            ->where('report_source_connection_id', $connection->id)
            ->whereHas('period', fn ($query) => $query->where('status', '!=', 'OPEN'))
            ->max('report_date');

        $cutover = CarbonImmutable::parse($binding->starts_on->toDateString(), $connection->timezone);
        if ($closedThrough) {
            $cutover = $cutover->max(
                CarbonImmutable::parse($closedThrough, $connection->timezone)->addDay()
            );
        }

        $previousReportingCurrency = strtoupper((string) $connection->currency);
        $configuration['network_currency'] = $sourceCurrency;
        $configuration['report_currency'] = $canonical;
        $configuration['canonical_currency_start_on'] = $cutover->toDateString();
        unset($configuration['google_jobs'], $configuration['sync_due']);

        $connection->update([
            'currency' => $canonical,
            'configuration' => $configuration,
            'last_successful_import_at' => null,
            'last_finalized_import_at' => null,
            'last_error' => null,
            'updated_by' => $actor?->id ?? $connection->updated_by,
        ]);

        $this->audit->record(
            'reporting.site_gam.currency_normalized',
            $binding->organization_id,
            $actor,
            $binding,
            ['report_currency' => $previousReportingCurrency],
            ['report_currency' => $canonical],
            [
                'network_currency' => $sourceCurrency,
                'cutover_start_on' => $cutover->toDateString(),
            ],
        );

        return $connection->fresh(['source']);
    }
}
