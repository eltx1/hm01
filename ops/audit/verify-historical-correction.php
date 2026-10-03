<?php

declare(strict_types=1);

/** Bounded, read-only verification. This file is streamed into the CURRENT app. */
final class HorusHistoricalCorrectionAudit
{
    public const OPERATION = '43c093f93251f353c7ba306e44d4c1e36a03e835bbd0d6ebe2463ae3fbe8cf45';
    public const DIGEST = '30f2ed07ba5408787949e883e2dfed3ffbec3c3aa53a08f5e2d2c16dd963e0a7';
    public const THROUGH = '2026-10-02';
    public const MONEY_FIELDS = ['gross_revenue_minor', 'demand_partner_deductions_minor',
        'invalid_traffic_adjustments_minor', 'other_adjustments_minor', 'net_revenue_minor',
        'publisher_earnings_minor', 'horus_earnings_minor', 'mcm_partner_earnings_minor'];
    public const COUNTER_FIELDS = ['ad_requests', 'matched_requests', 'unfilled_requests', 'impressions', 'clicks', 'video_starts', 'completed_views',
        'active_view_viewable_impressions', 'active_view_measurable_impressions', 'unfilled_impressions'];
    public const COUNT_KEYS = ['initial_daily_facts', 'sources', 'applied_windows', 'unique_receipts',
        'corrected_facts', 'blocked_facts', 'pending_facts', 'covered_facts', 'remaining_facts',
        'remaining_money_zero', 'remaining_money_nonzero', 'remaining_money_unknown', 'remaining_money_known_nonzero',
        'remaining_counters_zero', 'remaining_counters_nonzero', 'remaining_counters_unknown',
        'missing_facts', 'duplicate_facts', 'missing_receipts', 'duplicate_receipts', 'currency_issues',
        'hash_issues', 'provenance_issues', 'other_record_drift'];
    public const CHECK_KEYS = ['manifest_identity', 'manifest_digest', 'inventory_matches', 'coverage_complete',
        'receipts_match', 'corrected_hashes_match', 'remaining_hashes_match', 'remaining_money_valid',
        'currency_valid', 'provenance_valid', 'admin_reporting_parity', 'publisher_reporting_parity'];
    public const REASONS = ['NONE', 'BOOTSTRAP_FAILED', 'MANIFEST_UNAVAILABLE', 'MANIFEST_INVALID',
        'LOCK_UNAVAILABLE', 'SNAPSHOT_UNAVAILABLE', 'AUDIT_MISMATCH', 'AUDIT_FAILED'];

    public static function failure(string $reason): array
    {
        return ['schema_version' => 1, 'status' => 'FAILED',
            'reason' => in_array($reason, self::REASONS, true) && $reason !== 'NONE' ? $reason : 'AUDIT_FAILED',
            'counts' => array_fill_keys(self::COUNT_KEYS, 0), 'checks' => array_fill_keys(self::CHECK_KEYS, false)];
    }

    public static function hash(array $value): string
    {
        $canonical = static function (mixed $item) use (&$canonical): mixed {
            if (! is_array($item)) return $item;
            if (! array_is_list($item)) ksort($item);
            return array_map($canonical, $item);
        };
        return hash('sha256', json_encode($canonical($value), JSON_THROW_ON_ERROR));
    }

