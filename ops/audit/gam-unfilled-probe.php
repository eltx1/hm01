<?php

declare(strict_types=1);

/** One bounded, read-only diagnostic. Never imports, revises money, or substitutes a metric. */
final class HorusGamUnfilledProbe
{
    public const METRIC = 'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS';
    public const DIMENSIONS = ['DATE', 'AD_UNIT_ID', 'SITE_NAME'];

    public static function run(array $cases, $google, callable $saveEvidence, ?callable $clock = null, ?callable $pause = null): array
    {
        $clock ??= static fn (): float => microtime(true);
        $pause ??= static fn () => usleep(1000000);
        if (count($cases) > 25) throw new RuntimeException('CAPACITY_EXCEEDED');
        $deadline = $clock() + 240;
        $probes = []; $jobs = [];
        foreach ($cases as $index => $case) {
            $probes[$index] = self::emptyProbe();
            if ($clock() >= $deadline - 65) {
                $probes[$index]['reason'] = 'TIME_BUDGET';
                continue;
            }
            try {
                self::validateCase($case);
                $network = $google->call($case['connection'], 'NetworkService', 'getCurrentNetwork');
                if ((string) ($network['networkCode'] ?? '') !== $case['network_code']
                    || ($network['timeZone'] ?? '') !== $case['timezone']) throw new RuntimeException('NETWORK_METADATA_MISMATCH');
                $date = static function (string $value): array {
                    [$y, $m, $d] = array_map('intval', explode('-', $value));
                    return ['year' => $y, 'month' => $m, 'day' => $d];
                };
                $query = ['dimensions' => self::DIMENSIONS, 'columns' => [self::METRIC],
                    'adUnitView' => 'FLAT', 'dateRangeType' => 'CUSTOM_DATE',
                    'startDate' => $date($case['from']), 'endDate' => $date($case['to']), 'timeZoneType' => 'PUBLISHER',
                    'statement' => ['query' => 'WHERE AD_UNIT_ID = :unit', 'values' => [
                        ['key' => 'unit', 'value' => ['__type' => 'NumberValue', 'value' => $case['unit_id']]],
                    ]]];
                // No AdX/revenue metrics, optional fallback, broadened unit or hostname scope.
                $saveEvidence($index, ['query' => $query, 'hostname' => $case['hostname'], 'timezone' => $case['timezone']]);
                $response = $google->call($case['connection'], 'ReportService', 'runReportJob', ['reportJob' => ['reportQuery' => $query]]);
                $id = (string) ($response['id'] ?? '');
                if (! ctype_digit($id)) throw new RuntimeException('INVALID_JOB');
                $saveEvidence($index, ['job_id' => $id]);
                $probes[$index]['query_status'] = 'ACCEPTED';
                $jobs[$index] = $case + ['job_id' => $id];
            } catch (Throwable $error) {
                $reason = self::safeError($error);
                $probes[$index]['query_status'] = $reason === 'COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS' ? 'UNSUPPORTED' : 'INCONCLUSIVE';
                $probes[$index]['reason'] = $reason;
                $saveEvidence($index, ['reason' => $reason]);
            }
        }
        while ($jobs !== []) {
            foreach ($jobs as $index => $job) {
                if ($clock() >= $deadline - 55) {
                    $probes[$index]['reason'] = 'TIME_BUDGET';
                    unset($jobs[$index]);
                    continue;
                }
                try {
                    $status = $google->call($job['connection'], 'ReportService', 'getReportJobStatus', ['reportJobId' => $job['job_id']]);
                    if (($status['value'] ?? '') === 'FAILED') throw new RuntimeException('GOOGLE_REPORT_FAILED');
                    if (($status['value'] ?? '') !== 'COMPLETED') continue;
                    $csv = $google->download($job['connection'], $job['job_id']);
                    $saveEvidence($index, ['csv' => $csv]);
                    $evidence = self::validateCsv($csv, $job);
                    $probes[$index] = array_replace($probes[$index], $evidence, ['query_status' => 'COMPLETED', 'reason' => null]);
                    unset($jobs[$index]);
                } catch (Throwable $error) {
                    $probes[$index]['reason'] = self::safeError($error);
                    $saveEvidence($index, ['reason' => $probes[$index]['reason']]);
                    unset($jobs[$index]);
                }
            }
            if ($jobs !== []) $pause();
        }
        return ['schema_version' => 1, 'metric' => self::METRIC, 'scope' => 'AD_UNIT_AND_EXACT_SITE',
            'period' => 'LAST_SEVEN_COMPLETE_DAYS', 'bindings' => count($cases), 'probes' => array_values($probes)];
    }

