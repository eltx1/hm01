<?php

declare(strict_types=1);

/** Read only the private PR242 evidence. No framework, network, credential or database access. */
final class HorusUnfilledEvidence
{
    public const RELEASE = '7be80e9dff9d53cb82fe9dea9c007ac749adf1f4';
    public const FROM = 1791060800; // 2026-10-03 20:53:20 UTC
    public const TO = 1791060825;   // 2026-10-03 20:53:45 UTC
    public const REASONS = ['SERVICE_DISABLED', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT', 'IAM_PERMISSION_DENIED',
        'CONSUMER_INVALID', 'PROJECT_DELETED', 'BILLING_DISABLED', 'SECURITY_POLICY_VIOLATED',
        'ACCESS_TOKEN_EXPIRED', 'ACCESS_TOKEN_TYPE_UNSUPPORTED', 'CREDENTIALS_MISSING'];

    public static function inspect(string $root): array
    {
        $marker = @file_get_contents($root.'/.horus-release');
        if (! is_string($marker) || ! in_array('release_id='.self::RELEASE, explode("\n", trim($marker)), true)) {
            throw new RuntimeException('SOURCE_RELEASE_MISMATCH');
        }
        $storage = realpath($root.'/storage/app/private');
        if ($storage === false) throw new RuntimeException('EVIDENCE_NOT_FOUND');
        $base = $storage.'/gam-unfilled-probe';
        if (is_link($base) || ! is_dir($base)) throw new RuntimeException('EVIDENCE_NOT_FOUND');
        $entries = scandir($base);
        if ($entries === false || count($entries) > 102) throw new RuntimeException('EVIDENCE_CAPACITY');
        $matches = [];
        foreach ($entries as $name) {
            if (! preg_match('/^[a-f0-9]{24}$/D', $name)) continue;
            $path = $base.'/'.$name;
            if (is_link($path) || ! is_dir($path)) continue;
            $time = filemtime($path);
            if ($time >= self::FROM && $time <= self::TO) $matches[] = $path;
        }
        if (count($matches) !== 1) throw new RuntimeException(count($matches) === 0 ? 'EVIDENCE_NOT_FOUND' : 'EVIDENCE_AMBIGUOUS');
        $directory = $matches[0];
        $allowed = ['.', '..', 'soap-0.jsonl', 'soap-1.jsonl', 'soap-2.jsonl', 'rest-0.jsonl', 'rest-1.jsonl', 'rest-2.jsonl'];
        $files = scandir($directory);
        if (! is_array($files) || array_diff($files, $allowed) || array_diff($allowed, $files)) throw new RuntimeException('EVIDENCE_INVALID');
        $soap = []; $rest = [];
        for ($index = 0; $index < 3; $index++) {
            $soap[$index] = self::read($directory.'/soap-'.$index.'.jsonl');
            $rest[$index] = self::read($directory.'/rest-'.$index.'.jsonl');
        }
        return self::classify($soap, $rest);
    }

