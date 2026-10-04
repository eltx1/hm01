<?php

namespace App\Services\Reporting\Connectors;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Models\ReportSourceConnection;
use App\Models\SiteGamReportBinding;
use App\Services\Reporting\Contracts\ReportSourceConnectorInterface;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\GamReportMoneyParser;
use App\Services\Reporting\GamReportPending;
use App\Services\Reporting\SiteGamReportScope;
use App\Services\Reporting\SiteGamReportMetrics;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

final class GamAdUnitReportConnector implements ReportSourceConnectorInterface
{
    public const COLUMNS = SiteGamReportMetrics::COLUMNS;

    public function __construct(
        private readonly GamAdUnitReportClient $google,
        private readonly GamReportMoneyParser $money,
        private readonly SiteGamReportScope $scopes,
    ) {}

    public function fetch(ReportSourceConnection $connection, CarbonInterface $from, CarbonInterface $to,
        ReportGranularity $granularity, ReportFinality $finality, array $options = []): array
    {
        if ($granularity !== ReportGranularity::Daily) {
            throw new RuntimeException('GAM ad-unit reports use daily totals. Refresh daily estimates for intraday updates.');
        }
        $model = SiteGamReportBinding::modelForConnection($connection);
        $binding = $model::withoutGlobalScopes()->with(['gamConnection', 'site'])
            ->where('report_source_connection_id', $connection->id)->findOrFail($connection->connection_id);
        if (! $connection->is_enabled || ! $binding->gamConnection?->is_enabled || ! $binding->site
            || $binding->organization_id !== $connection->organization_id
            || $binding->site->organization_id !== $binding->organization_id
            || $from->toDateString() < $binding->starts_on->toDateString()
            || ($binding->ends_on && $to->toDateString() > $binding->ends_on->toDateString())
            || $to->lt($from) || $from->diffInDays($to) > 32
            || $to->toDateString() > CarbonImmutable::now($connection->timezone)->toDateString()
            || ($finality === ReportFinality::Finalized && ($granularity !== ReportGranularity::Daily
                || $to->toDateString() >= CarbonImmutable::now($connection->timezone)->toDateString()))
            || (string) $binding->gamConnection->network_code !== $binding->network_code) {
            throw new RuntimeException('The report dates or connection do not match this website reporting binding.');
        }
        $reportCurrency = $this->canonicalCurrency();
        if (strtoupper((string) $connection->currency) !== $reportCurrency) {
            throw new RuntimeException('Site GAM reporting must use the canonical Horus report currency. Reconnect or normalize this reporting source.');
        }
        $scope = $this->scopes->ensure($binding);
        if ($from->toDateString() < $scope['effective_from']) {
            throw new RuntimeException('These dates precede the exact-site reporting cutover. Existing balances are preserved; compare and approve historical corrections separately.');
        }
        $connection->refresh();
        $key = hash('sha256', $scope['fingerprint'].'|'.$granularity->value.'|'.$from->toDateString().'|'.$to->toDateString().'|'.$reportCurrency.'|'.implode(',', array_keys(self::COLUMNS)));
        $configuration = $connection->configuration ?? [];
        $jobId = data_get($configuration, 'google_jobs.'.$key.'.id');
        if (! $jobId) {
            $network = $this->google->call($binding->gamConnection, 'NetworkService', 'getCurrentNetwork');
            if ((string) ($network['networkCode'] ?? '') !== $binding->network_code
                || ! preg_match('/^[A-Z]{3}$/D', (string) ($network['currencyCode'] ?? ''))
                || ($network['timeZone'] ?? '') !== $connection->timezone) {
                throw new RuntimeException('The Google network identity or timezone changed. Reconnect the ad unit from the website Reports section.');
            }
            $date = fn (CarbonInterface $day): array => ['year' => $day->year, 'month' => $day->month, 'day' => $day->day];
            $query = [
                'dimensions' => ['DATE', 'AD_UNIT_ID', 'SITE_NAME'],
                'columns' => array_keys(self::COLUMNS), 'adUnitView' => 'FLAT', 'dateRangeType' => 'CUSTOM_DATE',
                'startDate' => $date($from), 'endDate' => $date($to), 'reportCurrency' => $reportCurrency, 'timeZoneType' => 'PUBLISHER',
                'statement' => ['query' => 'WHERE AD_UNIT_ID = :unit', 'values' => [
                    ['key' => 'unit', 'value' => ['__type' => 'NumberValue', 'value' => $binding->ad_unit_id]],
                ]],
            ];
            $response = $this->google->runPerformanceReport($binding->gamConnection, $query, array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS));
            $jobId = (string) ($response['id'] ?? '');
            if (! ctype_digit($jobId)) {
                throw new RuntimeException('Google did not return a valid report job ID.');
            }
            $configuration['source_network_currency'] = (string) $network['currencyCode'];
            $configuration['google_jobs'][$key] = [
                'id' => $jobId, 'requested_at' => now()->toIso8601String(),
                'scope_fingerprint' => $scope['fingerprint'],
                'confirmed_report_currency' => $this->money->confirmedCurrency($response, $reportCurrency),
            ];
            $this->scopes->checkpoint($binding, $scope, $key, $configuration['google_jobs'][$key], (string) $network['currencyCode']);
            $connection->refresh();
        }
        if (data_get($configuration, 'google_jobs.'.$key.'.scope_fingerprint') !== $scope['fingerprint']) {
            throw new RuntimeException('The pending Google report does not prove the current website scope.');
        }
        $status = $this->google->call($binding->gamConnection, 'ReportService', 'getReportJobStatus', ['reportJobId' => $jobId]);
        if (($status['value'] ?? '') === 'FAILED') {
            unset($configuration['google_jobs'][$key]);
            $this->scopes->checkpoint($binding, $scope, $key, null);
            throw new RuntimeException('Google could not complete the ad-unit report. The request will be retried.');
        }
        if (($status['value'] ?? '') !== 'COMPLETED') {
            if (CarbonImmutable::parse($configuration['google_jobs'][$key]['requested_at'])->lt(now()->subHours(6))) {
                unset($configuration['google_jobs'][$key]);
                $this->scopes->checkpoint($binding, $scope, $key, null);
                throw new RuntimeException('Google report preparation expired. A new request will be scheduled automatically.');
            }
            throw new GamReportPending('Google is preparing the ad-unit report; synchronization will resume automatically.');
        }
        try {
            $rows = $this->parse(
                $this->google->download($binding->gamConnection, (string) $jobId),
                $binding,
                $connection,
                $from,
                $to,
                $granularity,
                data_get($configuration, 'google_jobs.'.$key.'.confirmed_report_currency'),
                $scope,
            );
        } catch (\Throwable $exception) {
            // A completed Google job can still yield an invalid, stale, or
            // unexpected CSV. Do not pin retries to that same completed job.
            unset($configuration['google_jobs'][$key]);
            $this->scopes->checkpoint($binding, $scope, $key, null);
            throw $exception;
        }
        $this->scopes->assertCurrent($binding, $scope);

