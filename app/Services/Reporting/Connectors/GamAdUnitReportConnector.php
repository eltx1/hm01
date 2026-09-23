<?php

namespace App\Services\Reporting\Connectors;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Models\ReportSourceConnection;
use App\Models\SiteGamReportBinding;
use App\Services\Reporting\Contracts\ReportSourceConnectorInterface;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\GamReportPending;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

final class GamAdUnitReportConnector implements ReportSourceConnectorInterface
{
    public const COLUMNS = [
        'TOTAL_AD_REQUESTS' => 'ad_requests',
        'TOTAL_RESPONSES_SERVED' => 'matched_requests',
        'TOTAL_UNMATCHED_AD_REQUESTS' => 'unfilled_requests',
        'TOTAL_LINE_ITEM_LEVEL_IMPRESSIONS' => 'impressions',
        'TOTAL_LINE_ITEM_LEVEL_CLICKS' => 'clicks',
        'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => 'revenue_micros',
    ];

    public function __construct(private readonly GamAdUnitReportClient $google) {}

    public function fetch(ReportSourceConnection $connection, CarbonInterface $from, CarbonInterface $to,
        ReportGranularity $granularity, ReportFinality $finality, array $options = []): array
    {
        if ($granularity !== ReportGranularity::Daily) {
            throw new RuntimeException('GAM ad-unit reports use daily totals. Refresh daily estimates for intraday updates.');
        }
        $binding = SiteGamReportBinding::withoutGlobalScopes()->with(['gamConnection', 'site'])
            ->where('report_source_connection_id', $connection->id)->findOrFail($connection->connection_id);
        $currencyCutover = data_get($connection->configuration, 'canonical_currency_start_on');
        if (! $connection->is_enabled || ! $binding->gamConnection?->is_enabled || ! $binding->site
            || $binding->organization_id !== $connection->organization_id
            || $binding->site->organization_id !== $binding->organization_id
            || $from->toDateString() < $binding->starts_on->toDateString()
            || ($currencyCutover && $from->toDateString() < (string) $currencyCutover)
            || ($binding->ends_on && $to->toDateString() > $binding->ends_on->toDateString())
            || $to->lt($from) || $from->diffInDays($to) > 32
            || $to->toDateString() > CarbonImmutable::now($connection->timezone)->toDateString()
            || ($finality === ReportFinality::Finalized && ($granularity !== ReportGranularity::Daily
                || $to->toDateString() >= CarbonImmutable::now($connection->timezone)->toDateString()))
            || (string) $binding->gamConnection->network_code !== $binding->network_code) {
            throw new RuntimeException('The report dates or connection do not match this website reporting binding.');
        }
        $key = hash('sha256', $granularity->value.'|'.$from->toDateString().'|'.$to->toDateString());
        $configuration = $connection->configuration ?? [];
        $jobId = data_get($configuration, 'google_jobs.'.$key.'.id');
        if (! $jobId) {
            $network = $this->google->call($binding->gamConnection, 'NetworkService', 'getCurrentNetwork');
            if ((string) ($network['networkCode'] ?? '') !== $binding->network_code
                || ! preg_match('/^[A-Z]{3}$/D', (string) ($network['currencyCode'] ?? ''))
                || ($network['timeZone'] ?? '') !== $connection->timezone) {
                throw new RuntimeException('The Google network identity or timezone changed. Reconnect the ad unit from the website Reports section.');
            }
            $canonical = strtoupper((string) config('reporting.canonical_currency', 'USD'));
            if ($connection->currency !== $canonical) {
                throw new RuntimeException('This GAM reporting connection has not completed canonical USD migration yet.');
            }
            $configuration['network_currency'] = strtoupper((string) $network['currencyCode']);
            $configuration['report_currency'] = $canonical;
            $date = fn (CarbonInterface $day): array => ['year' => $day->year, 'month' => $day->month, 'day' => $day->day];
            $query = [
                'dimensions' => $granularity === ReportGranularity::Hourly ? ['DATE', 'HOUR', 'AD_UNIT_ID'] : ['DATE', 'AD_UNIT_ID'],
                'columns' => array_keys(self::COLUMNS), 'adUnitView' => 'FLAT', 'dateRangeType' => 'CUSTOM_DATE',
                'startDate' => $date($from), 'endDate' => $date($to), 'reportCurrency' => $connection->currency,
                'statement' => ['query' => 'WHERE AD_UNIT_ID = :unit', 'values' => [
                    ['key' => 'unit', 'value' => ['__type' => 'NumberValue', 'value' => $binding->ad_unit_id]],
                ]],
            ];
            $response = $this->google->call($binding->gamConnection, 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $query]]);
            $jobId = (string) ($response['id'] ?? '');
            if (! ctype_digit($jobId)) {
                throw new RuntimeException('Google did not return a valid report job ID.');
            }
            $configuration['google_jobs'][$key] = ['id' => $jobId, 'requested_at' => now()->toIso8601String(), 'report_currency' => $connection->currency];
            $connection->update(['configuration' => $configuration]);
        }
        $status = $this->google->call($binding->gamConnection, 'ReportService', 'getReportJobStatus', ['reportJobId' => $jobId]);
        if (($status['value'] ?? '') === 'FAILED') {
            unset($configuration['google_jobs'][$key]);
            $connection->update(['configuration' => $configuration]);
            throw new RuntimeException('Google could not complete the ad-unit report. The request will be retried.');
        }
        if (($status['value'] ?? '') !== 'COMPLETED') {
            if (CarbonImmutable::parse($configuration['google_jobs'][$key]['requested_at'])->lt(now()->subHours(6))) {
                unset($configuration['google_jobs'][$key]);
                $connection->update(['configuration' => $configuration]);
                throw new RuntimeException('Google report preparation expired. A new request will be scheduled automatically.');
            }
            throw new GamReportPending('Google is preparing the ad-unit report; synchronization will resume automatically.');
        }
        $rows = $this->parse($this->google->download($binding->gamConnection, (string) $jobId), $binding, $connection, $from, $to, $granularity);

        return [
            'rows' => $rows, 'external_report_id' => 'gam-unit:'.$jobId.':'.hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
            'totals' => collect(array_values(self::COLUMNS))->reject(fn ($column) => $column === 'revenue_micros')
                ->push('gross_revenue_minor')->mapWithKeys(fn ($column) => [$column => array_sum(array_column($rows, $column))])->all(),
            'pending_key' => $key,
        ];
    }

    private function parse(string $csv, SiteGamReportBinding $binding, ReportSourceConnection $connection,
        CarbonInterface $from, CarbonInterface $to, ReportGranularity $granularity): array
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
            $required = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', ...array_map(fn ($key) => 'Column.'.$key, array_keys(self::COLUMNS))];
            if ($granularity === ReportGranularity::Hourly) {
                $required[] = 'Dimension.HOUR';
            }
            if (array_diff($required, $headers) || count(array_unique($headers)) !== count($headers)) {
                throw new RuntimeException('The Google report is missing required dimensions or metrics.');
            }
            $buckets = [];
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
                if (isset($buckets[$key])) {
                    throw new RuntimeException('The Google report contains duplicate date/hour rows.');
                }
                $buckets[$key] = [];
                foreach (self::COLUMNS as $column => $field) {
                    $value = $this->integer($row['Column.'.$column]);
                    if ($field !== 'revenue_micros' && $value < 0) {
                        throw new RuntimeException('The Google report contains a negative delivery metric.');
                    }
                    $buckets[$key][$field] = $value;
                }
            }
            $rows = [];
            $now = CarbonImmutable::now($connection->timezone);
            for ($day = CarbonImmutable::parse($from->toDateString(), $connection->timezone); $day->toDateString() <= $to->toDateString(); $day = $day->addDay()) {
                $lastHour = $granularity === ReportGranularity::Hourly ? ($day->isSameDay($now) ? $now->hour : 23) : 0;
                for ($hour = 0; $hour <= $lastHour; $hour++) {
                    $metrics = $buckets[$day->toDateString().':'.$hour] ?? array_fill_keys(array_values(self::COLUMNS), 0);
                    $micros = $metrics['revenue_micros'];
                    unset($metrics['revenue_micros']);
                    $rows[] = $metrics + [
                        'date' => $day->toDateString(), 'hour' => $hour, 'currency' => $connection->currency,
                        'organization_id' => $binding->organization_id, 'site_id' => $binding->site_id,
                        'publisher_id' => $binding->site->publisher_id, 'gam_connection_id' => $binding->gam_connection_id,
                        'gam_ad_unit_id' => $binding->ad_unit_id,
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

    private function integer(string $value): int
    {
        if (! preg_match('/^-?\d+$/D', $value) || strlen(ltrim($value, '-0')) > 15) {
            throw new RuntimeException('The Google report contains an invalid or oversized integer metric.');
        }

        return (int) $value;
    }
}
