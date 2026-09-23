<?php

namespace App\Services\Reporting\Connectors;

use App\Enums\ReportFinality;
use App\Enums\ReportGranularity;
use App\Models\GamConnection;
use App\Models\ReportSourceConnection;
use App\Services\Reporting\Contracts\ReportSourceConnectorInterface;
use App\Services\Reporting\GamAdUnitReportClient;
use App\Services\Reporting\GamReportPending;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

final class GamReportConnector implements ReportSourceConnectorInterface
{
    /**
     * Use the same total-delivery metrics as website GAM reporting so that
     * Ad Server, Ad Exchange, and other dynamic-allocation revenue is not
     * silently reduced to booked Ad Server revenue.
     *
     * @var array<string, string>
     */
    public const COLUMNS = [
        'TOTAL_AD_REQUESTS' => 'ad_requests',
        'TOTAL_RESPONSES_SERVED' => 'matched_requests',
        'TOTAL_UNMATCHED_AD_REQUESTS' => 'unfilled_requests',
        'TOTAL_LINE_ITEM_LEVEL_IMPRESSIONS' => 'impressions',
        'TOTAL_LINE_ITEM_LEVEL_CLICKS' => 'clicks',
        'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE' => 'revenue_micros',
    ];

    /**
     * Keep the financial source at inventory level. Request metrics exist
     * before a line item or creative is selected, so adding line-item/creative
     * dimensions can make Google reject an otherwise valid revenue report.
     * Website-level reporting has already proven DATE + AD_UNIT_ID with this
     * metric set in production.
     *
     * @var array<string, string>
     */
    private const DIMENSIONS = [
        'AD_UNIT_ID' => 'gam_ad_unit_id',
    ];

    public function __construct(private readonly GamAdUnitReportClient $google)
    {
    }