        return [
            'rows' => $rows, 'external_report_id' => 'gam-unit:'.$jobId.':'.hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
            'totals' => collect(array_values(self::COLUMNS))->reject(fn ($column) => $column === 'revenue_micros')
                ->push('gross_revenue_minor')
                ->filter(fn ($column) => collect($rows)->every(fn ($row) => array_key_exists($column, $row) && $row[$column] !== null))
                ->mapWithKeys(fn ($column) => [$column => array_sum(array_column($rows, $column))])->all(),
            'pending_key' => $key,
        ];
    }

    private function parse(string $csv, SiteGamReportBinding $binding, ReportSourceConnection $connection,
        CarbonInterface $from, CarbonInterface $to, ReportGranularity $granularity, ?string $confirmedReportCurrency, array $scope): array
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        try {
            $headers = fgetcsv($stream, escape: '');
            if (! is_array($headers)) {
                throw new RuntimeException('The Google report has no CSV header.');
            }
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            $required = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME', ...array_map(fn ($key) => 'Column.'.$key, array_keys(SiteGamReportMetrics::CORE_COLUMNS))];
            if ($granularity === ReportGranularity::Hourly) {
                $required[] = 'Dimension.HOUR';
            }
            if (array_diff($required, $headers) || count(array_unique($headers)) !== count($headers)) {
                throw new RuntimeException('The Google report is missing required dimensions or metrics.');
            }
            $buckets = [];
            $seen = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new RuntimeException('The Google report contains an incomplete CSV row.');
                }
                $row = array_combine($headers, $values);
                $day = $row['Dimension.DATE'];
                $hour = $granularity === ReportGranularity::Hourly ? $this->integer($row['Dimension.HOUR']) : 0;
                if ($row['Dimension.AD_UNIT_ID'] !== $binding->ad_unit_id || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)
                    || $day < $from->toDateString() || $day > $to->toDateString() || $hour < 0 || $hour > 23) {
                    throw new RuntimeException('The Google report contains data outside the selected ad unit or dates.');
                }
                $key = $day.':'.$hour;
                $siteName = strtolower(preg_replace('/\.$/D', '', trim($row['Dimension.SITE_NAME'])));
                $identity = $key.'|'.$siteName;
                if (isset($seen[$identity])) {
                    throw new RuntimeException('The Google report contains duplicate date/hour/site rows.');
                }
                $seen[$identity] = true;
                $metrics = [];
                foreach (self::COLUMNS as $column => $field) {
                    if (! array_key_exists('Column.'.$column, $row)) {
                        $metrics[$field] = null;
                        continue;
                    }
                    $rawValue = $row['Column.'.$column];
                    $sourceCurrency = strtoupper((string) data_get($connection->configuration, 'source_network_currency', ''));
                    $value = $field === 'revenue_micros'
                        ? $this->money->parse(
                            $rawValue,
                            $this->canonicalCurrency(),
                            $sourceCurrency,
                            $confirmedReportCurrency,
                        )
                        : $this->integer($rawValue);
                    if ($field !== 'revenue_micros' && $value < 0) {
                        throw new RuntimeException('The Google report contains a negative delivery metric.');
                    }
                    $metrics[$field] = $value;
                }
                // Use Google Ad Exchange metrics only for this exact
                // registered hostname. Do not include siblings, www, parents,
                // unknown/not-applicable sites or other units. No PQL Site
                // filterability is assumed; SITE_NAME must exist in the CSV.
                if ($siteName === $scope['hostname']) $buckets[$key] = $metrics;
            }
            $rows = [];
            $now = CarbonImmutable::now($connection->timezone);
            for ($day = CarbonImmutable::parse($from->toDateString(), $connection->timezone); $day->toDateString() <= $to->toDateString(); $day = $day->addDay()) {
                $lastHour = $granularity === ReportGranularity::Hourly ? ($day->isSameDay($now) ? $now->hour : 23) : 0;
                for ($hour = 0; $hour <= $lastHour; $hour++) {
                    $metrics = $buckets[$day->toDateString().':'.$hour] ?? collect(self::COLUMNS)->mapWithKeys(fn ($field, $column) => [$field => in_array('Column.'.$column, $headers, true) ? 0 : null])->all();
                    $micros = $metrics['revenue_micros'];
                    unset($metrics['revenue_micros']);
                    $rows[] = $metrics + [
                        'date' => $day->toDateString(), 'hour' => $hour, 'currency' => $this->canonicalCurrency(),
                        'organization_id' => $binding->organization_id, 'site_id' => $binding->site_id,
                        'publisher_id' => $binding->site->publisher_id, 'gam_connection_id' => $binding->gam_connection_id,
                        'gam_ad_unit_id' => $binding->ad_unit_id,
                        'gam_report_site' => $scope['hostname'], 'gam_report_scope' => $scope['fingerprint'],
                        'gam_report_basis' => SiteGamReportMetrics::BASIS,
                        // Google provides no equivalent AdX unfilled-impression
                        // counter here. Never fabricate it from request counts.
                        'unfilled_impressions' => null,
                        // CSV_DUMP money is micros. The existing ledger stores hundredths, rounded once per aggregate.
                        'gross_revenue_minor' => ($micros < 0 ? -1 : 1) * intdiv(abs($micros) + 5000, 10000),
                    ];
                }
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function canonicalCurrency(): string
    {
        $currency = strtoupper(trim((string) config('reporting.canonical_currency', 'USD')));

        return preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : 'USD';
    }

    private function integer(string $value): int
    {
        if (! preg_match('/^-?\d+$/D', $value) || strlen(ltrim($value, '-0')) > 15) {
            throw new RuntimeException('The Google report contains an invalid or oversized integer metric.');
        }

        return (int) $value;
    }
}
