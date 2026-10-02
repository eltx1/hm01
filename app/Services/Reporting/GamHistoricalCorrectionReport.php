<?php

namespace App\Services\Reporting;

use App\Models\GamConnection;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Private correction evidence only; never saves reports, checkpoints or financial facts. */
final class GamHistoricalCorrectionReport
{
    public function __construct(
        private readonly GamAdUnitReportClient $google,
        private readonly GamReportMoneyParser $money,
        private readonly GamRevenueComparisonService $comparison,
    ) {}

    public function query(array $context): array
    {
        $this->assertBasis($context);
        $date = fn (string $day) => array_combine(['year', 'month', 'day'], array_map('intval', explode('-', $day)));

        return [
            'dimensions' => ['DATE', 'AD_UNIT_ID', 'SITE_NAME'],
            'columns' => array_keys(SiteGamReportMetrics::COLUMNS),
            'adUnitView' => 'FLAT', 'dateRangeType' => 'CUSTOM_DATE',
            'startDate' => $date($context['from']), 'endDate' => $date($context['to']),
            'timeZoneType' => 'PUBLISHER', 'reportCurrency' => 'USD',
            'statement' => ['query' => 'WHERE AD_UNIT_ID = :unit', 'values' => [
                ['key' => 'unit', 'value' => ['__type' => 'NumberValue', 'value' => $context['ad_unit_id']]],
            ]],
        ];
    }

    public function queryHash(array $context): string
    {
        return hash('sha256', json_encode([
            'metric_basis' => SiteGamReportMetrics::BASIS, 'query' => $this->query($context),
        ], JSON_THROW_ON_ERROR));
    }

    public function start(array $context): array
    {
        $query = $this->query($context);
        $connection = $this->connection($context);
        // Only these two optional AdX counters may be removed by the client's
        // narrowly scoped unsupported-column fallback. Core metrics never fall back.
        $response = $this->google->runPerformanceReport($connection, $query, array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS));
        $id = (string) ($response['id'] ?? '');
        if (! ctype_digit($id)) throw new RuntimeException('INVALID_REPORT_JOB');

