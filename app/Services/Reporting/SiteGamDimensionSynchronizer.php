<?php

namespace App\Services\Reporting;

use App\Models\ReportSourceConnection;
use App\Models\SiteGamDimensionReport;
use App\Models\SiteGamReportBinding;
use App\Models\SiteGamVideoReportBinding;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Independent, nonfinancial country / device breakdown evidence. Same bound
 * ad unit, exact registered hostname and Ad Exchange basis as the ledger
 * import, with one extra Google dimension. Never writes daily_reports.
 */
final class SiteGamDimensionSynchronizer
{
    public const SCOPE = 'AD_UNIT_EXACT_SITE_BY_DIMENSION_V1';

    public const KINDS = [
        SiteGamDimensionReport::COUNTRY => ['dimensions' => ['COUNTRY_CODE', 'COUNTRY_NAME'], 'key' => 'COUNTRY_CODE', 'name' => 'COUNTRY_NAME'],
        SiteGamDimensionReport::DEVICE => ['dimensions' => ['DEVICE_CATEGORY_ID', 'DEVICE_CATEGORY_NAME'], 'key' => 'DEVICE_CATEGORY_NAME', 'name' => 'DEVICE_CATEGORY_NAME'],
    ];

    public const HISTORY_DAYS = 90;

    public const REFRESH_DAYS = 7;

    public const REFRESH_MINUTES = 180;

    public function __construct(
        private readonly GamAdUnitReportClient $google,
        private readonly GamReportMoneyParser $money,
        private readonly SiteGamReportScope $scopes,
        private readonly AuditRecorder $audit,
    ) {}

    public static function stateKey(string $kind): string
    {
        return 'dimension_breakdown_'.strtolower($kind);
    }