    private static function read(string $path): array
    {
        if (is_link($path) || ! is_file($path) || filesize($path) > 8 * 1024 * 1024
            || filemtime($path) < self::FROM || filemtime($path) > self::TO) throw new RuntimeException('EVIDENCE_INVALID');
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($lines) || count($lines) > 64) throw new RuntimeException('EVIDENCE_INVALID');
        $records = [];
        foreach ($lines as $line) {
            $value = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($value)) throw new RuntimeException('EVIDENCE_INVALID');
            $records[] = $value;
        }
        return $records;
    }

    /** Public output is reconstructed exclusively from fixed enums and booleans. */
    public static function classify(array $soap, array $rest): array
    {
        if (count($soap) !== 3 || count($rest) !== 3) throw new RuntimeException('EVIDENCE_INVALID');
        $summaries = array_values(array_filter($soap[0], static fn ($r) => isset($r['summary'])));
        if (count($summaries) !== 1) throw new RuntimeException('EVIDENCE_INVALID');
        $summary = $summaries[0]['summary'];
        if (($summary['metric'] ?? null) !== 'TOTAL_INVENTORY_LEVEL_UNFILLED_IMPRESSIONS'
            || ($summary['bindings'] ?? null) !== 3 || ($summary['schema_version'] ?? null) !== 1
            || ($summary['scope'] ?? null) !== 'AD_UNIT_AND_EXACT_SITE'
            || ($summary['period'] ?? null) !== 'LAST_SEVEN_COMPLETE_DAYS'
            || count($summary['probes'] ?? []) !== 3 || count($summary['rest']['probes'] ?? []) !== 3) throw new RuntimeException('EVIDENCE_INVALID');
        $results = [];
        for ($index = 0; $index < 3; $index++) {
            $s = $summary['probes'][$index]; $r = $summary['rest']['probes'][$index];
            if (($s['query_status'] ?? null) !== 'COMPLETED' || ($s['valid_csv'] ?? null) !== true
                || ($s['exact_site_observed'] ?? null) !== false || ($s['nonmatching_site_observed'] ?? null) !== true
                || ($r['query_status'] ?? null) !== 'ACCESS_BLOCKED' || ($r['reason'] ?? null) !== 'REST_ACCESS_BLOCKED'
                || ($r['definition_status'] ?? null) !== 'NOT_SELECTED') throw new RuntimeException('EVIDENCE_INVALID');
            $results[] = ['soap_site_categories' => self::soapCategories($soap[$index]), ...self::restCause($rest[$index])];
        }
        return ['schema_version' => 1, 'source' => 'PR242_SAVED_EVIDENCE', 'new_google_requests' => false, 'bindings' => $results];
    }

    private static function soapCategories(array $records): array
    {
        $csvs = array_values(array_filter($records, static fn ($r) => isset($r['csv'])));
        if (count($csvs) !== 1 || ! is_string($csvs[0]['csv'])) throw new RuntimeException('EVIDENCE_INVALID');
        $stream = fopen('php://temp', 'w+'); fwrite($stream, $csvs[0]['csv']); rewind($stream);
        $categories = [];
        try {
            $headers = fgetcsv($stream, escape: '');
            if (! is_array($headers) || count(array_unique($headers)) !== count($headers)) throw new RuntimeException('EVIDENCE_INVALID');
            $position = array_search('Dimension.SITE_NAME', $headers, true);
            if ($position === false) throw new RuntimeException('EVIDENCE_INVALID');
            while (($row = fgetcsv($stream, escape: '')) !== false) {
                if ($row === [null]) continue;
                if (count($row) !== count($headers)) throw new RuntimeException('EVIDENCE_INVALID');
                $label = strtolower(trim($row[$position]));
                $category = match ($label) {
                    '(not applicable)' => 'NOT_APPLICABLE', '(unknown)', 'unknown' => 'UNKNOWN', '' => 'EMPTY',
                    default => filter_var(rtrim($label, '.'), FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                        && str_contains($label, '.') ? 'OTHER_HOSTNAME' : 'OTHER_LABEL',
                };
                $categories[$category] = true;
            }
        } finally { fclose($stream); }
        if ($categories === []) throw new RuntimeException('EVIDENCE_INVALID');
        $result = array_keys($categories); sort($result); return $result;
    }

    private static function restCause(array $records): array
    {
        $stage = 'UNKNOWN'; $status = 'UNKNOWN'; $reasons = []; $hasError = false;
        foreach ($records as $record) {
            if (isset($record['request'])) {
                $request = $record['request'];
                if ($hasError || ($request['method'] ?? null) !== 'GET' || ! is_string($request['path'] ?? null)) throw new RuntimeException('EVIDENCE_INVALID');
                $stage = match (true) {
                    (bool) preg_match('~^networks/[0-9]+$~D', $request['path']) => 'GET_NETWORK',
                    (bool) preg_match('~^networks/[0-9]+/reports$~D', $request['path']) => 'LIST_REPORTS',
                    default => throw new RuntimeException('EVIDENCE_INVALID'),
                };
            }
            if (isset($record['response']['error'])) {
                if ($hasError || $stage === 'UNKNOWN') throw new RuntimeException('EVIDENCE_INVALID');
                $error = $record['response']['error']; $hasError = true;
                $status = in_array($error['status'] ?? null, ['PERMISSION_DENIED', 'UNAUTHENTICATED'], true)
                    ? $error['status'] : 'UNKNOWN';
                foreach ($error['details'] ?? [] as $detail) {
                    if (! is_array($detail)) continue;
                    if (($detail['@type'] ?? '') === 'type.googleapis.com/google.rpc.ErrorInfo'
                        && ($detail['domain'] ?? '') === 'googleapis.com'
                        && in_array($detail['reason'] ?? '', self::REASONS, true)) $reasons[$detail['reason']] = true;
                }
            }
        }
        $reasonList = array_keys($reasons); sort($reasonList);
        return ['rest_stage' => $stage, 'rest_status' => $status, 'structured_error_present' => $hasError,
            'rest_reasons' => $reasonList === [] ? ['UNCLASSIFIED'] : $reasonList];
    }
}

if (defined('HORUS_UNFILLED_EVIDENCE_LIBRARY_ONLY')) return;
ini_set('display_errors', '0');
try {
    echo json_encode(HorusUnfilledEvidence::inspect(getcwd()), JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    $allowed = ['SOURCE_RELEASE_MISMATCH', 'EVIDENCE_NOT_FOUND', 'EVIDENCE_CAPACITY', 'EVIDENCE_AMBIGUOUS', 'EVIDENCE_INVALID'];
    $reason = in_array($error->getMessage(), $allowed, true) ? $error->getMessage() : 'EVIDENCE_INVALID';
    echo json_encode(['schema_version' => 1, 'source' => 'PR242_SAVED_EVIDENCE', 'reason' => $reason], JSON_THROW_ON_ERROR).PHP_EOL;
}
