<?php

declare(strict_types=1);

/**
 * Bounded diagnostic only. No imports, financial updates, sharing, or metric fallback.
 *
 * REST docs: https://developers.google.com/ad-manager/api/beta/reports
 * HIDDEN means API-only visibility, not a separate sharing/access-control guarantee.
 * The injected transport owns existing OAuth, sanitized auditing, a <=15s timeout,
 * and no automatic retries. It receives ($case, $verb, $relativePath, $bodyOrQuery).
 * An empty POST payload means an empty HTTP body, not the JSON array "[]".
 * Raw requests/results belong only in the private saveEvidence callback.
 */
final class HorusGamRestUnfilledProbe
{
    public const METRIC = 'UNFILLED_IMPRESSIONS';
    public const DIMENSIONS = ['DATE', 'AD_UNIT_ID', 'SITE'];
    private const BUDGET_SECONDS = 180;
    private const REQUEST_RESERVE_SECONDS = 20;
    private const MAX_PAGES = 2;
    private const MAX_POLLS = 8;

    public static function run(array $cases, callable $request, callable $saveEvidence, ?callable $clock = null, ?callable $pause = null, bool $dryRun = true): array
    {
        if (count($cases) > 25) throw new RuntimeException('CAPACITY_EXCEEDED');
        $clock ??= static fn (): float => microtime(true);
        $pause ??= static fn (int $seconds) => sleep($seconds);
        $started = $clock();
        $deadline = $started + self::BUDGET_SECONDS;
        $probes = []; $jobs = []; $knownReports = [];
        foreach (array_values($cases) as $index => $case) {
            $probes[$index] = self::emptyProbe();
            try {
                self::validateCase($case, $started);
                $call = static function (string $verb, string $path, array $payload = []) use ($case, $request, $saveEvidence, $index, $clock, $deadline): array {
                    self::checkBudget($clock, $deadline);
                    $saveEvidence($index, ['request' => ['method' => $verb, 'path' => $path, 'payload' => $payload]]);
                    $response = $request($case, $verb, $path, $payload);
                    if (! is_array($response)) throw new RuntimeException('INVALID_RESPONSE');
                    $saveEvidence($index, ['response' => $response]);
                    if (isset($response['error'])) throw new RuntimeException(self::errorReason(is_array($response['error']) ? $response['error'] : []));
                    return $response;
                };
                $networkPath = 'networks/'.$case['network_code'];
                $network = $call('GET', $networkPath);
                if (($network['name'] ?? '') !== $networkPath
                    || (string) ($network['networkCode'] ?? '') !== $case['network_code']
                    || ($network['timeZone'] ?? '') !== $case['timezone']) throw new RuntimeException('NETWORK_METADATA_MISMATCH');
                $definition = self::definition($case);
                $key = $networkPath.'|'.hash('sha256', json_encode(self::canonical($definition), JSON_THROW_ON_ERROR));
                $report = $knownReports[$key] ?? null;
                $next = ''; $seenTokens = [];
                for ($page = 0; $report === null && $page < self::MAX_PAGES; $page++) {
                    $query = ['pageSize' => 1000];
                    if ($next !== '') $query['pageToken'] = $next;
                    $listing = $call('GET', $networkPath.'/reports', $query);
                    if (! is_array($listing['reports'] ?? []) || ! array_is_list($listing['reports'] ?? [])) throw new RuntimeException('INVALID_RESPONSE');
                    foreach ($listing['reports'] ?? [] as $candidate) {
                        if (is_array($candidate) && self::sameDefinition($candidate['reportDefinition'] ?? null, $definition)) {
                            self::validateReportName($candidate['name'] ?? '', $networkPath);
                            $report = $candidate;
                            break;
                        }
                    }
                    $next = self::pageToken($listing);
                    $probes[$index]['list_exhausted'] = $next === '';
                    if ($report !== null || $next === '') break;
                    if (isset($seenTokens[$next])) throw new RuntimeException('LIST_INCOMPLETE');
                    $seenTokens[$next] = true;
                }
                if ($report !== null) {
                    $probes[$index]['definition_status'] = 'REUSED';
                } elseif ($next !== '') {
                    throw new RuntimeException('LIST_INCOMPLETE');
                } elseif ($dryRun) {
                    $probes[$index]['definition_status'] = 'WOULD_CREATE';
                } else {
                    // No schedule or sharing mutation. Never retry an uncertain create.
                    $report = $call('POST', $networkPath.'/reports', [
                        'displayName' => 'Horus unfilled diagnostic '.substr(hash('sha256', $key), 0, 16),
                        'visibility' => 'HIDDEN', 'reportDefinition' => $definition,
                    ]);
                    $probes[$index]['definition_status'] = 'CREATED';
                    self::validateReportName($report['name'] ?? '', $networkPath);
                    if (($report['visibility'] ?? 'HIDDEN') !== 'HIDDEN' || ! empty($report['scheduleOptions'])
                        || ! self::sameDefinition($report['reportDefinition'] ?? null, $definition)) throw new RuntimeException('REPORT_DEFINITION_MISMATCH');
                }
                if ($dryRun) {
                    $probes[$index]['query_status'] = 'DRY_RUN';
                    $probes[$index]['reason'] = 'DRY_RUN';
                    continue;
                }
                $knownReports[$key] = $report;
                // Re-read before running to catch stale listings or concurrent edits.
                $fresh = $call('GET', $report['name']);
                if (($fresh['name'] ?? '') !== $report['name'] || ! self::sameDefinition($fresh['reportDefinition'] ?? null, $definition)) throw new RuntimeException('REPORT_DEFINITION_MISMATCH');
                $operation = $call('POST', $report['name'].':run');
                self::validateOperation($operation, $networkPath, $report['name']);
                $probes[$index]['query_status'] = 'ACCEPTED';
                $jobs[$index] = ['case' => $case, 'call' => $call, 'operation' => $operation, 'report' => $report['name']];
            } catch (Throwable $error) {
                self::fail($probes[$index], $error, $index, $saveEvidence);
            }
        }
        for ($round = 0; $jobs !== [] && $round < self::MAX_POLLS; $round++) {
            $pending = array_filter($jobs, static fn (array $job): bool => ($job['operation']['done'] ?? false) !== true);
            if ($pending !== []) {
                $delay = min(20, 5 * (2 ** min($round, 2)));
                if ($clock() + $delay >= $deadline - self::REQUEST_RESERVE_SECONDS) break;
                $pause($delay);
            }
            foreach ($jobs as $index => $job) {
                try {
                    $operation = $job['operation'];
                    if (($operation['done'] ?? false) !== true) {
                        $operation = ($job['call'])('GET', $operation['name']);
                        self::validateOperation($operation, 'networks/'.$job['case']['network_code'], $job['report']);
                        if ($operation['name'] !== $job['operation']['name']) throw new RuntimeException('INVALID_OPERATION');
                        $jobs[$index]['operation'] = $operation;
                    }
                    if (($operation['done'] ?? false) !== true) continue;
                    if (isset($operation['error'])) throw new RuntimeException(self::errorReason($operation['error']));
                    $result = $operation['response']['reportResult'] ?? '';
                    if (! is_string($result) || ! preg_match('~^'.preg_quote($job['report'], '~').'/results/[A-Za-z0-9_-]+$~D', $result)) throw new RuntimeException('INVALID_RESULT');
                    $evidence = self::fetchRows($job['call'], $result, $job['case']);
                    $probes[$index] = array_replace($probes[$index], $evidence, ['query_status' => 'COMPLETED', 'reason' => $evidence['nonempty_rows'] ? null : 'NO_ROWS']);
                    unset($jobs[$index]);
                } catch (Throwable $error) {
                    self::fail($probes[$index], $error, $index, $saveEvidence);
                    unset($jobs[$index]);
                }
            }
        }
        foreach ($jobs as $index => $job) {
            $probes[$index]['reason'] = $clock() >= $deadline - 40 ? 'TIME_BUDGET' : 'POLL_LIMIT';
        }
        return ['schema_version' => 1, 'metric' => self::METRIC, 'scope' => 'AD_UNIT_AND_EXACT_SITE',
            'period' => 'LAST_SEVEN_COMPLETE_DAYS', 'dry_run' => $dryRun, 'bindings' => count($cases), 'probes' => array_values($probes)];
    }

