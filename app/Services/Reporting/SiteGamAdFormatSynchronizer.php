<?php

namespace App\Services\Reporting;

use App\Models\ReportSourceConnection;
use App\Models\SiteGamAdFormatReport;
use App\Models\SiteGamReportBinding;
use App\Models\SiteGamVideoReportBinding;
use App\Services\Audit\AuditRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Independent, nonfinancial "Breakdown by ad format" evidence.
 *
 * Uses the same bound GAM ad unit, the same exact registered hostname and the
 * same Ad Exchange metric basis as the website ledger import, with Google's
 * inventory format as an extra dimension. It never writes daily_reports,
 * never touches report scopes or import checkpoints, and a failure here never
 * changes the financial source health.
 */
final class SiteGamAdFormatSynchronizer
{
    public const SCOPE = 'AD_UNIT_EXACT_SITE_BY_INVENTORY_FORMAT_V1';

    public const STATE_KEY = 'ad_format_breakdown';

    /** Google exposes the identifier and the readable name as separate dimensions. */
    public const FORMAT_DIMENSIONS = ['INVENTORY_FORMAT', 'INVENTORY_FORMAT_NAME'];

    public const DIMENSIONS = ['DATE', 'AD_UNIT_ID', 'SITE_NAME', ...self::FORMAT_DIMENSIONS];

    public const HISTORY_DAYS = 400;

    public const REFRESH_DAYS = 7;

    public function __construct(
        private readonly GamAdUnitReportClient $google,
        private readonly GamReportMoneyParser $money,
        private readonly SiteGamReportScope $scopes,
        private readonly AuditRecorder $audit,
    ) {}