    /** Do not cast first: null, decimals, booleans, overflow and numeric junk are unknown. */
    public static function integer(mixed $value): ?int
    {
        if (is_int($value)) return $value;
        if (! is_string($value) || ! preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value)) return null;
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return $parsed === false ? null : $parsed;
    }

    public static function classify(array $fact, array $fields, bool $nonnegative = false): string
    {
        $nonzero = false;
        foreach ($fields as $field) {
            $value = self::integer($fact[$field] ?? null);
            if ($value === null || ($nonnegative && $value < 0)) return 'unknown';
            $nonzero = $nonzero || $value !== 0;
        }
        return $nonzero ? 'nonzero' : 'zero';
    }

    public static function anyKnownNonzero(array $fact, array $fields, bool $nonnegative = false): bool
    {
        foreach ($fields as $field) {
            $value = self::integer($fact[$field] ?? null);
            if ($value !== null && $value !== 0 && (! $nonnegative || $value > 0)) return true;
        }
        return false;
    }

    /** Existing files only; no creation, replacement, chmod, or exclusive writer lock. */
    public static function withManifest(string $directory, callable $reader): mixed
    {
        $path = $directory.'/'.self::OPERATION.'.json';
        $lockPath = $directory.'/'.self::OPERATION.'.lock';
        if (! is_dir($directory) || is_link($directory) || (fileperms($directory) & 0077) !== 0
            || ! is_file($path) || is_link($path) || (fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('MANIFEST_UNAVAILABLE');
        }
        if (! is_file($lockPath) || is_link($lockPath) || (fileperms($lockPath) & 0077) !== 0) {
            throw new RuntimeException('LOCK_UNAVAILABLE');
        }
        $lock = fopen($lockPath, 'r');
        if (! $lock) throw new RuntimeException('LOCK_UNAVAILABLE');
        try {
            if (! flock($lock, LOCK_SH | LOCK_NB)) throw new RuntimeException('LOCK_UNAVAILABLE');
            $stream = fopen($path, 'r');
            if (! $stream) throw new RuntimeException('MANIFEST_UNAVAILABLE');
            try {
                $bytes = stream_get_contents($stream, 8388609);
                if (! is_string($bytes) || strlen($bytes) > 8388608) throw new RuntimeException('MANIFEST_INVALID');
                $manifest = json_decode($bytes, true, 128, JSON_THROW_ON_ERROR);
                if (! is_array($manifest)) throw new RuntimeException('MANIFEST_INVALID');
            } finally { fclose($stream); }
            return $reader($manifest);
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public static function manifestDigest(array $manifest): string
    {
        $windows = [];
        foreach ($manifest['windows'] as $window) {
            if (isset($windows[$window['key']])) throw new RuntimeException('MANIFEST_INVALID');
            $windows[$window['key']] = $window;
        }
        $batch = [];
        foreach ($manifest['batch'] as $key) {
            if (! isset($windows[$key])) throw new RuntimeException('MANIFEST_INVALID');
            $window = $windows[$key];
            unset($window['state'], $window['receipt_id']);
            $batch[] = $window;
        }
        $payload = ['version' => 1, 'operation' => $manifest['operation'], 'actor_id' => $manifest['actor_id'],
            'coverage' => $manifest['batch_coverage'] ?? $manifest['coverage'],
            'inventory_digest' => $manifest['batch_inventory_digest'] ?? self::hash($manifest['records']), 'batch' => $batch];
        if (isset($manifest['contract'])) $payload['contract'] = $manifest['contract'];
        return self::hash($payload);
    }

    private static function equalHash(mixed $expected, mixed $actual): bool
    {
        return is_string($expected) && is_string($actual) && preg_match('/\A[a-f0-9]{64}\z/D', $expected)
            && hash_equals($expected, $actual);
    }

    private static function snapshotValid(array $snapshot, mixed $expected): bool
    {
        $fingerprint = $snapshot['fingerprint'] ?? null;
        unset($snapshot['fingerprint']);
        return self::equalHash($expected, $fingerprint) && self::equalHash($fingerprint, self::hash($snapshot));
    }

    public static function evidenceHash(array $candidate): string
    {
        return self::hash([$candidate['context'], $candidate['snapshot'], $candidate['query_hash'],
            $candidate['job'], $candidate['proposal'], $candidate['digest']]);
    }

    /** Pure evidence analyzer. Expected override exists only for synthetic library tests. */
    public static function inspect(array $manifest, array $inventory, array $facts, array $receipts,
        array $candidates, array $parity, ?array $expected = null): array
    {
        $expected ??= ['operation' => self::OPERATION, 'digest' => self::DIGEST, 'through' => self::THROUGH];
        $result = self::failure('AUDIT_MISMATCH');
        $counts = &$result['counts']; $checks = &$result['checks'];
        foreach (['initial_counts', 'records', 'coverage', 'windows', 'batch'] as $key) {
            if (! is_array($manifest[$key] ?? null)) throw new RuntimeException('MANIFEST_INVALID');
        }
        $checks['manifest_identity'] = ($manifest['version'] ?? null) === 2
            && ($manifest['operation'] ?? null) === $expected['operation']
            && ($manifest['contract']['operation'] ?? null) === $expected['operation']
            && ($manifest['contract']['through'] ?? null) === $expected['through']
            && ($manifest['contract']['schema_version'] ?? null) === 1;
        $checks['manifest_digest'] = self::equalHash($expected['digest'], $manifest['digest'] ?? null)
            && self::equalHash($expected['digest'], self::manifestDigest($manifest));
        $counts['initial_daily_facts'] = max(0, self::integer($manifest['initial_counts']['daily_facts'] ?? null) ?? 0);
        $counts['sources'] = count(array_filter(array_keys($inventory['records']), fn ($key) => str_starts_with($key, 'source:')));
        $current = $original = $receiptById = $candidateById = $corrected = [];
        $batchRecords = $manifest['records'];
        foreach ($facts as $row) {
            $id = $row['fact']['id'];
            if (isset($current[$id])) $counts['duplicate_facts']++;
            $current[$id] = $row;
            if (($row['fact']['currency'] ?? null) !== 'USD') $counts['currency_issues']++;
        }
        foreach ($candidates as $candidate) {
            if (isset($candidateById[$candidate['id']])) $counts['provenance_issues']++;
            $candidateById[$candidate['id']] = $candidate;
            $candidateDigest = self::hash(['version' => 1, 'candidate_id' => $candidate['id'], 'actor_id' => $candidate['actor_id'],
                'context' => $candidate['context'], 'snapshot' => $candidate['snapshot']['fingerprint'],
                'query_hash' => $candidate['query_hash'], 'job' => $candidate['job'], 'proposal' => $candidate['proposal']]);
            if (! self::equalHash($candidate['digest'] ?? null, $candidateDigest)
                || ! self::snapshotValid($candidate['snapshot'], $candidate['snapshot']['fingerprint'])) $counts['hash_issues']++;
            foreach ($candidate['snapshot']['facts'] ?? [] as $row) {
                $original[$row['fact']['id']][self::hash([$row['fact'], $row['dimension']])] = true;
            }
        }
        foreach ($receipts as $receipt) {
            $id = $receipt['attributes']['id'];
            if (isset($receiptById[$id])) $counts['duplicate_receipts']++;
            $receiptById[$id] = $receipt;
        }
        $seenReceipts = $seenCorrections = [];
        $correctedHashIssues = $remainingHashIssues = 0;
        foreach ($manifest['windows'] as $window) {
            foreach ($window['ancestor_evidence'] ?? [] as $evidence) {
                $ancestor = $candidateById[$evidence['candidate_id'] ?? ''] ?? null;
                if (! $ancestor || ! self::equalHash($ancestor['digest'] ?? null, $evidence['digest'] ?? null)
                    || ! self::equalHash(self::evidenceHash($ancestor), $evidence['evidence_hash'] ?? null)) $counts['provenance_issues']++;
            }
            if (($window['state'] ?? null) !== 'APPLIED') continue;
            $counts['applied_windows']++;
            $receiptId = $window['receipt_id'] ?? '';
            if (isset($seenReceipts[$receiptId])) $counts['duplicate_receipts']++;
            $seenReceipts[$receiptId] = true;
            $receipt = $receiptById[$receiptId] ?? null;
            $candidate = $candidateById[$window['candidate_id'] ?? ''] ?? null;
            if (! $receipt) { $counts['missing_receipts']++; continue; }
            $attributes = $receipt['attributes'];
            if (isset($seenCorrections[$attributes['correction_id']])) $counts['duplicate_receipts']++;
            $seenCorrections[$attributes['correction_id']] = true;
            if (! self::equalHash($manifest['records']['receipt:'.$receiptId] ?? null, self::hash($attributes))
                || ! self::snapshotValid($receipt['before'], $attributes['before_hash'] ?? null)
                || ! self::snapshotValid($receipt['after'], $attributes['after_hash'] ?? null)) $counts['hash_issues']++;
            if (in_array($window['key'], $manifest['batch'], true)) {
                unset($batchRecords['receipt:'.$receiptId]);
                foreach ($receipt['before']['facts'] ?? [] as $row) {
                    $batchRecords['daily:'.$row['fact']['id']] = self::hash([$row['fact'], $row['dimension']]);
                }
            }
            $ids = array_column(array_column($receipt['after']['facts'] ?? [], 'fact'), 'id');
            $beforeIds = array_column(array_column($receipt['before']['facts'] ?? [], 'fact'), 'id');
            $expectedIds = $window['fact_ids']; sort($ids); sort($beforeIds); sort($expectedIds);
            if ($ids !== $expectedIds || $beforeIds !== $expectedIds || ! $candidate
                || ($candidate['status'] ?? null) !== 'APPLIED'
                || $attributes['correction_id'] !== $window['candidate_id']
                || ($candidate['actor_id'] ?? null) !== ($manifest['actor_id'] ?? null)
                || ($attributes['approved_by'] ?? null) !== ($manifest['actor_id'] ?? null)
                || ! self::equalHash($attributes['digest'] ?? null, $window['candidate_digest'] ?? null)
                || ! self::equalHash($attributes['digest'] ?? null, $candidate['digest'] ?? null)) $counts['provenance_issues']++;
            if ($candidate) {
                foreach (['site_id', 'source_connection_id'] as $field) {
                    if (($candidate['context'][$field] ?? null) !== ($window[$field] ?? null)
                        || ($receipt['context'][$field] ?? null) !== ($window[$field] ?? null)) $counts['provenance_issues']++;
                }
                if (($receipt['context']['currency'] ?? null) !== 'USD'
                    || ($candidate['context']['currency'] ?? null) !== 'USD') $counts['currency_issues']++;
            }
            foreach ($receipt['after']['facts'] ?? [] as $row) {
                $id = $row['fact']['id'];
                if (isset($corrected[$id])) $counts['duplicate_facts']++;
                $corrected[$id] = $receiptId;
                $hash = self::hash([$row['fact'], $row['dimension']]);
                $present = $current[$id] ?? null;
                if (! $present || ! self::equalHash($hash, self::hash([$present['fact'], $present['dimension']]))) $correctedHashIssues++;
                $coverage = $manifest['coverage']['daily:'.$id] ?? [];
                if (($coverage['state'] ?? null) !== 'CORRECTED' || ($coverage['receipt_id'] ?? null) !== $receiptId
                    || ! self::equalHash($coverage['receipt_digest'] ?? null, $attributes['digest'])
                    || ($inventory['coverage']['daily:'.$id]['state'] ?? null) !== 'CORRECTED') $counts['provenance_issues']++;
                if (($row['fact']['currency'] ?? null) !== 'USD') $counts['currency_issues']++;
            }
        }
        $checks['manifest_digest'] = $checks['manifest_digest'] && count($manifest['batch']) > 0
            && self::equalHash($manifest['batch_inventory_digest'] ?? null, self::hash($batchRecords));
        $counts['unique_receipts'] = count($receiptById);
        if (array_diff_key($receiptById, $seenReceipts)) $counts['provenance_issues'] += count(array_diff_key($receiptById, $seenReceipts));
        $counts['corrected_facts'] = count($corrected);
        $coverageIds = [];
        foreach ($manifest['coverage'] as $key => $coverage) {
            if (! str_starts_with($key, 'daily:')) continue;
            $id = substr($key, 6); $coverageIds[$id] = true;
            $counts['covered_facts']++;
            $present = $current[$id] ?? null;
            if (! $present) $counts['missing_facts']++;
            if (isset($corrected[$id])) continue;
            $counts['remaining_facts']++;
            if (($coverage['state'] ?? null) === 'BLOCKED' && ($coverage['reason'] ?? null) === 'NO_EXACT_SITE_ROW') $counts['blocked_facts']++;
            else $counts['pending_facts']++;
            // These remain unknown Google observations even when stored ledger fields are zero.
            $fact = $present['fact'] ?? [];
            $counts['remaining_money_'.self::classify($fact, self::MONEY_FIELDS)]++;
            if (self::anyKnownNonzero($fact, self::MONEY_FIELDS)) $counts['remaining_money_known_nonzero']++;
            // Known activity and missing optional counters may coexist; these two counts overlap.
            $counterClass = self::classify($fact, self::COUNTER_FIELDS, true);
            if ($counterClass !== 'nonzero') $counts['remaining_counters_'.$counterClass]++;
            if (self::anyKnownNonzero($fact, self::COUNTER_FIELDS, true)) $counts['remaining_counters_nonzero']++;
            $hash = $present ? self::hash([$present['fact'], $present['dimension']]) : null;
            if (! self::equalHash($manifest['records'][$key] ?? null, $hash)
                || ! self::equalHash($inventory['records'][$key] ?? null, $hash)
                || ! $hash || count($original[$id] ?? []) !== 1 || ! isset($original[$id][$hash])) $remainingHashIssues++;
            if (! $present || ! is_array($present['dimension'] ?? null)) $counts['provenance_issues']++;
            $parents = array_values(array_filter($manifest['windows'], fn ($window) => $window['key'] === ($coverage['parent_window'] ?? null)));
            $parent = count($parents) === 1 ? $parents[0] : [];
            $candidate = $candidateById[$parent['candidate_id'] ?? ''] ?? [];
            $date = $coverage['date'] ?? '';
            $gap = $parent['partition']['unresolved'][$date] ?? [];
            if (($parent['state'] ?? null) !== 'PARTITIONED' || ($candidate['status'] ?? null) !== 'BLOCKED'
                || ($candidate['job']['status'] ?? null) !== 'COMPLETED'
                || ($gap['fact_id'] ?? null) !== $id || ($gap['reason'] ?? null) !== 'NO_EXACT_SITE_ROW'
                || $date !== substr($fact['report_date'] ?? '', 0, 10)
                || isset($candidate['job']['result']['days'][$date])
                || ($candidate['actor_id'] ?? null) !== ($manifest['actor_id'] ?? null)
                || ! self::equalHash($candidate['digest'] ?? null, $parent['candidate_digest'] ?? null)
                || ! $candidate || ! self::equalHash(self::evidenceHash($candidate), $parent['partition']['evidence_hash'] ?? null)) $counts['provenance_issues']++;
        }
        $counts['hash_issues'] += $correctedHashIssues + $remainingHashIssues;
        foreach (array_unique([...array_keys($manifest['records']), ...array_keys($inventory['records'])]) as $key) {
            if (self::equalHash($manifest['records'][$key] ?? null, $inventory['records'][$key] ?? null)) continue;
            if (str_starts_with($key, 'daily:') || str_starts_with($key, 'hourly:') || str_starts_with($key, 'receipt:')) $counts['hash_issues']++;
            else $counts['other_record_drift']++;
        }
        $recordIds = [];
        foreach (array_keys($manifest['records']) as $key) if (str_starts_with($key, 'daily:')) $recordIds[substr($key, 6)] = true;
        $checks['inventory_matches'] = $counts['hash_issues'] === 0 && $counts['other_record_drift'] === 0;
        $checks['coverage_complete'] = $counts['initial_daily_facts'] === 96 && $counts['covered_facts'] === 96
            && $counts['corrected_facts'] === 35 && $counts['remaining_facts'] === 61 && $counts['blocked_facts'] === 61
            && $counts['pending_facts'] === 0 && $counts['sources'] === 3
            && ($manifest['initial_counts']['sources'] ?? null) === 3
            && ($manifest['initial_counts']['hourly_facts'] ?? null) === 0
            && ($manifest['initial_counts']['forward_facts'] ?? null) === 0
            && ($manifest['initial_counts']['corrected_facts'] ?? null) === 0
            && count($manifest['coverage']) === $counts['covered_facts']
            && count(array_filter(array_keys($manifest['records']), fn ($key) => str_starts_with($key, 'hourly:'))) === 0
            && $counts['missing_facts'] === 0 && $counts['duplicate_facts'] === 0
            && ! array_diff_key($current, $coverageIds) && ! array_diff_key($corrected, $coverageIds)
            && ! array_diff_key($recordIds, $coverageIds) && ! array_diff_key($coverageIds, $recordIds);
        $checks['receipts_match'] = $counts['applied_windows'] === 6 && $counts['unique_receipts'] === 6
            && $counts['missing_receipts'] === 0 && $counts['duplicate_receipts'] === 0 && $counts['hash_issues'] === 0;
        $checks['corrected_hashes_match'] = $correctedHashIssues === 0 && $counts['corrected_facts'] === 35;
        $checks['remaining_hashes_match'] = $remainingHashIssues === 0 && $counts['remaining_facts'] === 61;
        $checks['remaining_money_valid'] = $counts['remaining_money_unknown'] === 0;
        $checks['currency_valid'] = $counts['currency_issues'] === 0;
        $checks['provenance_valid'] = $counts['provenance_issues'] === 0;
        $checks['admin_reporting_parity'] = ($parity['admin'] ?? false) === true;
        $checks['publisher_reporting_parity'] = ($parity['publisher'] ?? false) === true;
        if (! in_array(false, $checks, true)) { $result['status'] = 'OK'; $result['reason'] = 'NONE'; }
        return $result;
    }

    /** Each service is checked against its own full reporting filters, never the operation subset. */
    public static function reportingParity(array $facts): array
    {
        $sites = $publishers = [];
        foreach ($facts as $row) {
            $date = substr($row['fact']['report_date'], 0, 10);
            foreach (['sites' => 'site_id', 'publishers' => 'publisher_id'] as $group => $field) {
                $id = $row['dimension'][$field] ?? null;
                if (! is_string($id)) return ['admin' => false, 'publisher' => false];
                ${$group}[$id][] = $date;
            }
        }
        $admin = $publisherParity = count($sites) > 0 && count($publishers) > 0
            && strtoupper((string) config('reporting.canonical_currency', 'USD')) === 'USD';
        foreach ($sites as $id => $dates) {
            $site = App\Models\Site::withoutGlobalScopes()->find($id);
            if (! $site) { $admin = false; continue; }
            $rows = Illuminate\Support\Facades\DB::table('daily_reports as f')->join('report_dimensions as d', 'd.id', '=', 'f.report_dimension_id')
                ->where('d.site_id', $id)->where('f.currency', 'USD')->where('f.finality', 'FINALIZED')
                ->whereDate('f.report_date', '>=', min($dates))->whereDate('f.report_date', '<=', max($dates))->get(['f.*']);
            $summary = app(App\Services\Reporting\AdminWebsitePerformanceService::class)->summary($site, min($dates), max($dates));
            foreach (['gross_revenue_minor', 'publisher_earnings_minor', 'horus_earnings_minor', 'impressions', 'clicks'] as $field) {
                $admin = $admin && self::sumMatches($rows, $field, $summary[$field] ?? null);
            }
            $admin = $admin && $summary['available'] === $rows->isNotEmpty();
        }
        foreach ($publishers as $id => $dates) {
            $publisher = App\Models\Publisher::withoutGlobalScopes()->find($id);
            if (! $publisher) { $publisherParity = false; continue; }
            $rows = Illuminate\Support\Facades\DB::table('daily_reports as f')->join('report_dimensions as d', 'd.id', '=', 'f.report_dimension_id')
                ->where('d.publisher_id', $id)->where('d.organization_id', $publisher->organization_id)
                ->where('f.organization_id', $publisher->organization_id)->where('f.currency', 'USD')
                ->whereIn('f.finality', ['ESTIMATED', 'FINALIZED'])->whereDate('f.report_date', '>=', min($dates))
                ->whereDate('f.report_date', '<=', max($dates))->get(['f.*']);
            $summary = app(App\Services\Reporting\PublisherPerformanceService::class)->summary($publisher, min($dates), max($dates));
            $publisherParity = $publisherParity && self::sumMatches($rows, 'publisher_earnings_minor', $summary['earnings_minor'] ?? null)
                && self::sumMatches($rows->where('finality', 'ESTIMATED'), 'publisher_earnings_minor', $summary['estimated_minor'] ?? null)
                && self::sumMatches($rows->where('finality', 'FINALIZED'), 'publisher_earnings_minor', $summary['finalized_minor'] ?? null)
                && self::sumMatches($rows, 'impressions', $summary['impressions'] ?? null)
                && self::sumMatches($rows, 'clicks', $summary['clicks'] ?? null)
                && $summary['available'] === $rows->isNotEmpty();
        }
        return ['admin' => $admin, 'publisher' => $publisherParity];
    }

    private static function sumMatches(iterable $rows, string $field, mixed $expected): bool
    {
        $sum = 0;
        foreach ($rows as $row) {
            $value = self::integer($row->{$field} ?? null);
            if ($value === null || ! is_int($sum + $value)) return false;
            $sum += $value;
        }
        return is_int($expected) && $sum === $expected;
    }

    public static function withReadOnlySnapshot(object $connection, callable $reader): mixed
    {
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() !== 0) throw new RuntimeException('SNAPSHOT_UNAVAILABLE');
        $connection->useWriteConnectionWhenReading();
        $connection->setReconnector(static fn () => throw new RuntimeException('SNAPSHOT_UNAVAILABLE'));
        $pdo = $connection->getPdo();
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try { return $reader(); } finally { if ($pdo->inTransaction()) $pdo->rollBack(); }
    }

    public static function configureReadOnlyRuntime(object $app): void
    {
        // SettingsServiceProvider uses Cache::remember during boot. Keep that cache process-local.
        $app->make('config')->set('cache.default', 'array');
        $app->make('config')->set('cache.stores.array', ['driver' => 'array', 'serialize' => false]);
    }

    public static function readCurrent(array $manifest, ?callable $beforeRead = null): array
    {
        return self::withReadOnlySnapshot(Illuminate\Support\Facades\DB::connection(), static function () use ($manifest, $beforeRead): array {
            if ($beforeRead) $beforeRead();
            $inventory = app(App\Services\Reporting\GamHistoricalInventory::class)->discover(self::THROUGH);
            $ids = [];
            foreach (array_keys($manifest['coverage'] ?? []) as $key) if (str_starts_with($key, 'daily:')) $ids[] = substr($key, 6);
            $facts = App\Models\DailyReport::withoutGlobalScopes()->with('dimension')->whereIn('id', $ids)->orderBy('id')->get()
                ->map(fn ($fact) => ['fact' => $fact->getAttributes(), 'dimension' => $fact->dimension?->getAttributes()])->all();
            $candidateIds = array_values(array_filter(array_column($manifest['windows'] ?? [], 'candidate_id')));
            $candidates = App\Models\GamRevenueCorrection::whereIn('id', $candidateIds)->get()->map(fn ($candidate) => $candidate->getAttributes())->all();
            foreach ($candidates as &$candidate) foreach (['context', 'snapshot', 'job', 'proposal'] as $field) {
                $candidate[$field] = json_decode($candidate[$field], true, 128, JSON_THROW_ON_ERROR);
            }
            unset($candidate);
            $receiptIds = array_values(array_filter(array_column($manifest['windows'] ?? [], 'receipt_id')));
            $receipts = App\Models\GamRevenueCorrectionReceipt::whereIn('id', $receiptIds)->orWhereIn('correction_id', $candidateIds)->get()
                ->map(fn ($receipt) => ['attributes' => $receipt->getAttributes(), 'before' => $receipt->before,
                    'after' => $receipt->after, 'context' => $receipt->context])->all();
            return self::inspect($manifest, $inventory, $facts, $receipts, $candidates, self::reportingParity($facts));
        });
    }
}

// Unit tests load the exact same analyzer without bootstrapping or remote access.
if (defined('HORUS_HISTORICAL_AUDIT_LIBRARY_ONLY')) return;
ini_set('display_errors', '0');
ini_set('log_errors', '0');
$emitted = false;
ob_start(static fn (string $output): string => '');
$emit = static function (array $result) use (&$emitted): void {
    if ($emitted) return;
    $emitted = true;
    while (ob_get_level() > 0) ob_end_clean();
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
};
register_shutdown_function(static function () use ($emit): void { $emit(HorusHistoricalCorrectionAudit::failure('AUDIT_FAILED')); });
set_error_handler(static function (): never { throw new RuntimeException('AUDIT_FAILED'); });
$stage = 'BOOTSTRAP_FAILED';
try {
    $root = getcwd();
    // Avoid any framework cache construction, exception logging, or command execution.
    foreach (['vendor/autoload.php', 'bootstrap/app.php', 'bootstrap/cache/packages.php', 'bootstrap/cache/services.php'] as $file) {
        if (! is_file($root.'/'.$file)) throw new RuntimeException('BOOTSTRAP_FAILED');
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->bootstrapWith([
        Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        Illuminate\Foundation\Bootstrap\RegisterFacades::class,
        Illuminate\Foundation\Bootstrap\SetRequestForConsole::class,
    ]);
    HorusHistoricalCorrectionAudit::configureReadOnlyRuntime($app);
    $app->bootstrapWith([Illuminate\Foundation\Bootstrap\RegisterProviders::class]);
    $stage = 'AUDIT_FAILED';
    $result = HorusHistoricalCorrectionAudit::withManifest(storage_path('app/private/gam-historical-operations'),
        static fn (array $manifest): array => HorusHistoricalCorrectionAudit::readCurrent($manifest,
            static fn () => $app->bootstrapWith([Illuminate\Foundation\Bootstrap\BootProviders::class])));
} catch (Throwable $error) {
    $reason = in_array($error->getMessage(), HorusHistoricalCorrectionAudit::REASONS, true) ? $error->getMessage() : $stage;
    $result = HorusHistoricalCorrectionAudit::failure($reason);
}
$emit($result);
exit($result['status'] === 'OK' ? 0 : 1);
