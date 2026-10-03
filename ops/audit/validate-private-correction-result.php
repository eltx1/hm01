<?php

declare(strict_types=1);

// Standalone runner-side boundary. Do not bootstrap the application here.
// Only a reconstructed allowlisted object may reach public workflow output.
ini_set('display_errors', '0');

try {
    $operation = $argv[2] ?? '';
    $expectedDigest = $argv[3] ?? '';
    $transportStatus = $argv[4] ?? '0';
    $isToken = static fn (mixed $value): bool => is_string($value) && preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1;
    if (! $isToken($operation) || ($expectedDigest !== '' && ! $isToken($expectedDigest)) || ! in_array($transportStatus, ['0', '10'], true)) {
        exit(2);
    }

    $path = $argv[1] ?? '';
    if (! is_file($path) || is_link($path) || filesize($path) > 16384) {
        exit(2);
    }
    $result = json_decode((string) file_get_contents($path), false, 16, JSON_THROW_ON_ERROR);
    $keysEqual = static function (object $value, array $expected): bool {
        $keys = array_keys(get_object_vars($value));
        sort($keys);
        sort($expected);

        return $keys === $expected;
    };
    if (! $result instanceof stdClass || ! $keysEqual($result, ['schema_version', 'outcome', 'reason', 'operation', 'digest', 'counts', 'reasons'])) {
        exit(2);
    }
    $reasonCodes = [
        'NONE', 'INVALID_INPUT', 'ACTOR_UNVERIFIED', 'OPERATION_MISSING', 'OPERATION_CONFLICT',
        'STALE_INVENTORY', 'DIGEST_MISMATCH', 'REVIEW_REQUIRED', 'CANDIDATE_BLOCKED',
        'CANDIDATE_EXPIRED', 'CANDIDATE_UNCERTAIN', 'CAPACITY_REACHED', 'OPERATION_FAILED',
        'CONFIGURATION_INVALID', 'TRUST_PROOF_INVALID', 'TRANSPORT_FAILED', 'RELEASE_MISMATCH',
        'DEPLOY_LOCK_BUSY', 'COMMAND_FAILED', 'RESULT_INVALID', 'INTERNAL_ERROR',
    ];
    $countKeys = [
        'sources', 'daily_facts', 'hourly_facts', 'forward_facts', 'windows', 'eligible_windows',
        'blocked_facts', 'corrected_facts', 'pending', 'ready', 'applied', 'blocked',
    ];
    $aggregateReasons = [
        'HOURLY_FACTS', 'UNBOUND_SOURCE', 'INACTIVE_BINDING', 'UNVERIFIED_SCOPE', 'OUTSIDE_BINDING',
        'FORWARD_SCOPE', 'INCOMPLETE_DAY', 'MULTIPLE_FACTS', 'MIXED_IDENTITY', 'RECEIPT_MISMATCH',
        'CANDIDATE_BLOCKED', 'CANDIDATE_EXPIRED', 'CANDIDATE_UNCERTAIN', 'REVIEW_REQUIRED',
    ];
    if ($result->schema_version !== 1 || ! in_array($result->outcome, ['OK', 'BLOCKED', 'FAILED'], true)
        || ! in_array($result->reason, $reasonCodes, true) || $result->operation !== $operation
        || ($result->digest !== null && ! $isToken($result->digest))
        || ($expectedDigest !== '' && $result->outcome === 'OK' && $result->digest !== $expectedDigest)
        || ($expectedDigest !== '' && $result->digest !== null && ! hash_equals($expectedDigest, $result->digest)
            && ! ($result->outcome === 'BLOCKED' && $result->reason === 'DIGEST_MISMATCH'))
        || ($transportStatus === '10' && $result->outcome !== 'FAILED')
        || ! $result->counts instanceof stdClass || ! $keysEqual($result->counts, $countKeys)
        || ! $result->reasons instanceof stdClass) {
        exit(2);
    }
    $counts = [];
    foreach ($countKeys as $key) {
        $value = $result->counts->{$key};
        if (! is_int($value) || $value < 0 || $value > 9007199254740991) {
            exit(2);
        }
        $counts[$key] = $value;
    }
    $reasons = [];
    foreach (get_object_vars($result->reasons) as $key => $value) {
        if (! in_array($key, $aggregateReasons, true) || ! is_int($value) || $value < 0 || $value > 9007199254740991) {
            exit(2);
        }
        $reasons[$key] = $value;
    }
    ksort($reasons);
    echo json_encode([
        'schema_version' => 1,
        'outcome' => $result->outcome,
        'reason' => $result->reason,
        'operation' => $operation,
        'digest' => $result->digest,
        'counts' => $counts,
        'reasons' => (object) $reasons,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($result->outcome === 'FAILED' ? 1 : 0);
} catch (Throwable) {
    // Never emit exception messages, paths, malformed input, or bootstrap output.
    exit(2);
}