    /** At most one bounded Google report per pass; pending work resumes on the existing scheduler. */
    public function sync(SiteGamReportBinding $binding): array
    {
        if ($binding instanceof SiteGamVideoReportBinding) {
            // Video keeps its own independent report section.
            return ['status' => 'NOT_APPLICABLE', 'stored_days' => 0];
        }

        return Cache::lock('site-gam-report:'.$binding->report_source_connection_id, 300)->block(3, function () use ($binding): array {
            $binding->refresh()->load('connection.source', 'gamConnection', 'site');
            $this->assertBinding($binding);
            $connection = $binding->connection;
            if (! $connection->is_enabled || ! $connection->source?->is_enabled || $connection->status->value === 'DISABLED'
                || ! $binding->gamConnection->is_enabled || ! $binding->active_site_id) {
                return ['status' => 'DISABLED', 'stored_days' => 0];
            }
            $currency = strtoupper((string) $connection->currency);
            if ($currency !== strtoupper((string) config('reporting.canonical_currency', 'USD'))) {
                return ['status' => 'DISABLED', 'stored_days' => 0];
            }
            $hostname = $this->scopes->hostname((string) $binding->site->primary_domain);
            $now = CarbonImmutable::now($connection->timezone);
            $last = $binding->ends_on ? $now->min(CarbonImmutable::parse($binding->ends_on->toDateString(), $connection->timezone)) : $now;
            $first = max($binding->starts_on->toDateString(), $now->subDays(self::HISTORY_DAYS)->toDateString());
            if ($first > $last->toDateString()) return ['status' => 'NO_DATES', 'stored_days' => 0];

            $identity = $this->identity($binding, $hostname);
            $state = ($connection->configuration ?? [])[self::STATE_KEY] ?? [];
            if (($state['identity'] ?? null) !== $identity) $state = ['identity' => $identity];
            if (! isset($state['pending']) && ! empty($state['retry_at']) && CarbonImmutable::parse($state['retry_at'])->gt(now())) {
                return ['status' => $state['error'] ?? 'WAITING', 'stored_days' => 0];
            }
            if (isset($state['pending']) && CarbonImmutable::parse($state['pending']['requested_at'])->lt(now()->subHours(6))) {
                unset($state['pending']);
                $this->checkpoint($binding, $identity, $state);
            }
            if (isset($state['pending'])) {
                [$from, $to] = [$state['pending']['from'], $state['pending']['to']];
            } else {
                $cursor = max((string) ($state['history_next'] ?? $first), $first);
                $end = $last->subDay()->toDateString();
                if ($cursor <= $end) {
                    [$from, $to] = [$cursor, min(CarbonImmutable::parse($cursor)->addDays(30)->toDateString(), $end)];
                } elseif (empty($state['next_refresh_at']) || CarbonImmutable::parse($state['next_refresh_at'])->lte(now())) {
                    // Recheck recent Google revisions and today's running totals.
                    [$from, $to] = [max($first, $last->subDays(self::REFRESH_DAYS - 1)->toDateString()), $last->toDateString()];
                } else {
                    return ['status' => 'CURRENT', 'stored_days' => 0];
                }
            }
            if ($from < $binding->starts_on->toDateString() || $to > $last->toDateString() || $from > $to
                || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 30) {
                unset($state['pending']);
                $this->checkpoint($binding, $identity, $state);
                throw new RuntimeException('Invalid ad format report dates.');
            }

            try {
                if (! isset($state['pending'])) {
                    $network = $this->google->call($binding->gamConnection, 'NetworkService', 'getCurrentNetwork');
                    if ((string) ($network['networkCode'] ?? '') !== $binding->network_code || ($network['timeZone'] ?? '') !== $connection->timezone
                        || ! preg_match('/^[A-Z]{3}$/D', (string) ($network['currencyCode'] ?? ''))) {
                        throw new RuntimeException('NETWORK_IDENTITY_CHANGED');
                    }
                    $response = $this->google->runPerformanceReport($binding->gamConnection, $this->query($binding, $from, $to, $currency),
                        array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS));
                    $id = (string) ($response['id'] ?? '');
                    if (! ctype_digit($id) || strlen($id) > 32) throw new RuntimeException('Invalid ad format Google job.');
                    $state['pending'] = [
                        'id' => $id, 'from' => $from, 'to' => $to, 'requested_at' => now()->toIso8601String(),
                        'network_currency' => (string) $network['currencyCode'],
                        'confirmed_report_currency' => $this->money->confirmedCurrency($response, $currency),
                    ];
                    $this->checkpoint($binding, $identity, $state);
                }
                $job = $state['pending'];
                $status = $this->google->call($binding->gamConnection, 'ReportService', 'getReportJobStatus', ['reportJobId' => $job['id']]);
                if (($status['value'] ?? '') !== 'COMPLETED') {
                    if (($status['value'] ?? '') === 'FAILED') {
                        unset($state['pending']);
                        throw new RuntimeException('Google ad format report preparation failed.');
                    }

                    return ['status' => 'PENDING', 'stored_days' => 0];
                }
                $csv = $this->google->download($binding->gamConnection, $job['id']);
                try {
                    $rows = $this->parse($csv, $binding->ad_unit_id, $hostname, $job['from'], $job['to'], $currency,
                        $job['network_currency'] ?? '', $job['confirmed_report_currency'] ?? null);
                } catch (\Throwable $error) {
                    // A malformed completed report must not pin retries to the same job.
                    unset($state['pending']);
                    throw $error;
                }
                $state = DB::transaction(function () use ($binding, $identity, $state, $rows, $job, $last, $hostname): array {
                    $locked = SiteGamReportBinding::withoutGlobalScopes()->lockForUpdate()->findOrFail($binding->id);
                    $locked->load('connection', 'site', 'gamConnection');
                    $this->assertBinding($locked);
                    if ($this->identity($locked, $hostname) !== $identity) throw new RuntimeException('Ad format binding changed during collection.');
                    // Each completed report is a full snapshot for its dates:
                    // replace them so corrections and removed formats never add up.
                    SiteGamAdFormatReport::withoutGlobalScopes()
                        ->where('report_source_connection_id', $locked->report_source_connection_id)
                        ->whereDate('report_date', '>=', $job['from'])->whereDate('report_date', '<=', $job['to'])->delete();
                    foreach ($rows as $row) {
                        SiteGamAdFormatReport::withoutGlobalScopes()->create($row + [
                            'organization_id' => $locked->organization_id,
                            'report_source_connection_id' => $locked->report_source_connection_id,
                            'site_gam_report_binding_id' => $locked->id, 'site_id' => $locked->site_id,
                            'gam_connection_id' => $locked->gam_connection_id, 'network_code' => $locked->network_code,
                            'ad_unit_id' => $locked->ad_unit_id, 'hostname' => $hostname, 'timezone' => $locked->connection->timezone,
                            'google_report_job_id' => $job['id'], 'reported_at' => now(),
                        ]);
                    }
                    $state['history_next'] = max((string) ($state['history_next'] ?? $job['from']), CarbonImmutable::parse($job['to'])->addDay()->toDateString());
                    $state['next_refresh_at'] = ($job['to'] === $last->toDateString() ? now()->addHour() : now())->toIso8601String();
                    $state['synced_at'] = now()->toIso8601String();
                    unset($state['pending'], $state['error'], $state['retry_at']);
                    $this->checkpoint($locked, $identity, $state);
                    $this->audit->record('reporting.site_gam.ad_format_synced', $locked->organization_id, auditable: $locked,
                        metadata: ['scope' => self::SCOPE, 'from' => $job['from'], 'to' => $job['to'], 'stored_rows' => count($rows), 'financial_rows_changed' => false]);

                    return $state;
                });

                return ['status' => 'COMPLETED', 'stored_rows' => count($rows), 'stored_days' => count(array_unique(array_column($rows, 'report_date')))];
            } catch (MainReportSyncLockException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $unsupported = self::unsupported($error);
                if ($unsupported) unset($state['pending']);
                $state['error'] = $unsupported ? 'FORMAT_DIMENSION_UNSUPPORTED' : 'SOURCE_UNAVAILABLE';
                // Back off so an unsupported network is not asked again every five minutes.
                $state['retry_at'] = now()->addMinutes($unsupported ? 24 * 60 : max(5, (int) config('reporting.retry_delay_minutes', 30)))->toIso8601String();
                $this->checkpoint($binding, $identity, $state);

                return ['status' => $state['error'], 'stored_days' => 0];
            }
        });
    }

    /**
     * Read-only operator view of a website's format synchronization. Never calls Google.
     *
     * @return array{state: string, label: string, synced_through: ?string, synced_at: ?string, retry_at: ?string}
     */
    public function status(\App\Models\Site $site): array
    {
        $site->loadMissing('currentGamReportBinding.connection');
        $binding = $site->currentGamReportBinding;
        $connection = $binding?->connection;
        if (! $binding || ! $connection || $connection->organization_id !== $site->organization_id) {
            return ['state' => 'NOT_CONNECTED', 'label' => 'No GAM reporting ad unit connected', 'synced_through' => null, 'synced_at' => null, 'retry_at' => null];
        }
        $state = ($connection->configuration ?? [])[self::STATE_KEY] ?? [];
        $through = isset($state['history_next']) ? CarbonImmutable::parse($state['history_next'])->subDay()->toDateString() : null;
        $retry = isset($state['retry_at']) && CarbonImmutable::parse($state['retry_at'])->isFuture() ? $state['retry_at'] : null;
        [$code, $label] = match (true) {
            ! $connection->is_enabled || $connection->status->value === 'DISABLED' => ['DISABLED', 'Reporting source disabled'],
            ($state['error'] ?? null) === 'FORMAT_DIMENSION_UNSUPPORTED' => ['UNSUPPORTED', 'Google did not accept the ad format dimension for this network; retrying automatically'],
            ($state['error'] ?? null) !== null => ['RETRYING', 'Google was temporarily unavailable; retrying automatically'],
            isset($state['pending']) => ['PREPARING', 'Google is preparing '.$state['pending']['from'].' – '.$state['pending']['to']],
            $through === null => ['WAITING', 'Waiting for the first synchronization (within five minutes)'],
            $through < CarbonImmutable::now($connection->timezone)->subDay()->toDateString() => ['BACKFILLING', 'Collecting history'],
            default => ['CURRENT', 'Up to date'],
        };

        return ['state' => $code, 'label' => $label, 'synced_through' => $through, 'synced_at' => $state['synced_at'] ?? null, 'retry_at' => $retry];
    }

    public function query(SiteGamReportBinding $binding, string $from, string $to, string $currency = 'USD'): array
    {
        $date = static fn (string $day): array => ['year' => (int) substr($day, 0, 4), 'month' => (int) substr($day, 5, 2), 'day' => (int) substr($day, 8, 2)];

        return [
            'dimensions' => self::DIMENSIONS, 'columns' => array_keys(SiteGamReportMetrics::COLUMNS),
            'adUnitView' => 'FLAT', 'dateRangeType' => 'CUSTOM_DATE', 'startDate' => $date($from), 'endDate' => $date($to),
            'reportCurrency' => $currency, 'timeZoneType' => 'PUBLISHER',
            'statement' => ['query' => 'WHERE AD_UNIT_ID = :unit', 'values' => [
                ['key' => 'unit', 'value' => ['__type' => 'NumberValue', 'value' => $binding->ad_unit_id]],
            ]],
        ];
    }

    /**
     * Rows for the exact registered hostname only, one per day and format.
     * Missing optional Active View columns stay null and are never invented.
     */
    public function parse(string $csv, string $unit, string $hostname, string $from, string $to, string $currency = 'USD',
        string $networkCurrency = '', ?string $confirmedCurrency = null): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        try {
            $headers = fgetcsv($stream, escape: '');
            if (! is_array($headers) || $headers === [null]) throw new RuntimeException('Missing ad format CSV header.');
            $headers[0] = ltrim((string) $headers[0], "\xEF\xBB\xBF");
            $required = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME',
                ...array_map(fn ($column) => 'Column.'.$column, array_keys(SiteGamReportMetrics::CORE_COLUMNS))];
            $formatHeaders = array_values(array_intersect(array_map(fn ($d) => 'Dimension.'.$d, self::FORMAT_DIMENSIONS), $headers));
            $allowed = [...$required, ...array_map(fn ($d) => 'Dimension.'.$d, self::FORMAT_DIMENSIONS), 'Dimension.AD_UNIT_NAME',
                ...array_map(fn ($column) => 'Column.'.$column, array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS))];
            if (array_diff($required, $headers) || $formatHeaders === [] || array_diff($headers, $allowed)
                || count(array_unique($headers)) !== count($headers)) {
                throw new RuntimeException('Unexpected ad format CSV definition.');
            }
            $rows = [];
            $seen = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if ($values === [null]) continue;
                if (count($values) !== count($headers)) throw new RuntimeException('Incomplete ad format CSV row.');
                $row = array_combine($headers, $values);
                $day = $row['Dimension.DATE'];
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || ! checkdate((int) substr($day, 5, 2), (int) substr($day, 8, 2), (int) substr($day, 0, 4))
                    || $day < $from || $day > $to || $row['Dimension.AD_UNIT_ID'] !== $unit) {
                    throw new RuntimeException('The ad format report contains data outside the selected ad unit or dates.');
                }
                $googleId = isset($row['Dimension.INVENTORY_FORMAT']) ? trim($row['Dimension.INVENTORY_FORMAT']) : null;
                $googleName = isset($row['Dimension.INVENTORY_FORMAT_NAME']) ? trim($row['Dimension.INVENTORY_FORMAT_NAME']) : null;
                $site = strtolower(preg_replace('/\.$/D', '', trim($row['Dimension.SITE_NAME'])));
                $identity = $day.'|'.$site.'|'.$googleId.'|'.$googleName;
                if (isset($seen[$identity])) throw new RuntimeException('The ad format report contains duplicate rows.');
                $seen[$identity] = true;
                // Same attribution as the ledger import: the exact registered hostname only.
                if ($site !== $hostname) continue;

                $format = AdFormatCatalog::normalize($googleName, $googleId);
                $metrics = [];
                foreach (SiteGamReportMetrics::COLUMNS as $column => $field) {
                    if (! array_key_exists('Column.'.$column, $row)) {
                        $metrics[$field] = null;
                        continue;
                    }
                    $metrics[$field] = $field === 'revenue_micros'
                        ? $this->money->parse($row['Column.'.$column], $currency, $networkCurrency, $confirmedCurrency)
                        : $this->integer($row['Column.'.$column]);
                }
                $key = $day.'|'.$format['key'];
                if (! isset($rows[$key])) {
                    $rows[$key] = ['report_date' => $day, 'ad_format' => $format['key'],
                        'google_format_id' => $googleId === '' ? null : substr((string) $googleId, 0, 32),
                        'google_format_name' => $format['name'], ...$metrics];
                    continue;
                }
                // Two Google identifiers with the same readable format are one row.
                foreach ($metrics as $field => $value) {
                    $rows[$key][$field] = $rows[$key][$field] === null || $value === null ? null : $rows[$key][$field] + $value;
                }
            }
            foreach ($rows as &$row) {
                $viewable = $row['active_view_viewable_impressions'];
                $measurable = $row['active_view_measurable_impressions'];
                if (($viewable === null) !== ($measurable === null) || ($viewable !== null && $viewable > $measurable)) {
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

    public static function unsupported(\Throwable $error): bool
    {
        return preg_match('/COLUMNS_NOT_SUPPORTED|NOT_SUPPORTED_FOR_REQUESTED|INVALID_DIMENSION|INVENTORY_FORMAT|Unmarshalling|not a valid enum|is not a valid value/i', $error->getMessage()) === 1;
    }

    private function integer(string $value): int
    {
        if (! preg_match('/^\d+$/D', $value) || strlen(ltrim($value, '0')) > 15) {
            throw new RuntimeException('The ad format report contains an invalid delivery metric.');
        }

        return (int) $value;
    }

    private function assertBinding(SiteGamReportBinding $binding): void
    {
        if (! $binding->connection || ! $binding->site || ! $binding->gamConnection
            || $binding->connection->connection_type !== $binding->connectionType() || $binding->connection->connection_id !== $binding->id
            || $binding->organization_id !== $binding->connection->organization_id || $binding->site->organization_id !== $binding->organization_id
            || (string) $binding->gamConnection->network_code !== $binding->network_code || ! ctype_digit((string) $binding->ad_unit_id)) {
            throw new RuntimeException('Invalid ad format report binding.');
        }
    }

    private function identity(SiteGamReportBinding $binding, string $hostname): string
    {
        return hash('sha256', implode('|', [self::SCOPE, SiteGamReportMetrics::BASIS, $binding->id, $binding->organization_id,
            $binding->site_id, $binding->gam_connection_id, $binding->network_code, $binding->ad_unit_id, $hostname,
            $binding->connection->timezone, $binding->connection->currency, $binding->starts_on->toDateString(), $binding->ends_on?->toDateString()]));
    }

    private function checkpoint(SiteGamReportBinding $binding, string $identity, array $state): void
    {
        DB::transaction(function () use ($binding, $identity, $state): void {
            $connection = ReportSourceConnection::withoutGlobalScopes()->lockForUpdate()->findOrFail($binding->report_source_connection_id);
            $binding->load('connection', 'site', 'gamConnection');
            $this->assertBinding($binding);
            if ($this->identity($binding, $this->scopes->hostname((string) $binding->site->primary_domain)) !== $identity) {
                throw new RuntimeException('Ad format binding changed.');
            }
            $configuration = $connection->configuration ?? [];
            $configuration[self::STATE_KEY] = $state;
            $connection->update(['configuration' => $configuration]);
        });
    }
}