    public static function definition(array $case): array
    {
        return ['reportType' => 'HISTORICAL', 'dimensions' => self::DIMENSIONS, 'metrics' => [self::METRIC],
            'dateRange' => ['fixed' => ['startDate' => self::dateParts($case['from']), 'endDate' => self::dateParts($case['to'])]],
            'timeZoneSource' => 'PUBLISHER', 'expandedCompatibility' => false,
            'filters' => [['andFilter' => ['filters' => [
                ['fieldFilter' => ['field' => ['dimension' => 'AD_UNIT_ID'], 'operation' => 'IN', 'values' => [['intValue' => $case['unit_id']]]]],
                ['fieldFilter' => ['field' => ['dimension' => 'SITE'], 'operation' => 'IN', 'values' => [['stringValue' => $case['hostname']]]]],
            ]]]]];
    }

    private static function sameDefinition(mixed $actual, array $expected): bool
    {
        if (! is_array($actual)) return false;
        // Only documented defaults are interchangeable with absence; unknown fields fail closed.
        $defaults = ['expandedCompatibility' => false, 'timeZoneSource' => 'PUBLISHER', 'timeZone' => '',
            'currencyCode' => '', 'timePeriodColumn' => 'TIME_PERIOD_COLUMN_UNSPECIFIED',
            'cmsMetadataDimensionKeyIds' => [], 'customDimensionKeyIds' => [], 'ekvDimensionKeyIds' => [],
            'lineItemCustomFieldIds' => [], 'orderCustomFieldIds' => [], 'creativeCustomFieldIds' => [], 'flags' => [], 'sorts' => []];
        return self::canonical(array_replace($defaults, $actual)) === self::canonical(array_replace($defaults, $expected));
    }