        return [
            'id' => $id, 'status' => 'PENDING',
            'confirmed_currency' => $this->money->confirmedCurrency($response, 'USD'),
            'next_poll_at' => CarbonImmutable::now()->addSeconds(10)->timestamp, 'polls' => 0,
        ];
    }

    public function poll(array $context, array $job): array
    {
        $this->assertBasis($context);
        if (($job['status'] ?? null) === 'COMPLETED') return $job;
        if (($job['status'] ?? null) !== 'PENDING' || ! is_string($job['id'] ?? null) || ! ctype_digit($job['id'])
            || ! is_int($job['next_poll_at'] ?? null) || ! is_int($job['polls'] ?? null) || $job['polls'] < 0
            || ! array_key_exists('confirmed_currency', $job) || ! in_array($job['confirmed_currency'], [null, 'USD'], true)) {
            throw new RuntimeException('INVALID_REPORT_JOB');
        }
        if (CarbonImmutable::now()->timestamp < $job['next_poll_at']) return $job;
        if ($job['polls'] >= 60) throw new RuntimeException('POLL_LIMIT_REACHED');
        $connection = $this->connection($context);
        $response = $this->google->call($connection, 'ReportService', 'getReportJobStatus', ['reportJobId' => $job['id']]);
        $status = $response['value'] ?? '';
        if ($status === 'FAILED') throw new RuntimeException('GOOGLE_REPORT_FAILED');
        if (! in_array($status, ['IN_PROGRESS', 'COMPLETED'], true)) throw new RuntimeException('INVALID_REPORT_STATUS');
        $job['polls']++;
        $job['next_poll_at'] = CarbonImmutable::now()->addSeconds(15)->timestamp;
        if ($status !== 'COMPLETED') return $job;

        $job['result'] = $this->parse($this->google->download($connection, $job['id']), $context, $job['confirmed_currency']);
        $this->comparison->assertContext($context);
        $job['status'] = 'COMPLETED';
        $job['completed_at'] = CarbonImmutable::now()->toIso8601String();

        return $job;
    }

    public function parse(string $csv, array $context, ?string $confirmedCurrency): array
    {
        $this->assertBasis($context);
        if (str_contains($csv, "\0")) throw new RuntimeException('INVALID_CSV_ROW');
        $required = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME',
            ...array_map(fn ($key) => 'Column.'.$key, array_keys(SiteGamReportMetrics::CORE_COLUMNS))];
        $optional = array_map(fn ($key) => 'Column.'.$key, array_keys(SiteGamReportMetrics::OPTIONAL_COLUMNS));
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, str_starts_with($csv, "\xEF\xBB\xBF") ? substr($csv, 3) : $csv);
        rewind($stream);
        $days = $seen = [];
        $excluded = $rows = 0;
        try {
            $headers = $this->readRow($stream);
            // AD_UNIT_NAME is automatically supplied by Google, but is never
            // part of the scope, grain or returned evidence.
            $allowed = [...$required, ...$optional, 'Dimension.AD_UNIT_NAME'];
            if (! is_array($headers) || array_diff($required, $headers) || array_diff($headers, $allowed)
                || count(array_unique($headers)) !== count($headers)
                || ! in_array(count(array_intersect($headers, $optional)), [0, count($optional)], true)) {
                throw new RuntimeException('INVALID_CSV_HEADERS');
            }
            while (($values = $this->readRow($stream)) !== false) {
                if ($values === [null]) continue;
                if (++$rows > 100000 || count($values) !== count($headers)) throw new RuntimeException('INVALID_CSV_ROW');
                $row = array_combine($headers, $values);
                $day = $row['Dimension.DATE'];
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
                if (! $date || $date->format('Y-m-d') !== $day || $day < $context['from'] || $day > $context['to']) {
                    throw new RuntimeException('OUT_OF_SCOPE_DATE');
                }
                if ($row['Dimension.AD_UNIT_ID'] !== $context['ad_unit_id']) throw new RuntimeException('OUT_OF_SCOPE_UNIT');
                // Match only the normalized hostname; do not interpret URLs or
                // widen it to parent, sibling, child or www hostnames.
                $host = strtolower(preg_replace('/\.$/D', '', trim($row['Dimension.SITE_NAME'])));
                $identity = $day.'|'.$host;
                if (isset($seen[$identity])) throw new RuntimeException('DUPLICATE_CSV_ROW');
                $seen[$identity] = true;
                $metrics = [];
                foreach (SiteGamReportMetrics::COLUMNS as $column => $field) {
                    $header = 'Column.'.$column;
                    $metrics[$field] = ! array_key_exists($header, $row) ? null
                        : ($field === 'revenue_micros'
                            ? $this->money->parse($row[$header], 'USD', $context['network_currency'], $confirmedCurrency)
                            : $this->counter($row[$header]));
                }
                if ($metrics['active_view_viewable_impressions'] !== null
                    && $metrics['active_view_viewable_impressions'] > $metrics['active_view_measurable_impressions']) {
                    throw new RuntimeException('INVALID_ACTIVE_VIEW_COUNTERS');
                }
                $micros = $metrics['revenue_micros'];
                $metrics['gross_revenue_minor'] = ($micros < 0 ? -1 : 1) * intdiv(abs($micros) + 5000, 10000);
                $metrics['unfilled_impressions'] = null;
                // Validate every row before excluding other Sites. Missing
                // exact-site days must remain absent, never fabricated zeroes.
                if ($host !== $context['hostname']) { $excluded++; continue; }
                $days[$day] = $metrics;
            }
        } finally {
            fclose($stream);
        }
        ksort($days);

        return ['days' => $days, 'exact_site_observed' => $days !== [], 'excluded_site_rows' => $excluded, 'source_rows' => $rows];
    }

    private function connection(array $context): GamConnection
    {
        $binding = $this->comparison->assertContext($context);
        $network = $this->google->call($binding->gamConnection, 'NetworkService', 'getCurrentNetwork');
        if ((string) ($network['networkCode'] ?? '') !== $context['network_code']
            || $binding->network_code !== $context['network_code']
            || ($network['timeZone'] ?? '') !== $context['timezone']
            || $binding->connection->timezone !== $context['timezone']
            || ! preg_match('/^[A-Z]{3}$/D', (string) ($network['currencyCode'] ?? ''))
            || $network['currencyCode'] !== $context['network_currency']) {
            throw new RuntimeException('NETWORK_IDENTITY_CHANGED');
        }

        return $binding->gamConnection;
    }

    /** Reject malformed quoting that PHP's permissive CSV reader would accept. */
    private function readRow($stream): array|false
    {
        $start = ftell($stream);
        $values = fgetcsv($stream, escape: '');
        if ($values === false) return false;
        $end = ftell($stream);
        fseek($stream, $start);
        $raw = fread($stream, $end - $start);
        if (preg_match('/\A(?:[^",\r\n]*|"(?:[^"]|"")*")(?:,(?:[^",\r\n]*|"(?:[^"]|"")*"))*(?:\r?\n)?\z/D', $raw) !== 1) {
            throw new RuntimeException('INVALID_CSV_ROW');
        }

        return $values;
    }

    private function counter(string $value): int
    {
        if (! preg_match('/^\d{1,15}$/D', $value)) throw new RuntimeException('INVALID_COUNTER');

        return (int) $value;
    }

    private function assertBasis(array $context): void
    {
        if (($context['metric_basis'] ?? null) !== SiteGamReportMetrics::BASIS) throw new RuntimeException('METRIC_BASIS_CHANGED');
        if (($context['currency'] ?? null) !== 'USD') throw new RuntimeException('INVALID_REPORT_CURRENCY');
    }
}