    /** At most one bounded Google report per kind per pass; newest days first, then history backwards. */
    public function sync(SiteGamReportBinding $binding, string $kind): array
    {
        if (! isset(self::KINDS[$kind])) throw new \InvalidArgumentException('Unknown breakdown kind.');
        if ($binding instanceof SiteGamVideoReportBinding) return ['status' => 'NOT_APPLICABLE', 'stored_days' => 0];

        return Cache::lock('site-gam-report:'.$binding->report_source_connection_id, 300)->block(3, function () use ($binding, $kind): array {
            $binding->refresh()->load(self::relations());
            $this->assertBinding($binding);
            $connection = $binding->connection;
            if (! $connection->is_enabled || ! $connection->source?->is_enabled || $connection->status->value === 'DISABLED'
                || ! $binding->gamConnection->is_enabled || ! $binding->active_site_id) {
                return ['status' => 'DISABLED', 'stored_days' => 0];
            }
            $currency = strtoupper((string) $connection->currency);
            if ($currency !== strtoupper((string) config('reporting.canonical_currency', 'USD'))) return ['status' => 'DISABLED', 'stored_days' => 0];
            $hostname = $this->scopes->hostname((string) $binding->site->primary_domain);
            $now = CarbonImmutable::now($connection->timezone);
            $last = $binding->ends_on ? $now->min(CarbonImmutable::parse($binding->ends_on->toDateString(), $connection->timezone)) : $now;
            $first = max($binding->starts_on->toDateString(), $now->subDays(self::HISTORY_DAYS)->toDateString());
            if ($first > $last->toDateString()) return ['status' => 'NO_DATES', 'stored_days' => 0];

            $identity = $this->identity($binding, $hostname, $kind);
            $stateKey = self::stateKey($kind);
            $state = ($connection->configuration ?? [])[$stateKey] ?? [];
            if (($state['identity'] ?? null) !== $identity) $state = ['identity' => $identity];
            if (! isset($state['pending']) && ! empty($state['retry_at']) && CarbonImmutable::parse($state['retry_at'])->gt(now())) {
                return ['status' => $state['error'] ?? 'WAITING', 'stored_days' => 0];
            }
            if (isset($state['pending']) && CarbonImmutable::parse($state['pending']['requested_at'])->lt(now()->subHours(6))) {
                unset($state['pending']);
                $this->checkpoint($binding, $identity, $stateKey, $state);
            }
            if (isset($state['pending'])) {
                [$from, $to, $mode] = [$state['pending']['from'], $state['pending']['to'], $state['pending']['kind'] ?? 'history'];
            } else {
                $recentFrom = max($first, $last->subDays(self::REFRESH_DAYS - 1)->toDateString());
                $historyBefore = min((string) ($state['history_before'] ?? $recentFrom), $recentFrom);
                if (empty($state['next_refresh_at']) || CarbonImmutable::parse($state['next_refresh_at'])->lte(now())) {
                    [$from, $to, $mode] = [$recentFrom, $last->toDateString(), 'recent'];
                } elseif ($historyBefore > $first) {
                    $to = CarbonImmutable::parse($historyBefore)->subDay()->toDateString();
                    [$from, $mode] = [max($first, CarbonImmutable::parse($to)->subDays(30)->toDateString()), 'history'];
                } else {
                    return ['status' => 'CURRENT', 'stored_days' => 0];
                }
            }
            if ($from < $binding->starts_on->toDateString() || $to > $last->toDateString() || $from > $to
                || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 30) {
                unset($state['pending']);
                $this->checkpoint($binding, $identity, $stateKey, $state);
                throw new RuntimeException('Invalid breakdown report dates.');
            }

            try {
                if (! isset($state['pending'])) {
                    $network = $this->google->call($binding->gamConnection, 'NetworkService', 'getCurrentNetwork');
                    if ((string) ($network['networkCode'] ?? '') !== $binding->network_code || ($network['timeZone'] ?? '') !== $connection->timezone
                        || ! preg_match('/^[A-Z]{3}$/D', (string) ($network['currencyCode'] ?? ''))) {
                        throw new RuntimeException('NETWORK_IDENTITY_CHANGED');
                    }
                    $response = $this->google->runPerformanceReport($binding->gamConnection, $this->query($binding, $kind, $from, $to, $currency),
                        array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS));
                    $id = (string) ($response['id'] ?? '');
                    if (! ctype_digit($id) || strlen($id) > 32) throw new RuntimeException('Invalid breakdown Google job.');
                    $state['pending'] = ['id' => $id, 'from' => $from, 'to' => $to, 'kind' => $mode, 'requested_at' => now()->toIso8601String(),
                        'network_currency' => (string) $network['currencyCode'],
                        'confirmed_report_currency' => $this->money->confirmedCurrency($response, $currency)];
                    $this->checkpoint($binding, $identity, $stateKey, $state);
                }
                $job = $state['pending'];
                $status = $this->google->call($binding->gamConnection, 'ReportService', 'getReportJobStatus', ['reportJobId' => $job['id']]);
                if (($status['value'] ?? '') !== 'COMPLETED') {
                    if (($status['value'] ?? '') === 'FAILED') {
                        unset($state['pending']);
                        throw new RuntimeException('Google breakdown report preparation failed.');
                    }

                    return ['status' => 'PENDING', 'stored_days' => 0];
                }
                $csv = $this->google->download($binding->gamConnection, $job['id']);
                try {
                    $rows = $this->parse($csv, $kind, $binding->ad_unit_id, $hostname, $job['from'], $job['to'], $currency,
                        $job['network_currency'] ?? '', $job['confirmed_report_currency'] ?? null);
                } catch (\Throwable $error) {
                    unset($state['pending']);
                    throw $error;
                }
                $state = DB::transaction(function () use ($binding, $identity, $stateKey, $state, $rows, $job, $hostname, $kind): array {
                    $locked = SiteGamReportBinding::withoutGlobalScopes()->lockForUpdate()->findOrFail($binding->id);
                    $locked->load(self::relations());
                    $this->assertBinding($locked);
                    if ($this->identity($locked, $hostname, $kind) !== $identity) throw new RuntimeException('Breakdown binding changed during collection.');
                    SiteGamDimensionReport::withoutGlobalScopes()->where('report_source_connection_id', $locked->report_source_connection_id)
                        ->where('kind', $kind)->whereDate('report_date', '>=', $job['from'])->whereDate('report_date', '<=', $job['to'])->delete();
                    foreach ($rows as $row) {
                        SiteGamDimensionReport::withoutGlobalScopes()->create($row + [
                            'organization_id' => $locked->organization_id, 'report_source_connection_id' => $locked->report_source_connection_id,
                            'site_gam_report_binding_id' => $locked->id, 'site_id' => $locked->site_id, 'kind' => $kind,
                            'google_report_job_id' => $job['id'], 'reported_at' => now(),
                        ]);
                    }
                    $state['history_before'] = min((string) ($state['history_before'] ?? $job['from']), $job['from']);
                    if (($job['kind'] ?? 'history') === 'recent') {
                        $state['recent_through'] = $job['to'];
                        $state['next_refresh_at'] = now()->addMinutes(self::REFRESH_MINUTES)->toIso8601String();
                    }
                    $state['synced_at'] = now()->toIso8601String();
                    unset($state['pending'], $state['error'], $state['retry_at']);
                    $this->checkpoint($locked, $identity, $stateKey, $state);
                    $this->audit->record('reporting.site_gam.dimension_synced', $locked->organization_id, auditable: $locked,
                        metadata: ['scope' => self::SCOPE, 'kind' => $kind, 'from' => $job['from'], 'to' => $job['to'], 'stored_rows' => count($rows), 'financial_rows_changed' => false]);

                    return $state;
                });

                return ['status' => 'COMPLETED', 'stored_rows' => count($rows)];
            } catch (MainReportSyncLockException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $unsupported = SiteGamAdFormatSynchronizer::unsupported($error) || str_contains($error->getMessage(), 'COUNTRY') || str_contains($error->getMessage(), 'DEVICE_CATEGORY');
                if ($unsupported) unset($state['pending']);
                $state['error'] = $unsupported ? 'DIMENSION_UNSUPPORTED' : 'SOURCE_UNAVAILABLE';
                $state['retry_at'] = now()->addMinutes($unsupported ? 24 * 60 : max(5, (int) config('reporting.retry_delay_minutes', 30)))->toIso8601String();
                $this->checkpoint($binding, $identity, $stateKey, $state);

                return ['status' => $state['error'], 'stored_days' => 0];
            }
        });
    }

    public function query(SiteGamReportBinding $binding, string $kind, string $from, string $to, string $currency = 'USD'): array
    {
        $date = static fn (string $day): array => ['year' => (int) substr($day, 0, 4), 'month' => (int) substr($day, 5, 2), 'day' => (int) substr($day, 8, 2)];

        return [
            'dimensions' => ['DATE', 'AD_UNIT_ID', 'SITE_NAME', ...self::KINDS[$kind]['dimensions']], 'columns' => array_keys(SiteGamReportMetrics::COLUMNS),
            'adUnitView' => 'FLAT', 'dateRangeType' => 'CUSTOM_DATE', 'startDate' => $date($from), 'endDate' => $date($to),
            'reportCurrency' => $currency, 'timeZoneType' => 'PUBLISHER',
            'statement' => ['query' => 'WHERE AD_UNIT_ID = :unit', 'values' => [
                ['key' => 'unit', 'value' => ['__type' => 'NumberValue', 'value' => $binding->ad_unit_id]],
            ]],
        ];
    }

    /** Exact registered hostname only, one row per day and dimension value. */
    public function parse(string $csv, string $kind, string $unit, string $hostname, string $from, string $to, string $currency = 'USD',
        string $networkCurrency = '', ?string $confirmedCurrency = null): array
    {
        $spec = self::KINDS[$kind];
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        try {
            $headers = fgetcsv($stream, escape: '');
            if (! is_array($headers) || $headers === [null]) throw new RuntimeException('Missing breakdown CSV header.');
            $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
            $required = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME', 'Dimension.'.$spec['key'],
                ...array_map(fn ($column) => 'Column.'.$column, array_keys(SiteGamReportMetrics::CORE_COLUMNS))];
            $allowed = [...$required, ...array_map(fn ($d) => 'Dimension.'.$d, $spec['dimensions']), 'Dimension.AD_UNIT_NAME',
                ...array_map(fn ($column) => 'Column.'.$column, array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS))];
            if (array_diff($required, $headers) || array_diff($headers, $allowed) || count(array_unique($headers)) !== count($headers)) {
                throw new RuntimeException('Unexpected breakdown CSV definition.');
            }
            $rows = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if ($values === [null]) continue;
                if (count($values) !== count($headers)) throw new RuntimeException('Incomplete breakdown CSV row.');
                $row = array_combine($headers, $values);
                $day = $row['Dimension.DATE'];
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || $day < $from || $day > $to || $row['Dimension.AD_UNIT_ID'] !== $unit) {
                    throw new RuntimeException('The breakdown report contains data outside the selected ad unit or dates.');
                }
                if (strtolower(preg_replace('/\.$/D', '', trim($row['Dimension.SITE_NAME']))) !== $hostname) continue;
                $rawKey = trim((string) $row['Dimension.'.$spec['key']]);
                $key = strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]+/', '_', $rawKey), 0, 64)) ?: 'UNKNOWN';
                if (in_array($key, ['_', 'N_A', 'NA'], true)) $key = 'UNKNOWN';
                $name = isset($row['Dimension.'.$spec['name']]) ? mb_substr(trim($row['Dimension.'.$spec['name']]), 0, 128) : null;
                $metrics = [];
                foreach (SiteGamReportMetrics::COLUMNS as $column => $field) {
                    if (! array_key_exists('Column.'.$column, $row)) { $metrics[$field] = null; continue; }
                    $metrics[$field] = $field === 'revenue_micros'
                        ? $this->money->parse($row['Column.'.$column], $currency, $networkCurrency, $confirmedCurrency)
                        : $this->integer($row['Column.'.$column]);
                }
                $index = $day.'|'.$key;
                if (! isset($rows[$index])) {
                    $rows[$index] = ['report_date' => $day, 'dimension_key' => $key, 'dimension_name' => $name ?: null, ...$metrics];
                    continue;
                }
                foreach ($metrics as $field => $value) {
                    $rows[$index][$field] = $rows[$index][$field] === null || $value === null ? null : $rows[$index][$field] + $value;
                }
            }
            foreach ($rows as &$row) {
                if (($row['active_view_viewable_impressions'] === null) !== ($row['active_view_measurable_impressions'] === null)
                    || ($row['active_view_viewable_impressions'] !== null && $row['active_view_viewable_impressions'] > $row['active_view_measurable_impressions'])) {
                    $row['active_view_viewable_impressions'] = $row['active_view_measurable_impressions'] = null;
                }
                foreach (['impressions', 'clicks'] as $field) $row[$field] ??= 0;
            }
            unset($row);
            ksort($rows);

            return array_values($rows);
        } finally {
            fclose($stream);
        }
    }

    private static function relations(): array
    {
        $unscoped = fn ($query) => $query->withoutGlobalScopes();

        return ['connection' => $unscoped, 'connection.source' => $unscoped, 'site' => $unscoped, 'gamConnection' => $unscoped];
    }

    private function integer(string $value): int
    {
        if (! preg_match('/^\d+$/D', $value) || strlen(ltrim($value, '0')) > 15) throw new RuntimeException('Invalid breakdown delivery metric.');

        return (int) $value;
    }

    private function assertBinding(SiteGamReportBinding $binding): void
    {
        if (! $binding->connection || ! $binding->site || ! $binding->gamConnection
            || $binding->connection->connection_type !== $binding->connectionType() || $binding->connection->connection_id !== $binding->id
            || $binding->organization_id !== $binding->connection->organization_id || $binding->site->organization_id !== $binding->organization_id
            || (string) $binding->gamConnection->network_code !== $binding->network_code || ! ctype_digit((string) $binding->ad_unit_id)) {
            throw new RuntimeException('Invalid breakdown binding.');
        }
    }

    private function identity(SiteGamReportBinding $binding, string $hostname, string $kind): string
    {
        return hash('sha256', implode('|', [self::SCOPE, $kind, SiteGamReportMetrics::BASIS, $binding->id, $binding->organization_id,
            $binding->site_id, $binding->gam_connection_id, $binding->network_code, $binding->ad_unit_id, $hostname,
            $binding->connection->timezone, $binding->connection->currency, $binding->starts_on->toDateString(), $binding->ends_on?->toDateString()]));
    }

    private function checkpoint(SiteGamReportBinding $binding, string $identity, string $stateKey, array $state): void
    {
        DB::transaction(function () use ($binding, $identity, $stateKey, $state): void {
            $connection = ReportSourceConnection::withoutGlobalScopes()->lockForUpdate()->findOrFail($binding->report_source_connection_id);
            $binding->load(self::relations());
            $this->assertBinding($binding);
            $kind = strtoupper(substr($stateKey, strlen('dimension_breakdown_')));
            if ($this->identity($binding, $this->scopes->hostname((string) $binding->site->primary_domain), $kind) !== $identity) {
                throw new RuntimeException('Breakdown binding changed.');
            }
            $configuration = $connection->configuration ?? [];
            $configuration[$stateKey] = $state;
            $connection->update(['configuration' => $configuration]);
        });
    }
}