    public static function validateCsv(string $csv, array $case): array
    {
        $stream = fopen('php://temp', 'w+'); fwrite($stream, $csv); rewind($stream);
        $seen = []; $exactDays = []; $nonmatching = false; $rows = false;
        try {
            $headers = fgetcsv($stream, escape: '');
            if (! is_array($headers)) throw new RuntimeException('INVALID_CSV');
            $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            $required = ['Dimension.DATE', 'Dimension.AD_UNIT_ID', 'Dimension.SITE_NAME', 'Column.'.self::METRIC];
            if (array_diff($required, $headers) || count(array_unique($headers)) !== count($headers)) throw new RuntimeException('INVALID_CSV');
            while (($values = fgetcsv($stream, escape: '')) !== false) {
                if ($values === [null]) continue;
                if (count($values) !== count($headers)) throw new RuntimeException('INVALID_CSV');
                $row = array_combine($headers, $values);
                $day = $row['Dimension.DATE'];
                if (! self::validDate($day) || $day < $case['from'] || $day > $case['to']
                    || $row['Dimension.AD_UNIT_ID'] !== $case['unit_id']
                    || ! preg_match('/^\d{1,15}$/D', $row['Column.'.self::METRIC])) throw new RuntimeException('INVALID_CSV');
                $hostname = strtolower(preg_replace('/\.$/D', '', trim($row['Dimension.SITE_NAME'])));
                $key = $day.'|'.$hostname;
                if (isset($seen[$key])) throw new RuntimeException('DUPLICATE_ROWS');
                $seen[$key] = true; $rows = true;
                if ($hostname === $case['hostname']) $exactDays[$day] = true;
                else $nonmatching = true;
            }
        } finally { fclose($stream); }
        $days = (new DateTimeImmutable($case['from']))->diff(new DateTimeImmutable($case['to']))->days + 1;
        return ['valid_csv' => true, 'nonempty_rows' => $rows, 'exact_site_observed' => $exactDays !== [],
            'exact_site_days_complete' => count($exactDays) === $days, 'nonmatching_site_observed' => $nonmatching];
    }

    private static function validateCase(array $case): void
    {
        if (! ctype_digit($case['unit_id'] ?? '') || ! ctype_digit($case['network_code'] ?? '')
            || ! in_array($case['timezone'] ?? '', timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)
            || ! filter_var($case['hostname'] ?? '', FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ! str_contains($case['hostname'] ?? '', '.')
            || ! self::validDate($case['from'] ?? '') || ! self::validDate($case['to'] ?? '')
            || $case['from'] > $case['to']
            || (new DateTimeImmutable($case['from']))->diff(new DateTimeImmutable($case['to']))->days > 6) throw new RuntimeException('INVALID_CASE');
    }

    private static function validDate(string $day): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        return $date !== false && $date->format('Y-m-d') === $day;
    }

    private static function emptyProbe(): array
    {
        return ['query_status' => 'INCONCLUSIVE', 'reason' => null, 'valid_csv' => null, 'nonempty_rows' => null,
            'exact_site_observed' => null, 'exact_site_days_complete' => null, 'nonmatching_site_observed' => null];
    }

    public static function safeError(Throwable $error): string
    {
        foreach (['COLUMNS_NOT_SUPPORTED_FOR_REQUESTED_DIMENSIONS', 'INVALID_DIMENSION_FILTERS', 'NETWORK_METADATA_MISMATCH',
            'INVALID_JOB', 'GOOGLE_REPORT_FAILED', 'INVALID_CSV', 'DUPLICATE_ROWS', 'INVALID_CASE', 'CAPACITY_EXCEEDED', 'EVIDENCE_WRITE_FAILED'] as $code) {
            if (str_contains($error->getMessage(), $code)) return $code;
        }
        return 'PROBE_FAILED';
    }
}

