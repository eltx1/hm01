<?php

namespace App\Services\Reporting;

use App\Models\DailyReport;
use App\Models\FinancialPeriod;
use App\Models\HourlyReport;
use App\Models\ReconciliationRun;
use App\Models\ReportImportJob;
use App\Models\ReportSourceConnection;
use App\Models\SiteGamReportBinding;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SiteGamCurrencyPolicy
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function currency(): string
    {
        $currency = strtoupper(trim((string) config('reporting.gam_report_currency', 'USD')));

        return preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : 'USD';
    }

    /**
     * Keep Google network currency as provenance only. Site GAM money enters the
     * Horus ledger in one canonical currency requested directly from Google.
     */
    public function enforce(SiteGamReportBinding $binding, ?User $actor = null): SiteGamReportBinding
    {
        $binding->loadMissing(['connection', 'site', 'gamConnection']);
        $connection = $binding->connection;
        if (! $connection) {
            return $binding;
        }

        $canonical = $this->currency();
        if (strtoupper((string) $connection->currency) === $canonical) {
            $configuration = (array) ($connection->configuration ?? []);
            if (($configuration['canonical_report_currency'] ?? null) !== $canonical) {
                $configuration['canonical_report_currency'] = $canonical;
                $connection->update(['configuration' => $configuration]);
            }

            return $binding;
        }

        return DB::transaction(function () use ($binding, $connection, $canonical, $actor): SiteGamReportBinding {
            /** @var SiteGamReportBinding $lockedBinding */
            $lockedBinding = SiteGamReportBinding::withoutGlobalScopes()
                ->with(['connection', 'site', 'gamConnection'])
                ->whereKey($binding->id)
                ->lockForUpdate()
                ->firstOrFail();
            /** @var ReportSourceConnection $lockedConnection */
            $lockedConnection = ReportSourceConnection::withoutGlobalScopes()
                ->whereKey($connection->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (strtoupper((string) $lockedConnection->currency) === $canonical) {
                return $lockedBinding;
            }

            $oldCurrency = strtoupper((string) $lockedConnection->currency);
            $lockedPeriodIds = FinancialPeriod::query()
                ->where('status', '!=', 'OPEN')
                ->pluck('id');

            $lastLocked = collect([
                DailyReport::withoutGlobalScopes()
                    ->where('report_source_connection_id', $lockedConnection->id)
                    ->whereIn('financial_period_id', $lockedPeriodIds)
                    ->max('report_date'),
                HourlyReport::withoutGlobalScopes()
                    ->where('report_source_connection_id', $lockedConnection->id)
                    ->whereIn('financial_period_id', $lockedPeriodIds)
                    ->max('report_date'),
            ])->filter()->max();

            $configuration = (array) ($lockedConnection->configuration ?? []);
            $configuration['network_currency'] ??= $oldCurrency;
            $configuration['canonical_report_currency'] = $canonical;
            unset($configuration['google_jobs'], $configuration['sync_due']);

            // No immutable financial history exists for this connection. Rebase
            // the connection in place and let the next scheduler pass re-request
            // the exact same dates from Google in USD.
            if (! $lastLocked) {
                ReconciliationRun::withoutGlobalScopes()
                    ->where('report_source_connection_id', $lockedConnection->id)
                    ->delete();
                DailyReport::withoutGlobalScopes()
                    ->where('report_source_connection_id', $lockedConnection->id)
                    ->delete();
                HourlyReport::withoutGlobalScopes()
                    ->where('report_source_connection_id', $lockedConnection->id)
                    ->delete();
                ReportImportJob::withoutGlobalScopes()
                    ->where('report_source_connection_id', $lockedConnection->id)
                    ->delete();

                $lockedConnection->update([
                    'currency' => $canonical,
                    'configuration' => $configuration,
                    'status' => 'ACTIVE',
                    'is_enabled' => true,
                    'last_attempted_at' => null,
                    'last_successful_import_at' => null,
                    'last_finalized_import_at' => null,
                    'last_error' => null,
                    'updated_by' => $actor?->id ?? $lockedConnection->updated_by,
                ]);

                $this->audit->record(
                    'reporting.site_gam.currency_rebased',
                    $lockedBinding->organization_id,
                    $actor,
                    $lockedBinding,
                    ['currency' => $oldCurrency],
                    ['currency' => $canonical],
                    ['mode' => 'OPEN_HISTORY_REIMPORT', 'network_currency' => $configuration['network_currency']],
                );

                return $lockedBinding->fresh(['connection', 'site', 'gamConnection']);
            }

            // Closed/closing rows are immutable. Keep them on the old connection
            // and cut over the active website source immediately after the last
            // locked reporting day. Open rows at/after the cutover are discarded
            // and safely re-requested from Google in the canonical currency.
            if (! $lockedBinding->active_site_id || $lockedBinding->ends_on) {
                $configuration['canonical_currency_pending'] = true;
                $lockedConnection->update(['configuration' => $configuration]);

                return $lockedBinding;
            }

            $cutover = CarbonImmutable::parse($lastLocked, $lockedConnection->timezone)->addDay()->startOfDay();
            $this->purgeOpenCutoverData($lockedConnection, $cutover);

            $newId = (string) Str::ulid();
            $newConfiguration = $configuration;
            unset($newConfiguration['canonical_currency_pending']);

            $newConnection = ReportSourceConnection::withoutGlobalScopes()->create([
                'id' => (string) Str::ulid(),
                'organization_id' => $lockedConnection->organization_id,
                'report_source_id' => $lockedConnection->report_source_id,
                'name' => $lockedConnection->name,
                'connection_type' => 'SITE_GAM_AD_UNIT',
                'connection_id' => $newId,
                'account_identifier' => $lockedConnection->account_identifier,
                'currency' => $canonical,
                'timezone' => $lockedConnection->timezone,
                'status' => 'ACTIVE',
                'is_enabled' => true,
                'configuration' => $newConfiguration,
                'created_by' => $actor?->id ?? $lockedConnection->created_by,
                'updated_by' => $actor?->id ?? $lockedConnection->updated_by,
            ]);

            $lockedBinding->update([
                'active_site_id' => null,
                'active_unit_key' => null,
                'ends_on' => $cutover->subDay()->toDateString(),
            ]);
            $lockedConnection->update([
                'status' => 'DISABLED',
                'is_enabled' => false,
                'configuration' => $configuration,
                'last_error' => null,
                'updated_by' => $actor?->id ?? $lockedConnection->updated_by,
            ]);

            $newBinding = SiteGamReportBinding::withoutGlobalScopes()->create([
                'id' => $newId,
                'organization_id' => $lockedBinding->organization_id,
                'site_id' => $lockedBinding->site_id,
                'gam_connection_id' => $lockedBinding->gam_connection_id,
                'report_source_connection_id' => $newConnection->id,
                'active_site_id' => $lockedBinding->site_id,
                'active_unit_key' => $lockedBinding->network_code.':'.$lockedBinding->ad_unit_id,
                'network_code' => $lockedBinding->network_code,
                'ad_unit_id' => $lockedBinding->ad_unit_id,
                'ad_unit_name' => $lockedBinding->ad_unit_name,
                'ad_unit_code' => $lockedBinding->ad_unit_code,
                'starts_on' => $cutover->toDateString(),
                'created_by' => $actor?->id ?? $lockedBinding->created_by,
            ]);

            $this->audit->record(
                'reporting.site_gam.currency_cutover',
                $lockedBinding->organization_id,
                $actor,
                $newBinding,
                ['currency' => $oldCurrency, 'connection_id' => $lockedConnection->id],
                ['currency' => $canonical, 'connection_id' => $newConnection->id],
                [
                    'cutover_date' => $cutover->toDateString(),
                    'locked_history_preserved' => true,
                    'network_currency' => $configuration['network_currency'],
                ],
            );

            return $newBinding->fresh(['connection', 'site', 'gamConnection']);
        });
    }

    private function purgeOpenCutoverData(ReportSourceConnection $connection, CarbonImmutable $cutover): void
    {
        $openPeriodIds = FinancialPeriod::query()->where('status', 'OPEN')->pluck('id');

        ReconciliationRun::withoutGlobalScopes()
            ->where('report_source_connection_id', $connection->id)
            ->whereDate('period_end', '>=', $cutover->toDateString())
            ->delete();

        DailyReport::withoutGlobalScopes()
            ->where('report_source_connection_id', $connection->id)
            ->whereIn('financial_period_id', $openPeriodIds)
            ->whereDate('report_date', '>=', $cutover->toDateString())
            ->delete();
        HourlyReport::withoutGlobalScopes()
            ->where('report_source_connection_id', $connection->id)
            ->whereIn('financial_period_id', $openPeriodIds)
            ->whereDate('report_date', '>=', $cutover->toDateString())
            ->delete();

        ReportImportJob::withoutGlobalScopes()
            ->where('report_source_connection_id', $connection->id)
            ->where(function ($query) use ($cutover): void {
                $query->whereDate('period_start', '>=', $cutover->toDateString())
                    ->orWhereIn('status', ['PENDING', 'FAILED', 'PROCESSING']);
            })
            ->delete();
    }
}