    private static function canonical(array $value): array
    {
        // IN and PRIMARY are documented enum defaults and may be omitted by protobuf JSON.
        if (isset($value['fieldFilter']) && is_array($value['fieldFilter'])) {
            $value['fieldFilter'] = array_replace(['operation' => 'IN', 'metricValueType' => 'PRIMARY', 'timePeriodIndex' => 0], $value['fieldFilter']);
        }
        foreach ($value as &$item) if (is_array($item)) $item = self::canonical($item);
        unset($item);
        if (! array_is_list($value)) ksort($value);
        return $value;
    }

    private static function fetchRows(callable $call, string $result, array $case): array
    {
        $rows = []; $next = ''; $seenTokens = []; $total = null;
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $query = ['pageSize' => 1000];
            if ($next !== '') $query['pageToken'] = $next;
            $response = $call('GET', $result.':fetchRows', $query);
            if (! is_array($response['rows'] ?? []) || ! array_is_list($response['rows'] ?? [])) throw new RuntimeException('INVALID_ROWS');
            if ($page === 0) {
                if (self::canonical($response['dateRanges'] ?? []) !== self::canonical([self::definition($case)['dateRange']['fixed']])
                    || ! empty($response['comparisonDateRanges'])) throw new RuntimeException('RESULT_DATE_RANGE_MISMATCH');
                $total = $response['totalRowCount'] ?? 0;
                if (! is_int($total) || $total < 0 || $total > 7) throw new RuntimeException('INVALID_ROWS');
            }
            $rows = array_merge($rows, $response['rows'] ?? []);
            if (count($rows) > 7) throw new RuntimeException('INVALID_ROWS');
            $next = self::pageToken($response);
            if ($next === '') break;
            if (isset($seenTokens[$next])) throw new RuntimeException('ROWS_INCOMPLETE');
            $seenTokens[$next] = true;
        }
        if ($next !== '' || count($rows) !== $total) throw new RuntimeException('ROWS_INCOMPLETE');
        return self::validateRows($rows, $case);
    }

    public static function validateRows(array $rows, array $case): array
    {
        $days = [];
        foreach ($rows as $row) {
            $dimensions = $row['dimensionValues'] ?? null;
            $groups = $row['metricValueGroups'] ?? null;
            if (! is_array($dimensions) || ! array_is_list($dimensions) || count($dimensions) !== 3
                || ! is_array($groups) || ! array_is_list($groups) || count($groups) !== 1
                || ! is_array($groups[0]['primaryValues'] ?? null) || ! array_is_list($groups[0]['primaryValues'])
                || count($groups[0]['primaryValues']) !== 1) throw new RuntimeException('INVALID_ROWS');
            $day = self::scalarValue($dimensions[0], 'stringValue');
            $unit = self::scalarValue($dimensions[1], 'intValue');
            $host = self::scalarValue($dimensions[2], 'stringValue');
            $count = self::scalarValue($groups[0]['primaryValues'][0], 'intValue');
            if (! self::validDate($day) || $day < $case['from'] || $day > $case['to']
                || $unit !== $case['unit_id'] || $host !== $case['hostname']
                || ! preg_match('/^(0|[1-9][0-9]{0,18})$/D', $count)
                || (strlen($count) === 19 && strcmp($count, '9223372036854775807') > 0)) throw new RuntimeException('INVALID_ROWS');
            if (isset($days[$day])) throw new RuntimeException('DUPLICATE_ROWS');
            $days[$day] = true;
        }
        // A missing row/day never establishes a numeric zero. No counts leave this helper.
        return ['valid_rows' => true, 'nonempty_rows' => $rows !== [], 'exact_site_observed' => $rows !== [],
            'exact_site_days_complete' => count($days) === 7, 'nonmatching_site_observed' => false];
    }

    private static function scalarValue(mixed $value, string $field): string
    {
        if (! is_array($value) || array_keys($value) !== [$field] || ! is_string($value[$field])) throw new RuntimeException('INVALID_ROWS');
        return $value[$field];
    }

    private static function validateCase(array $case, float $now): void
    {
        foreach (['network_code', 'unit_id', 'timezone', 'hostname', 'from', 'to'] as $field) {
            if (! isset($case[$field]) || ! is_string($case[$field])) throw new RuntimeException('INVALID_CASE');
        }
        if (! preg_match('/^[1-9][0-9]{0,18}$/D', $case['unit_id']) || ! preg_match('/^[1-9][0-9]{0,18}$/D', $case['network_code'])
            || ! in_array($case['timezone'], timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)
            || ! filter_var($case['hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! str_contains($case['hostname'], '.')
            || strtolower($case['hostname']) !== $case['hostname'] || str_ends_with($case['hostname'], '.')
            || ! self::validDate($case['from']) || ! self::validDate($case['to'])) throw new RuntimeException('INVALID_CASE');
        $end = (new DateTimeImmutable('@'.(string) ((int) $now)))->setTimezone(new DateTimeZone($case['timezone']))->modify('-1 day');
        if ($case['to'] !== $end->format('Y-m-d') || $case['from'] !== $end->modify('-6 days')->format('Y-m-d')) throw new RuntimeException('INVALID_CASE');
    }

    private static function validDate(string $day): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        return $date !== false && $date->format('Y-m-d') === $day;
    }

    private static function dateParts(string $day): array
    {
        [$year, $month, $day] = array_map('intval', explode('-', $day));
        return ['year' => $year, 'month' => $month, 'day' => $day];
    }

    private static function validateReportName(mixed $name, string $networkPath): void
    {
        if (! is_string($name) || ! preg_match('~^'.preg_quote($networkPath, '~').'/reports/[0-9]+$~D', $name)) throw new RuntimeException('INVALID_REPORT');
    }

    private static function validateOperation(array $operation, string $networkPath, string $report): void
    {
        if (! is_string($operation['name'] ?? null)
            || ! preg_match('~^'.preg_quote($networkPath, '~').'/operations/reports/runs/[A-Za-z0-9_-]+$~D', $operation['name'])
            || (isset($operation['done']) && ! is_bool($operation['done']))
            || (isset($operation['metadata']['report']) && $operation['metadata']['report'] !== $report)) throw new RuntimeException('INVALID_OPERATION');
    }

    private static function pageToken(array $response): string
    {
        $token = $response['nextPageToken'] ?? '';
        if (! is_string($token) || strlen($token) > 8192) throw new RuntimeException('INVALID_RESPONSE');
        return $token;
    }

    private static function checkBudget(callable $clock, float $deadline): void
    {
        if ($clock() >= $deadline - self::REQUEST_RESERVE_SECONDS) throw new RuntimeException('TIME_BUDGET');
    }

    private static function emptyProbe(): array
    {
        return ['query_status' => 'INCONCLUSIVE', 'definition_status' => 'NOT_SELECTED', 'reason' => null,
            'list_exhausted' => false, 'valid_rows' => null, 'nonempty_rows' => null,
            'exact_site_observed' => null, 'exact_site_days_complete' => null, 'nonmatching_site_observed' => null];
    }

    private static function fail(array &$probe, Throwable $error, int $index, callable $saveEvidence): void
    {
        $reason = self::safeError($error);
        $probe['query_status'] = match ($reason) {
            'REST_INCOMPATIBLE' => 'INCOMPATIBLE', 'REST_ACCESS_BLOCKED' => 'ACCESS_BLOCKED', default => 'INCONCLUSIVE',
        };
        $probe['reason'] = $reason;
        $saveEvidence($index, ['reason' => $reason]);
    }

    public static function errorReason(array $error): string
    {
        $status = $error['status'] ?? '';
        if (in_array($status, ['PERMISSION_DENIED', 'UNAUTHENTICATED'], true) || in_array($error['code'] ?? null, [7, 16, 401, 403], true)) return 'REST_ACCESS_BLOCKED';
        $message = strtoupper(is_string($error['message'] ?? null) ? $error['message'] : '');
        // An arbitrary INVALID_ARGUMENT is not evidence of metric incompatibility.
        if (in_array($status, ['INVALID_ARGUMENT', 'FAILED_PRECONDITION'], true) || in_array($error['code'] ?? null, [3, 9, 400], true)) {
            if (str_contains($message, 'DIMENSION') && str_contains($message, 'METRIC')
                && (str_contains($message, 'INCOMPATIBLE') || str_contains($message, 'NOT COMPATIBLE') || str_contains($message, 'NOT SUPPORTED'))) return 'REST_INCOMPATIBLE';
            return 'REST_INVALID_ARGUMENT';
        }
        return match ($status) {
            'RESOURCE_EXHAUSTED' => 'REST_RESOURCE_EXHAUSTED', 'DEADLINE_EXCEEDED' => 'REST_TIMEOUT',
            'NOT_FOUND' => 'REST_NOT_FOUND', 'UNAVAILABLE' => 'REST_UNAVAILABLE', default => 'REST_FAILED',
        };
    }

    public static function safeError(Throwable $error): string
    {
        $allowed = ['CAPACITY_EXCEEDED', 'INVALID_CASE', 'NETWORK_METADATA_MISMATCH', 'TIME_BUDGET', 'INVALID_RESPONSE',
            'INVALID_REPORT', 'REPORT_DEFINITION_MISMATCH', 'LIST_INCOMPLETE', 'INVALID_OPERATION', 'INVALID_RESULT',
            'RESULT_DATE_RANGE_MISMATCH', 'INVALID_ROWS', 'DUPLICATE_ROWS', 'ROWS_INCOMPLETE', 'EVIDENCE_WRITE_FAILED',
            'REST_ACCESS_BLOCKED', 'REST_INCOMPATIBLE', 'REST_INVALID_ARGUMENT', 'REST_RESOURCE_EXHAUSTED',
            'REST_TIMEOUT', 'REST_NOT_FOUND', 'REST_UNAVAILABLE', 'REST_FAILED'];
        return in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'REST_FAILED';
    }
}