    public function fetch(
        ReportSourceConnection $connection,
        CarbonInterface $from,
        CarbonInterface $to,
        ReportGranularity $granularity,
        ReportFinality $finality,
        array $options = [],
    ): array {
        $gam = GamConnection::withoutGlobalScopes()->findOrFail($connection->connection_id);
        $canonicalCurrency = $this->canonicalCurrency();

        if ($granularity !== ReportGranularity::Daily) {
            throw new RuntimeException('Full-network GAM financial reports use daily totals. Refresh daily estimates for intraday updates.');
        }
        if (strtoupper((string) $connection->currency) !== $canonicalCurrency) {
            throw new RuntimeException('GAM financial reporting must use the canonical Horus reporting currency.');
        }
        if (! $connection->is_enabled || ! $gam->is_enabled || $to->lt($from) || $from->diffInDays($to) > 32) {
            throw new RuntimeException('The GAM report dates or connection are not valid for automatic financial reporting.');
        }
        $key = hash('sha256', implode('|', [
            'full-network', $granularity->value, $from->toDateString(), $to->toDateString(), $canonicalCurrency,
            hash('sha256', json_encode($options['statement'] ?? null, JSON_THROW_ON_ERROR)),
        ]));
        $configuration = (array) ($connection->configuration ?? []);
        $jobId = data_get($configuration, 'google_jobs.'.$key.'.id');

        if (! $jobId) {
            $network = $this->google->call($gam, 'NetworkService', 'getCurrentNetwork');
            if ((string) ($network['networkCode'] ?? '') !== (string) $gam->network_code) {
                throw new RuntimeException('The Google network identity changed. Reconnect this GAM reporting source.');
            }

            $sourceCurrency = strtoupper(trim((string) ($network['currencyCode'] ?? '')));
            if (preg_match('/^[A-Z]{3}$/D', $sourceCurrency) === 1) {
                $configuration['source_network_currency'] = $sourceCurrency;
            }

            $networkTimezone = trim((string) ($network['timeZone'] ?? ''));
            if ($networkTimezone !== '') {
                try {
                    CarbonImmutable::now($networkTimezone);
                    $connection->timezone = $networkTimezone;
                } catch (\Throwable) {
                    throw new RuntimeException('Google returned an invalid GAM network timezone.');
                }
            }
            $configuration['currency_policy'] = 'CANONICAL_REPORTING_CURRENCY';
            $configuration['report_currency'] = $canonicalCurrency;

            if ($finality === ReportFinality::Finalized
                && $to->toDateString() >= CarbonImmutable::now($connection->timezone)->toDateString()) {
                throw new RuntimeException('A finalized GAM report cannot include the current reporting day.');
            }

            $date = fn (CarbonInterface $day): array => [
                'year' => $day->year,
                'month' => $day->month,
                'day' => $day->day,
            ];
            $dimensions = array_merge(['DATE'], array_keys(self::DIMENSIONS));

            $query = [
                'dimensions' => $dimensions,
                'columns' => array_keys(self::COLUMNS),
                'adUnitView' => 'FLAT',
                'dateRangeType' => 'CUSTOM_DATE',
                'startDate' => $date($from),
                'endDate' => $date($to),
                // Google performs the conversion in the report itself. Never
                // relabel source-network money after it reaches Horus.
                'reportCurrency' => $canonicalCurrency,
            ];
            if (is_array($options['statement'] ?? null) && ($options['statement'] ?? []) !== []) {
                $query['statement'] = $options['statement'];
            }

            $response = $this->google->call($gam, 'ReportService', 'runReportJob', [
                'reportJob' => ['reportQuery' => $query],
            ]);
            $jobId = (string) ($response['id'] ?? '');
            if (! ctype_digit($jobId)) {
                throw new RuntimeException('Google did not return a valid GAM report job ID.');
            }

            $configuration['google_jobs'][$key] = [
                'id' => $jobId,
                'requested_at' => now()->toIso8601String(),
                'currency' => $canonicalCurrency,
            ];
            $connection->update([
                'configuration' => $configuration,
                'timezone' => $connection->timezone,
            ]);
        } elseif ($finality === ReportFinality::Finalized
            && $to->toDateString() >= CarbonImmutable::now($connection->timezone)->toDateString()) {
            throw new RuntimeException('A finalized GAM report cannot include the current reporting day.');
        }

        $status = $this->google->call($gam, 'ReportService', 'getReportJobStatus', ['reportJobId' => $jobId]);
        if (($status['value'] ?? '') === 'FAILED') {
            unset($configuration['google_jobs'][$key]);
            $connection->update(['configuration' => $configuration]);
            throw new RuntimeException('Google could not complete the GAM financial report. The request will be retried.');
        }
        if (($status['value'] ?? '') !== 'COMPLETED') {
            $requestedAt = data_get($configuration, 'google_jobs.'.$key.'.requested_at');
            if ($requestedAt && CarbonImmutable::parse($requestedAt)->lt(now()->subHours(6))) {
                unset($configuration['google_jobs'][$key]);
                $connection->update(['configuration' => $configuration]);
                throw new RuntimeException('Google GAM report preparation expired. A new request will be scheduled automatically.');
            }

            throw new GamReportPending('Google is preparing the GAM financial report; synchronization will resume automatically.');
        }

        try {
            $rows = $this->parse(
                $this->google->download($gam, (string) $jobId),
                $connection,
                $gam,
                $from,
                $to,
                $granularity,
            );
        } catch (\Throwable $exception) {
            // A completed Google job can still produce unusable output.
            // Force the retry path to request a fresh report rather than
            // repeatedly downloading the same terminal bad artifact.
            unset($configuration['google_jobs'][$key]);
            $connection->update(['configuration' => $configuration]);
            throw $exception;
        }

        unset($configuration['google_jobs'][$key]);
        $configuration['last_completed_report_currency'] = $canonicalCurrency;
        $connection->update(['configuration' => $configuration]);

        $totals = [];
        foreach (['ad_requests', 'matched_requests', 'unfilled_requests', 'impressions', 'clicks', 'gross_revenue_minor'] as $field) {
            $totals[$field] = array_sum(array_column($rows, $field));
        }

        return [
            'external_report_id' => 'gam:'.$jobId.':'.hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)),
            'rows' => $rows,
            'totals' => $totals,
            'metadata' => [
                'gam_connection_id' => $gam->id,
                'network_code' => $gam->network_code,
                'report_currency' => $canonicalCurrency,
                'source_network_currency' => data_get($configuration, 'source_network_currency'),
            ],
        ];
    }

    private function parse(
        string $csv,
        ReportSourceConnection $connection,
        GamConnection $gam,
        CarbonInterface $from,
        CarbonInterface $to,
        ReportGranularity $granularity,
    ): array {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);

        try {
            $headers = fgetcsv($stream, escape: '');
            if (! is_array($headers)) {
                throw new RuntimeException('The Google GAM report has no CSV header.');
            }
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");

            $required = array_merge(
                ['Dimension.DATE'],
                array_map(fn (string $dimension): string => 'Dimension.'.$dimension, array_keys(self::DIMENSIONS)),
                array_map(fn (string $column): string => 'Column.'.$column, array_keys(self::COLUMNS)),
            );
            if (array_diff($required, $headers) || count(array_unique($headers)) !== count($headers)) {
                throw new RuntimeException('The Google GAM report is missing required dimensions or metrics.');
            }

            $rows = [];
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new RuntimeException('The Google GAM report contains an incomplete CSV row.');
                }

                $source = array_combine($headers, $values);
                $day = (string) $source['Dimension.DATE'];
                $hour = 0;
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)
                    || $day < $from->toDateString()
                    || $day > $to->toDateString()
                    || $hour < 0 || $hour > 23) {
                    throw new RuntimeException('The Google GAM report contains data outside the requested reporting window.');
                }

                $metrics = [];
                foreach (self::COLUMNS as $column => $field) {
                    $rawValue = (string) $source['Column.'.$column];
                    $sourceCurrency = strtoupper((string) data_get($connection->configuration, 'source_network_currency', ''));
                    $value = $field === 'revenue_micros'
                        ? $this->moneyMicros(
                            $rawValue,
                            $this->canonicalCurrency(),
                            $sourceCurrency !== '' && $sourceCurrency !== $this->canonicalCurrency(),
                        )
                        : $this->integer($rawValue);
                    if ($field !== 'revenue_micros' && $value < 0) {
                        throw new RuntimeException('The Google GAM report contains a negative delivery metric.');
                    }
                    $metrics[$field] = $value;
                }
                $micros = $metrics['revenue_micros'];
                unset($metrics['revenue_micros']);

                $row = $metrics + [
                    'date' => $day,
                    'hour' => $hour,
                    'currency' => $this->canonicalCurrency(),
                    'organization_id' => $connection->organization_id,
                    'gam_connection_id' => $gam->id,
                    'gross_revenue_minor' => ($micros < 0 ? -1 : 1) * intdiv(abs($micros) + 5000, 10000),
                ];
                foreach (self::DIMENSIONS as $dimension => $field) {
                    $value = trim((string) $source['Dimension.'.$dimension]);
                    if ($value !== '') {
                        $row[$field] = $field === 'country_code' ? strtoupper(substr($value, 0, 2)) : $value;
                    }
                }
                $rows[] = $row;
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

    private function moneyMicros(string $value, string $expectedCurrency, bool $requireCurrencyMarker = false): int
    {
        $value = trim($value);

        if (preg_match('/^(.+?)\\s+(-?\\d+)$/uD', $value, $matches) === 1) {
            $prefix = trim((string) $matches[1]);
            $allowedPrefixes = $expectedCurrency === 'USD'
                ? ['USD', '$', 'US$']
                : [$expectedCurrency];

            if (! in_array($prefix, $allowedPrefixes, true)) {
                throw new RuntimeException('The Google GAM report returned a monetary value in an unexpected currency.');
            }

            $value = (string) $matches[2];
        } elseif (preg_match('/^-?\\d+$/D', $value) !== 1) {
            throw new RuntimeException('The Google GAM report contains an invalid revenue value.');
        } elseif ($requireCurrencyMarker) {
            throw new RuntimeException('The Google GAM report did not prove that converted revenue is in the canonical currency.');
        }

        return $this->integer($value);
    }

    private function integer(string $value): int
    {
        if (! preg_match('/^-?\d+$/D', $value) || strlen(ltrim($value, '-0')) > 15) {
            throw new RuntimeException('The Google GAM report contains an invalid or oversized integer metric.');
        }

        return (int) $value;
    }
}