if (defined('HORUS_GAM_UNFILLED_PROBE_LIBRARY_ONLY')) return;
ini_set('display_errors', '0');
try {
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $bindings = App\Models\SiteGamReportBinding::withoutGlobalScopes()->with(['connection', 'gamConnection', 'site'])
        ->whereNotNull('active_site_id')->whereHas('connection', fn ($q) => $q->where('is_enabled', true))
        ->whereHas('gamConnection', fn ($q) => $q->where('is_enabled', true))->orderBy('id')->limit(26)->get();
    $cases = $bindings->map(function ($binding): array {
        if (! $binding->site || $binding->site->organization_id !== $binding->organization_id
            || $binding->connection->organization_id !== $binding->organization_id
            || $binding->connection->connection_type !== 'SITE_GAM_AD_UNIT'
            || $binding->connection->connection_id !== $binding->id) throw new RuntimeException('INVALID_CASE');
        $end = Carbon\CarbonImmutable::now($binding->connection->timezone)->subDay();
        return ['connection' => $binding->gamConnection, 'network_code' => (string) $binding->network_code,
            'unit_id' => (string) $binding->ad_unit_id, 'timezone' => (string) $binding->connection->timezone,
            'hostname' => app(App\Services\Reporting\SiteGamReportScope::class)->hostname($binding->site->primary_domain),
            'from' => $end->subDays(6)->toDateString(), 'to' => $end->toDateString()];
    })->all();
    // Raw aggregate evidence stays on the existing production host, never in Actions.
    $directory = storage_path('app/private/gam-unfilled-probe/'.bin2hex(random_bytes(12)));
    if (! mkdir($directory, 0700, true)) throw new RuntimeException('EVIDENCE_WRITE_FAILED');
    $saveEvidence = static function (int $index, array $value) use ($directory): void {
        $path = $directory.'/soap-'.$index.'.jsonl';
        $file = fopen($path, 'ab');
        if ($file === false) throw new RuntimeException('EVIDENCE_WRITE_FAILED');
        chmod($path, 0600);
        try {
            if (fwrite($file, json_encode($value, JSON_THROW_ON_ERROR).PHP_EOL) === false) throw new RuntimeException('EVIDENCE_WRITE_FAILED');
        } finally { fclose($file); }
    };
    $result = HorusGamUnfilledProbe::run($cases, app(App\Services\Reporting\GamAdUnitReportClient::class), $saveEvidence);
    $restEvidence = static function (int $index, array $value) use ($directory): void {
        $path = $directory.'/rest-'.$index.'.jsonl';
        $file = fopen($path, 'ab');
        if ($file === false) throw new RuntimeException('EVIDENCE_WRITE_FAILED');
        chmod($path, 0600);
        try {
            if (fwrite($file, json_encode($value, JSON_THROW_ON_ERROR).PHP_EOL) === false) throw new RuntimeException('EVIDENCE_WRITE_FAILED');
        } finally { fclose($file); }
    };
    $restRequest = static function (array $case, string $verb, string $path, array $payload): array {
        // Fixed official origin and closed endpoint families; no credential-bearing
        // URL, redirect, cross-network name, persistent grant or retry is allowed.
        $network = 'networks/'.$case['network_code'];
        $suffix = substr($path, strlen($network));
        if (! str_starts_with($path, $network) || ! in_array($verb, ['GET', 'POST'], true)
            || ! preg_match('~^(|/reports|/reports/[0-9]+|/reports/[0-9]+:run|/operations/reports/runs/[A-Za-z0-9_-]+|/reports/[0-9]+/results/[A-Za-z0-9_-]+:fetchRows)$~D', $suffix)
            || ($verb === 'POST' && ! preg_match('~^/reports(?:/[0-9]+:run)?$~D', $suffix))) throw new RuntimeException('REST_FAILED');
        $connection = $case['connection'];
        $audit = app(App\Services\Audit\AuditRecorder::class);
        $audit->record('reporting.true_unfilled.rest_requested', $connection->organization_id, auditable: $connection,
            metadata: ['http_method' => $verb, 'resource' => $path, 'metric' => 'UNFILLED_IMPRESSIONS', 'diagnostic_only' => true]);
        $status = null;
        try {
            $request = Illuminate\Support\Facades\Http::withToken(app(App\Services\Gam\GamOAuthTokenProvider::class)->accessToken($connection))
                ->acceptJson()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 15]);
            $url = 'https://admanager.googleapis.com/v1/'.$path;
            $response = $verb === 'GET' ? $request->get($url, $payload)
                : ($payload === [] ? $request->withBody('', 'application/json')->send('POST', $url)
                    : $request->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')->send('POST', $url));
            $status = $response->status();
            $stream = $response->toPsrResponse()->getBody(); $body = ''; $deadline = microtime(true) + 15;
            try {
                while (! $stream->eof()) {
                    $body .= $stream->read(65536);
                    if (strlen($body) > 4 * 1024 * 1024 || microtime(true) > $deadline) throw new RuntimeException('REST_TIMEOUT');
                }
            } finally { $stream->close(); }
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) throw new RuntimeException('INVALID_RESPONSE');
            if (! $response->successful() && ! isset($decoded['error'])) {
                throw new RuntimeException(in_array($status, [401, 403], true) ? 'REST_ACCESS_BLOCKED' : 'REST_FAILED');
            }
            return $decoded;
        } catch (Throwable $error) {
            // HTTP exceptions can include identifiers; the helper returns only enums.
            throw new RuntimeException(HorusGamRestUnfilledProbe::safeError($error));
        } finally {
            $audit->record('reporting.true_unfilled.rest_finished', $connection->organization_id, auditable: $connection,
                metadata: ['http_method' => $verb, 'http_status' => $status, 'metric' => 'UNFILLED_IMPRESSIONS', 'diagnostic_only' => true]);
        }
    };
    $result['rest'] = HorusGamRestUnfilledProbe::run($cases, $restRequest, $restEvidence, dryRun: false);
    $saveEvidence(0, ['summary' => $result]);
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['schema_version' => 1, 'metric' => HorusGamUnfilledProbe::METRIC,
        'reason' => HorusGamUnfilledProbe::safeError($error)], JSON_THROW_ON_ERROR).PHP_EOL;
}
