<?php

declare(strict_types=1);

/** Stream into the CURRENT production app before deploying changed import code.
 * Only Google report jobs and existing sanitized operational API audits are made.
 * Never import money, save report definitions, or emit CSV/identities/amounts.
 */
final class HorusGamSiteScopePreflight
{
    // Incoming release contract, checked against the connector in regression
    // tests. Do not derive this from the old deployed connector at runtime.
    public const COLUMNS = [
        'TOTAL_AD_REQUESTS', 'TOTAL_RESPONSES_SERVED', 'TOTAL_UNMATCHED_AD_REQUESTS',
        'TOTAL_LINE_ITEM_LEVEL_IMPRESSIONS', 'TOTAL_LINE_ITEM_LEVEL_CLICKS', 'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE',
        'TOTAL_ACTIVE_VIEW_VIEWABLE_IMPRESSIONS', 'TOTAL_ACTIVE_VIEW_MEASURABLE_IMPRESSIONS', 'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS',
    ];
    public static function run(array $cases, $google, $money, ?callable $clock = null, ?callable $pause = null): array
    {
        $clock ??= static fn (): float => microtime(true);
        $pause ??= static fn () => usleep(1000000);
        if (count($cases) > 25) throw new RuntimeException('PREFLIGHT_CAPACITY_EXCEEDED');
        $deadline = $clock() + 180;
        $jobs = [];
        $columns = self::COLUMNS;
        foreach ($cases as $case) {
            if ($clock() >= $deadline - 30) throw new RuntimeException('PREFLIGHT_TIMEOUT');
            $network = $google->call($case['connection'], 'NetworkService', 'getCurrentNetwork');
            $timezone = (string) ($network['timeZone'] ?? '');
            $currency = (string) ($network['currencyCode'] ?? '');
            if ((string) ($network['networkCode'] ?? '') !== $case['network_code']
                || $timezone !== $case['timezone'] || ! in_array($timezone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)
                || ! preg_match('/^[A-Z]{3}$/D', $currency)) throw new RuntimeException('NETWORK_METADATA_MISMATCH');
            $day = Carbon\CarbonImmutable::now($timezone)->subDay();
            $date = ['year' => $day->year, 'month' => $day->month, 'day' => $day->day];
            $query = [
                'dimensions' => ['DATE', 'AD_UNIT_ID', 'SITE_NAME'], 'columns' => $columns,
                'adUnitView' => 'FLAT', 'dateRangeType' => 'CUSTOM_DATE', 'startDate' => $date, 'endDate' => $date,
                'reportCurrency' => 'USD', 'timeZoneType' => 'PUBLISHER',
                'statement' => ['query' => 'WHERE AD_UNIT_ID = :unit', 'values' => [
                    ['key' => 'unit', 'value' => ['__type' => 'NumberValue', 'value' => $case['unit_id']]],
                ]],
            ];
            // Exact candidate query only. An unsupported query stops deployment;
            // never silently drop SITE_NAME, change metrics, or broaden filters.
            $response = $google->call($case['connection'], 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $query]]);
            $id = (string) ($response['id'] ?? '');
            if (! ctype_digit($id)) throw new RuntimeException('INVALID_REPORT_JOB');
            $jobs[] = $case + ['job_id' => $id, 'day' => $day->toDateString(), 'network_currency' => $currency,
                'confirmed_currency' => $money->confirmedCurrency($response, 'USD')];
        }
        $validated = 0;
        while ($jobs !== []) {
            foreach ($jobs as $key => $job) {
                if ($clock() >= $deadline - 15) throw new RuntimeException('PREFLIGHT_TIMEOUT');
                $status = $google->call($job['connection'], 'ReportService', 'getReportJobStatus', ['reportJobId' => $job['job_id']]);
                if (($status['value'] ?? '') === 'FAILED') throw new RuntimeException('GOOGLE_REPORT_FAILED');
                if (($status['value'] ?? '') !== 'COMPLETED') continue;
                self::validateCsv($google->download($job['connection'], $job['job_id']), $job, $columns, $money);
                $validated++;
                unset($jobs[$key]);
            }
            if ($jobs !== []) $pause();
        }
        return ['schema_version' => 1, 'compatible' => true, 'active_bindings' => count($cases),
            'validated_bindings' => $validated, 'scope' => 'AD_UNIT_AND_EXACT_SITE', 'currency' => 'USD'];
    }

    private static function validateCsv(string $csv, array $job, array $columns, $money): void
    {
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        try {
            $headers = fgetcsv($stream, escape: '');
            if (! is_array($headers)) throw new RuntimeException('INVALID_SCOPED_CSV');
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            $required = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME', ...array_map(fn ($column) => 'Column.'.$column, $columns)];
            if (array_diff($required, $headers) || count(array_unique($headers)) !== count($headers)) throw new RuntimeException('INVALID_SCOPED_CSV');
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if ($values === [null]) continue;
                if (count($values) !== count($headers)) throw new RuntimeException('INVALID_SCOPED_CSV');
                $row = array_combine($headers, $values);
                if ($row['Dimension.DATE'] !== $job['day'] || $row['Dimension.AD_UNIT_ID'] !== $job['unit_id']) throw new RuntimeException('INVALID_SCOPED_CSV');
                foreach ($columns as $column) {
                    $value = $row['Column.'.$column];
                    if ($column === 'TOTAL_LINE_ITEM_LEVEL_ALL_REVENUE') {
                        $money->parse($value, 'USD', $job['network_currency'], $job['confirmed_currency']);
                    } elseif (! preg_match('/^\d{1,15}$/D', $value)) throw new RuntimeException('INVALID_SCOPED_CSV');
                }
            }
        } finally {
            fclose($stream);
        }
    }

    public static function safeError(Throwable $error): string
    {
        foreach (['COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS', 'INVALID_DIMENSION_FILTERS', 'TIME_ZONE_TYPE_NOT_SUPPORTED_FOR_REQUESTED_REPORT', 'CURRENCY_CODE_NOT_SUPPORTED_FOR_REQUESTED_REPORT', 'AD_UNIT_VIEW_NOT_SUPPORTED_FOR_REQUESTED_REPORT', 'PREFLIGHT_CAPACITY_EXCEEDED', 'PREFLIGHT_TIMEOUT', 'NETWORK_METADATA_MISMATCH', 'INVALID_REPORT_JOB', 'GOOGLE_REPORT_FAILED', 'INVALID_SCOPED_CSV'] as $code) {
            if (str_contains($error->getMessage(), $code)) return $code;
        }
        return 'PREFLIGHT_FAILED';
    }
}

// Tests load the same preflight code without booting or contacting production.
if (defined('HORUS_GAM_SCOPE_PREFLIGHT_TEST')) return;
ini_set('display_errors', '0');
try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $bindings = App\Models\SiteGamReportBinding::withoutGlobalScopes()->with(['connection', 'gamConnection'])
        ->whereNotNull('active_site_id')->whereHas('connection', fn ($q) => $q->where('is_enabled', true))
        ->whereHas('gamConnection', fn ($q) => $q->where('is_enabled', true))->limit(26)->get();
    $cases = $bindings->map(fn ($binding) => ['connection' => $binding->gamConnection,
        'network_code' => (string) $binding->network_code, 'unit_id' => (string) $binding->ad_unit_id,
        'timezone' => (string) $binding->connection->timezone])->all();
    $result = HorusGamSiteScopePreflight::run($cases, app(App\Services\Reporting\GamAdUnitReportClient::class), app(App\Services\Reporting\GamReportMoneyParser::class));
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['schema_version' => 1, 'compatible' => false, 'reason' => HorusGamSiteScopePreflight::safeError($error)], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(1);
}
