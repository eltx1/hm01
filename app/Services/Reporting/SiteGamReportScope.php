<?php

namespace App\Services\Reporting;

use App\Models\DailyReport;
use App\Models\HourlyReport;
use App\Models\ReportSourceConnection;
use App\Models\SiteGamReportBinding;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Versioned forward-only attribution, independent of ad serving. */
final class SiteGamReportScope
{
    public const VERSION = 'AD_UNIT_AND_EXACT_SITE_V2';

    public function __construct(private readonly AuditRecorder $audit) {}

    public function hostname(string $hostname): string
    {
        $input = trim($hostname);
        $parts = parse_url(preg_match('#^[a-z][a-z0-9+.-]*://#i', $input) ? $input : 'https://'.$input);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || empty($parts['host'])) {
            throw new RuntimeException('The registered website hostname is invalid for exact Google Site reporting.');
        }
        $hostname = strtolower(preg_replace('/\.$/D', '', $parts['host']));
        if (! filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! str_contains($hostname, '.') || str_ends_with($hostname, '.')) {
            throw new RuntimeException('The registered website hostname is invalid for exact Google Site reporting.');
        }
        return $hostname;
    }

    /** New and existing publishers use their registered hostname automatically. */
    public function ensure(SiteGamReportBinding $binding): array
    {
        $binding->loadMissing('site', 'connection');
        if (! $binding->site || ! $binding->connection
            || $binding->connection->connection_type !== 'SITE_GAM_AD_UNIT'
            || $binding->connection->connection_id !== $binding->id
            || $binding->connection->organization_id !== $binding->organization_id
            || $binding->site->organization_id !== $binding->organization_id) {
            throw new RuntimeException('The website reporting binding identity is inconsistent.');
        }
        $hostname = $this->hostname($binding->site->primary_domain);
        return DB::transaction(function () use ($binding, $hostname): array {
            $connection = ReportSourceConnection::withoutGlobalScopes()->lockForUpdate()->findOrFail($binding->report_source_connection_id);
            $configuration = (array) ($connection->configuration ?? []);
            $current = $configuration['site_report_scope'] ?? null;
            if (is_array($current) && ($current['version'] ?? '') === self::VERSION
                && ($current['metric_basis'] ?? '') === SiteGamReportMetrics::BASIS
                && ($current['hostname'] ?? '') === $hostname && ($current['binding_id'] ?? '') === $binding->id
                && ($current['network_code'] ?? '') === $binding->network_code && ($current['ad_unit_id'] ?? '') === $binding->ad_unit_id
                && ($current['currency'] ?? '') === $connection->currency && ($current['timezone'] ?? '') === $connection->timezone
                && $this->valid($current)) {
                return $current;
            }
            $last = null;
            foreach ([DailyReport::class, HourlyReport::class] as $model) {
                $date = $model::withoutGlobalScopes()->where('report_source_connection_id', $connection->id)->max('report_date');
                if ($date !== null && ($last === null || $date > $last)) $last = $date;
            }
            $starts = CarbonImmutable::parse($binding->starts_on->toDateString(), $connection->timezone);
            if ($last !== null) {
                // Never overwrite, supersede or duplicate an already stored day.
                $starts = $starts->max(CarbonImmutable::now($connection->timezone)->startOfDay())
                    ->max(CarbonImmutable::parse($last, $connection->timezone)->addDay());
            }
            $scope = [
                'version' => self::VERSION, 'metric_basis' => SiteGamReportMetrics::BASIS, 'binding_id' => $binding->id,
                'network_code' => $binding->network_code, 'ad_unit_id' => $binding->ad_unit_id,
                'hostname' => $hostname, 'currency' => $connection->currency, 'timezone' => $connection->timezone,
                'effective_from' => $starts->toDateString(), 'recorded_at' => now()->toIso8601String(),
                'preserved_history_through' => $last, 'historical_review_required' => $last !== null,
            ];
            $scope['fingerprint'] = $this->fingerprint($scope);
            if (is_array($current)) $configuration['site_report_scope_history'][] = $current;
            $configuration['site_report_scope'] = $scope;
            // Scheduler checkpoints are separate from scope identity. Legacy
            // Google jobs remain auditable, but the new key cannot resume them.
            unset($configuration['sync_due']);
            $connection->update(['configuration' => $configuration]);
            $this->audit->record('reporting.site_gam.exact_site_scope', $binding->organization_id, auditable: $binding,
                oldValues: ['scope' => $current], newValues: ['scope' => $scope],
                metadata: ['financial_rows_changed' => false, 'automatic_forward_cutover' => true]);
            return $scope;
        });
    }

    public function assertCurrent(SiteGamReportBinding $binding, array $scope, bool $lock = false): void
    {
        $binding->load([
            'site' => fn ($q) => $q->when($lock, fn ($q) => $q->lockForUpdate()),
            'connection' => fn ($q) => $q->when($lock, fn ($q) => $q->lockForUpdate()),
        ]);
        $stored = data_get($binding->connection?->configuration, 'site_report_scope');
        if (! is_array($stored) || ! $this->valid($scope) || ! $this->valid($stored)
            || $scope['version'] !== self::VERSION || $scope['metric_basis'] !== SiteGamReportMetrics::BASIS
            || ! hash_equals($scope['fingerprint'], $stored['fingerprint'])
            || $scope['hostname'] !== $this->hostname((string) $binding->site?->primary_domain)
            || $scope['binding_id'] !== $binding->id || $scope['ad_unit_id'] !== $binding->ad_unit_id
            || $scope['network_code'] !== $binding->network_code
            || $scope['currency'] !== $binding->connection?->currency || $scope['timezone'] !== $binding->connection?->timezone
            || $binding->connection?->connection_type !== 'SITE_GAM_AD_UNIT'
            || $binding->connection?->connection_id !== $binding->id
            || $binding->connection?->organization_id !== $binding->organization_id
            || $binding->site?->organization_id !== $binding->organization_id) {
            throw new RuntimeException('The website report scope changed while Google prepared the report. A fresh exact-site report is required.');
        }
    }

    public function assertNoConflictingFacts(ReportSourceConnection $connection, string $day, string $fingerprint): void
    {
        foreach ([DailyReport::class, HourlyReport::class] as $model) {
            if ($model::withoutGlobalScopes()->where('report_source_connection_id', $connection->id)->whereDate('report_date', $day)
                ->whereHas('dimension', fn ($q) => $q->where(fn ($q) => $q
                    ->whereNull('external_dimensions->gam_report_scope')
                    ->orWhere('external_dimensions->gam_report_scope', '!=', $fingerprint)))->exists()) {
                throw new RuntimeException('Existing financial facts use another reporting scope. A historical comparison preview and explicit correction approval are required.');
            }
        }
    }

    /** Preserve newer scope/configuration when an asynchronous Google call returns. */
    public function checkpoint(SiteGamReportBinding $binding, array $scope, string $key, ?array $job, ?string $networkCurrency = null): void
    {
        DB::transaction(function () use ($binding, $scope, $key, $job, $networkCurrency): void {
            $connection = ReportSourceConnection::withoutGlobalScopes()->lockForUpdate()->findOrFail($binding->report_source_connection_id);
            $this->assertCurrent($binding, $scope);
            $configuration = (array) ($connection->configuration ?? []);
            if ($job === null) unset($configuration['google_jobs'][$key]);
            else $configuration['google_jobs'][$key] = $job;
            if ($networkCurrency !== null) $configuration['source_network_currency'] = $networkCurrency;
            $connection->update(['configuration' => $configuration]);
        });
    }

    private function valid(array $scope): bool
    {
        foreach (['version', 'metric_basis', 'binding_id', 'network_code', 'ad_unit_id', 'hostname', 'currency', 'timezone', 'effective_from', 'fingerprint'] as $key) {
            if (! is_string($scope[$key] ?? null) || $scope[$key] === '') return false;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/D', $scope['effective_from']) === 1
            && hash_equals($this->fingerprint($scope), $scope['fingerprint']);
    }

    private function fingerprint(array $scope): string
    {
        $keys = [
            'version', 'metric_basis', 'binding_id', 'network_code', 'ad_unit_id', 'hostname', 'currency', 'timezone', 'effective_from',
        ];
        return hash('sha256', json_encode(array_combine($keys, array_map(fn ($key) => $scope[$key] ?? null, $keys)), JSON_THROW_ON_ERROR));
    }
}
