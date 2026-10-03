<?php

namespace Tests\Feature;

use HorusHistoricalCorrectionAudit as Audit;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Synthetic records only; database fixtures use the isolated test connection. */
final class HistoricalCorrectionReadOnlyAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        if (! defined('HORUS_HISTORICAL_AUDIT_LIBRARY_ONLY')) define('HORUS_HISTORICAL_AUDIT_LIBRARY_ONLY', true);
        require_once dirname(__DIR__, 2).'/ops/audit/verify-historical-correction.php';
    }

    public function test_signed_money_classification_checks_all_eight_fields_without_netting(): void
    {
        $zero = array_fill_keys(Audit::MONEY_FIELDS, '0');
        $this->assertSame('zero', Audit::classify($zero, Audit::MONEY_FIELDS));
        foreach (Audit::MONEY_FIELDS as $field) {
            $this->assertSame('nonzero', Audit::classify(array_replace($zero, [$field => -1]), Audit::MONEY_FIELDS));
        }
        $cancel = array_replace($zero, ['gross_revenue_minor' => 1, 'other_adjustments_minor' => -1]);
        $this->assertSame('nonzero', Audit::classify($cancel, Audit::MONEY_FIELDS));
        foreach ([null, false, true, 0.0, '', '0.0', '0e0', ' 0', '+1', '01', '12junk', '9223372036854775808'] as $invalid) {
            $fact = array_replace($cancel, ['net_revenue_minor' => $invalid]);
            $this->assertSame('unknown', Audit::classify($fact, Audit::MONEY_FIELDS));
            $this->assertTrue(Audit::anyKnownNonzero($fact, Audit::MONEY_FIELDS));
        }
        $this->assertSame(PHP_INT_MIN, Audit::integer((string) PHP_INT_MIN));
        $this->assertSame(PHP_INT_MAX, Audit::integer((string) PHP_INT_MAX));
    }

    public function test_nullable_counters_remain_unknown_with_known_activity(): void
    {
        $fact = array_fill_keys(Audit::COUNTER_FIELDS, 0);
        $this->assertSame('zero', Audit::classify($fact, Audit::COUNTER_FIELDS, true));
        $fact['active_view_viewable_impressions'] = null;
        $fact['impressions'] = 1;
        $this->assertSame('unknown', Audit::classify($fact, Audit::COUNTER_FIELDS, true));
        $this->assertTrue(Audit::anyKnownNonzero($fact, Audit::COUNTER_FIELDS, true));
        foreach (['unfilled_requests', 'video_starts', 'completed_views'] as $field) {
            $this->assertSame('nonzero', Audit::classify(array_replace(array_fill_keys(Audit::COUNTER_FIELDS, 0), [$field => 1]), Audit::COUNTER_FIELDS, true));
        }
    }

    public function test_complete_receipts_and_coverage_pass_with_nonzero_remaining_money(): void
    {
        $fixture = $this->fixture(['other_adjustments_minor' => -1, 'publisher_earnings_minor' => 1]);
        $result = $this->inspect($fixture);
        $this->assertSame('OK', $result['status'], json_encode($result));
        $this->assertSame(61, $result['counts']['remaining_money_nonzero']);
        $this->assertSame(61, $result['counts']['remaining_money_known_nonzero']);
        $this->assertSame(0, $result['counts']['remaining_money_zero']);
        $this->assertSame(35, $result['counts']['corrected_facts']);
        $this->assertSame(6, $result['counts']['unique_receipts']);
        $this->assertSame(96, $result['counts']['covered_facts']);
        $this->assertSame(61, $result['counts']['blocked_facts']);
        $this->assertSame(0, $result['counts']['pending_facts']);
        $this->assertPublicOnly($result, $fixture);
    }

    public function test_unknown_money_cannot_pass_but_does_not_hide_known_nonzero(): void
    {
        $fixture = $this->fixture(['gross_revenue_minor' => 1, 'net_revenue_minor' => null, 'active_view_viewable_impressions' => null, 'impressions' => 1]);
        $result = $this->inspect($fixture);
        $this->assertSame('FAILED', $result['status']);
        $this->assertSame(61, $result['counts']['remaining_money_unknown']);
        $this->assertSame(61, $result['counts']['remaining_money_known_nonzero']);
        $this->assertSame(61, $result['counts']['remaining_counters_unknown']);
        $this->assertSame(61, $result['counts']['remaining_counters_nonzero']);
        $this->assertSame(0, $result['counts']['remaining_counters_zero']);
        $this->assertFalse($result['checks']['remaining_money_valid']);
    }

    public function test_corrected_dimension_tampering_and_remaining_row_tampering_fail(): void
    {
        $fixture = $this->fixture();
        $fixture['facts'][0]['dimension']['dimension_hash'] = 'synthetic-changed';
        $result = $this->inspect($fixture);
        $this->assertFalse($result['checks']['corrected_hashes_match']);
        $this->assertGreaterThan(0, $result['counts']['hash_issues']);
        $fixture = $this->fixture();
        $fixture['facts'][95]['fact']['other_adjustments_minor'] = -1;
        $result = $this->inspect($fixture);
        $this->assertFalse($result['checks']['remaining_hashes_match']);
        $this->assertSame(1, $result['counts']['remaining_money_nonzero']);
    }

    public function test_missing_and_duplicate_evidence_is_not_silently_counted_as_verified(): void
    {
        $fixture = $this->fixture();
        array_pop($fixture['facts']);
        array_pop($fixture['receipts']);
        $fixture['receipts'][] = $fixture['receipts'][0];
        $result = $this->inspect($fixture);
        $this->assertSame('FAILED', $result['status']);
        $this->assertSame(1, $result['counts']['missing_facts']);
        $this->assertSame(1, $result['counts']['missing_receipts']);
        $this->assertGreaterThan(0, $result['counts']['duplicate_receipts']);
        $this->assertFalse($result['checks']['receipts_match']);
        $fixture = $this->fixture();
        $fixture['facts'][] = $fixture['facts'][0];
        $this->assertSame(1, $this->inspect($fixture)['counts']['duplicate_facts']);
    }

    public function test_currency_provenance_and_nonfinancial_drift_have_distinct_failures(): void
    {
        $fixture = $this->fixture();
        $fixture['facts'][95]['fact']['currency'] = 'EUR';
        $fixture['manifest']['coverage']['daily:synthetic-fact-96']['reason'] = 'UNVERIFIED_OBSERVED_DAY';
        $fixture['inventory']['records']['source:synthetic-source-1'] = Audit::hash(['changed']);
        $fixture['parity']['publisher'] = false;
        $result = $this->inspect($fixture);
        $this->assertSame(1, $result['counts']['currency_issues']);
        $this->assertSame(1, $result['counts']['pending_facts']);
        $this->assertSame(1, $result['counts']['other_record_drift']);
        $this->assertFalse($result['checks']['currency_valid']);
        $this->assertFalse($result['checks']['inventory_matches']);
        $this->assertFalse($result['checks']['publisher_reporting_parity']);
        $this->assertPublicOnly($result, $fixture);
    }

    public function test_frozen_batch_inventory_and_receipt_snapshots_are_authenticated(): void
    {
        $fixture = $this->fixture();
        $fixture['manifest']['records']['daily:synthetic-fact-96'] = Audit::hash(['replacement']);
        $fixture['inventory']['records']['daily:synthetic-fact-96'] = Audit::hash(['replacement']);
        $this->assertFalse($this->inspect($fixture)['checks']['manifest_digest']);
        $fixture = $this->fixture();
        $fixture['receipts'][0]['after']['facts'][0]['fact']['revision']++;
        $this->assertGreaterThan(0, $this->inspect($fixture)['counts']['hash_issues']);
    }

    public function test_non_daily_original_coverage_cannot_be_omitted(): void
    {
        foreach (['hourly_facts', 'forward_facts', 'corrected_facts'] as $field) {
            $fixture = $this->fixture();
            $fixture['manifest']['initial_counts'][$field] = 1;
            $this->assertFalse($this->inspect($fixture)['checks']['coverage_complete']);
        }
        $fixture = $this->fixture();
        $fixture['manifest']['coverage']['hourly:synthetic-hourly'] = ['state' => 'BLOCKED'];
        $this->assertFalse($this->inspect($fixture)['checks']['coverage_complete']);
    }

    public function test_missing_google_row_stays_unknown_and_cannot_be_relabelled(): void
    {
        $fixture = $this->fixture();
        $parent = &$fixture['candidates'][0];
        $parent['job']['result']['days']['2020-04-05'] = ['synthetic' => true];
        $parent['digest'] = $this->candidateDigest($parent);
        $result = $this->inspect($fixture);
        $this->assertGreaterThan(0, $result['counts']['provenance_issues']);
        $this->assertFalse($result['checks']['provenance_valid']);
    }

    public function test_manifest_read_uses_shared_existing_lock_and_never_changes_files(): void
    {
        $directory = sys_get_temp_dir().'/horus-read-only-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $path = $directory.'/'.Audit::OPERATION.'.json';
        $lockPath = $directory.'/'.Audit::OPERATION.'.lock';
        file_put_contents($path, '{"synthetic":true}'); chmod($path, 0600);
        file_put_contents($lockPath, ''); chmod($lockPath, 0600);
        $hash = hash_file('sha256', $path);
        $mtime = filemtime($path);
        $lock = fopen($lockPath, 'r');
        try {
            flock($lock, LOCK_SH | LOCK_NB);
            $this->assertTrue(Audit::withManifest($directory, fn ($manifest) => $manifest['synthetic']));
            flock($lock, LOCK_UN); flock($lock, LOCK_EX | LOCK_NB);
            try {
                Audit::withManifest($directory, fn () => $this->fail('Busy writer lock must reject reads.'));
                $this->fail('Expected lock rejection.');
            } catch (\RuntimeException $error) { $this->assertSame('LOCK_UNAVAILABLE', $error->getMessage()); }
            flock($lock, LOCK_UN);
            $this->assertSame($hash, hash_file('sha256', $path));
            $this->assertSame($mtime, filemtime($path));
            $this->assertSame(['.', '..', basename($path), basename($lockPath)], scandir($directory));
            unlink($lockPath);
            try {
                Audit::withManifest($directory, fn () => null);
                $this->fail('Missing lock must not be created.');
            } catch (\RuntimeException $error) { $this->assertSame('LOCK_UNAVAILABLE', $error->getMessage()); }
            $this->assertFileDoesNotExist($lockPath);
        } finally { fclose($lock); if (is_file($lockPath)) unlink($lockPath); unlink($path); rmdir($directory); }
    }

    public function test_failures_never_echo_untrusted_exception_text(): void
    {
        $result = Audit::failure('synthetic-private-login@example.test amount secret');
        $this->assertSame('AUDIT_FAILED', $result['reason']);
        $this->assertStringNotContainsString('secret', json_encode($result));
        $this->assertSame(Audit::COUNT_KEYS, array_keys($result['counts']));
        $this->assertSame(Audit::CHECK_KEYS, array_keys($result['checks']));
    }

    public function test_recomputed_parent_digest_does_not_replace_frozen_provenance(): void
    {
        $fixture = $this->fixture();
        $fixture['candidates'][0]['proposal']['synthetic-changed'] = true;
        $fixture['candidates'][0]['digest'] = $this->candidateDigest($fixture['candidates'][0]);
        $result = $this->inspect($fixture);
        $this->assertFalse($result['checks']['provenance_valid']);
        $this->assertGreaterThan(0, $result['counts']['provenance_issues']);
    }

    public function test_provider_cache_is_process_local_before_settings_are_booted(): void
    {
        $directory = sys_get_temp_dir().'/horus-cache-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            config(['cache.default' => 'file', 'cache.stores.file.path' => $directory]);
            Audit::configureReadOnlyRuntime($this->app);
            app(\App\Services\Settings\GlobalSettingsService::class)->applyRuntimeOverrides();
            $this->assertSame('array', config('cache.default'));
            $this->assertInstanceOf(\Illuminate\Cache\ArrayStore::class, app('cache')->store()->getStore());
            $this->assertSame(['.', '..'], scandir($directory));
        } finally { rmdir($directory); }
    }

    public function test_reporting_parity_uses_full_site_and_publisher_filters_without_writes(): void
    {
        config(['reporting.canonical_currency' => 'USD']);
        $facts = [];
        for ($tenant = 0; $tenant < 2; $tenant++) {
            $organization = (string) Str::ulid(); $publisher = (string) Str::ulid();
            DB::table('organizations')->insert(['id' => $organization, 'name' => 'Synthetic', 'slug' => 'synthetic-'.$tenant, 'type' => 'PUBLISHER', 'status' => 'ACTIVE']);
            DB::table('publishers')->insert(['id' => $publisher, 'organization_id' => $organization, 'legal_name' => 'Synthetic', 'display_name' => 'Synthetic', 'status' => 'ACTIVE']);
            $source = (string) Str::ulid(); $connection = (string) Str::ulid(); $job = (string) Str::ulid();
            DB::table('report_sources')->insert(['id' => $source, 'code' => 'SYNTHETIC_'.$tenant, 'name' => 'Synthetic']);
            DB::table('report_source_connections')->insert(['id' => $connection, 'organization_id' => $organization, 'report_source_id' => $source, 'name' => 'Synthetic', 'connection_type' => 'TEST']);
            DB::table('report_import_jobs')->insert(['id' => $job, 'organization_id' => $organization, 'report_source_connection_id' => $connection,
                'import_type' => 'TEST', 'granularity' => 'DAILY', 'finality' => 'FINALIZED', 'status' => 'COMPLETED',
                'period_start' => '2020-01-01', 'period_end' => '2020-01-03', 'idempotency_key' => hash('sha256', 'synthetic-'.$tenant)]);
            for ($siteIndex = 0; $siteIndex < 2; $siteIndex++) {
                $site = (string) Str::ulid();
                DB::table('sites')->insert(['id' => $site, 'organization_id' => $organization, 'publisher_id' => $publisher, 'public_key' => $site,
                    'display_name' => 'Synthetic', 'primary_domain' => 'synthetic-'.$siteIndex.'.example.test', 'content_category' => 'News', 'country' => 'US', 'default_revenue_share_percent' => 70]);
                foreach ([['USD', 'FINALIZED', '2020-01-01'], ['USD', 'FINALIZED', '2020-01-02'], ['USD', 'ESTIMATED', '2020-01-01'], ['EUR', 'FINALIZED', '2020-01-01'], ['USD', 'FINALIZED', '2020-01-03']] as $index => [$currency, $finality, $date]) {
                    $dimensionId = (string) Str::ulid();
                    $dimension = ['id' => $dimensionId, 'organization_id' => $organization, 'publisher_id' => $publisher, 'site_id' => $site,
                        'dimension_hash' => hash('sha256', $dimensionId)];
                    DB::table('report_dimensions')->insert($dimension);
                    $fact = ['id' => (string) Str::ulid(), 'organization_id' => $organization, 'report_source_connection_id' => $connection,
                        'report_import_job_id' => $job, 'report_dimension_id' => $dimensionId, 'report_date' => $date, 'currency' => $currency,
                        'finality' => $finality, 'gross_revenue_minor' => $index + 1, 'publisher_earnings_minor' => $index + 1,
                        'impressions' => $index + 1, 'clicks' => 0, 'source_row_hash' => hash('sha256', $dimensionId)];
                    DB::table('daily_reports')->insert($fact);
                    if ($index < 2) $facts[] = ['fact' => $fact, 'dimension' => $dimension];
                }
            }
        }
        // Only a subset is supplied, but services include other in-range rows from these sites/publishers.
        $before = DB::table('daily_reports')->orderBy('id')->get()->toJson();
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void { $queries[] = $event->sql; });
        $this->assertSame(['admin' => true, 'publisher' => true], Audit::reportingParity($facts));
        foreach ($queries as $query) $this->assertMatchesRegularExpression('/\Aselect\s/i', $query);
        $this->assertSame($before, DB::table('daily_reports')->orderBy('id')->get()->toJson());
        config(['reporting.canonical_currency' => 'EUR']);
        $this->assertSame(['admin' => false, 'publisher' => false], Audit::reportingParity($facts));
    }

    public function test_snapshot_rejects_active_transactions_before_any_query(): void
    {
        $queries = [];
        DB::listen(static function ($event) use (&$queries): void { $queries[] = $event->sql; });
        $this->assertGreaterThan(0, DB::connection()->transactionLevel());
        try {
            Audit::readCurrent([]);
            $this->fail('An existing transaction must be rejected.');
        } catch (\RuntimeException $error) { $this->assertSame('SNAPSHOT_UNAVAILABLE', $error->getMessage()); }
        $this->assertSame([], $queries);
    }

    public function test_snapshot_is_consistent_read_only_and_always_rolled_back(): void
    {
        $pdo = new class {
            public array $calls = [];
            public function exec(string $sql): void { $this->calls[] = $sql; }
            public function inTransaction(): bool { return true; }
            public function rollBack(): void { $this->calls[] = 'ROLLBACK'; }
        };
        $connection = new class($pdo) {
            public bool $primary = false;
            public mixed $reconnector = null;
            public function __construct(public object $pdo) {}
            public function getDriverName(): string { return 'mysql'; }
            public function transactionLevel(): int { return 0; }
            public function useWriteConnectionWhenReading(): void { $this->primary = true; }
            public function setReconnector(callable $reconnector): void { $this->reconnector = $reconnector; }
            public function getPdo(): object { return $this->pdo; }
        };
        try {
            Audit::withReadOnlySnapshot($connection, static fn () => throw new \RuntimeException('synthetic'));
            $this->fail('Reader exception must propagate for sanitization.');
        } catch (\RuntimeException $error) { $this->assertSame('synthetic', $error->getMessage()); }
        $this->assertSame(['SET TRANSACTION ISOLATION LEVEL REPEATABLE READ', 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY', 'ROLLBACK'], $pdo->calls);
        $this->assertTrue($connection->primary);
        $this->expectExceptionMessage('SNAPSHOT_UNAVAILABLE');
        ($connection->reconnector)();
    }

    public function test_real_mysql_snapshot_rejects_dml_and_reader_rolls_back(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') $this->markTestSkipped('MySQL CI exercises the database-enforced read-only contract.');
        $default = config('database.default');
        $name = 'synthetic_historical_audit';
        config(['database.connections.'.$name => config('database.connections.'.$default)]);
        $connection = DB::connection($name);
        try {
            Audit::withReadOnlySnapshot($connection, function () use ($connection): void {
                $pdo = $connection->getPdo();
                $this->assertSame(1, (int) $pdo->query('SELECT 1')->fetchColumn());
                try {
                    $pdo->exec('UPDATE daily_reports SET revision = revision WHERE 1 = 0');
                    $this->fail('MySQL must reject even zero-row DML in a read-only transaction.');
                } catch (\PDOException $error) {
                    $this->assertSame(1792, (int) ($error->errorInfo[1] ?? 0));
                }
            });
            $this->assertFalse($connection->getPdo()->inTransaction());
            // Exercise the real deployed reader against the same isolated schema, outside its fixture transaction.
            DB::setDefaultConnection($name);
            $manifest = ['version' => 2, 'operation' => Audit::OPERATION, 'actor_id' => 'synthetic-actor',
                'contract' => ['schema_version' => 1, 'operation' => Audit::OPERATION, 'through' => Audit::THROUGH],
                'initial_counts' => ['daily_facts' => 0, 'sources' => 0, 'hourly_facts' => 0, 'forward_facts' => 0, 'corrected_facts' => 0],
                'coverage' => [], 'records' => [], 'windows' => [], 'batch' => [], 'batch_inventory_digest' => Audit::hash([]), 'digest' => Audit::DIGEST];
            $result = Audit::readCurrent($manifest);
            $this->assertSame('FAILED', $result['status']);
            $this->assertSame('AUDIT_MISMATCH', $result['reason']);
            $this->assertFalse($connection->getPdo()->inTransaction());
        } finally { DB::setDefaultConnection($default); DB::purge($name); }
    }

    public function test_counter_targets_are_only_the_two_frozen_zero_money_rows_with_stable_ordinals(): void
    {
        $fixture = $this->counterFixture();
        $targets = $this->targets($fixture);
        $this->assertCount(2, $targets);
        $this->assertSame(['synthetic-fact-95', 'synthetic-fact-96'], array_map(fn ($target) => $target['row']['fact']['id'], $targets));
        $this->assertSame([1, 1], array_column($targets, 'source_ordinal'));
        $this->assertSame([4, 4], array_column($targets, 'month_ordinal'));
        $this->assertSame(61, $this->inspect($fixture)['counts']['remaining_money_zero']);
        foreach ($targets as $target) $this->assertSame('zero', Audit::classify($target['row']['fact'], Audit::MONEY_FIELDS));
        foreach ([94, 95] as $index) {
            $changed = $fixture;
            $changed['facts'][$index]['fact']['impressions']++;
            $this->assertNull($this->targets($changed), 'Changed hashes must stop all Google work.');
        }
        $withMoney = $this->fixture([], [95 => ['impressions' => 1, 'other_adjustments_minor' => -1], 96 => ['clicks' => 1]]);
        $this->assertSame('OK', $this->inspect($withMoney)['status']);
        $this->assertNull($this->targets($withMoney), 'Even a hash-authenticated nonzero money field is out of scope.');
        $this->assertNull($this->targets($this->fixture([], [96 => ['impressions' => 1]])));
        $this->assertNull($this->targets($this->fixture([], [94 => ['clicks' => 1], 95 => ['clicks' => 1], 96 => ['clicks' => 1]])));
        $this->assertNull($this->targets($this->fixture([], [95 => ['impressions' => 1, 'active_view_viewable_impressions' => null], 96 => ['clicks' => 1]])));
        $changed = $fixture;
        $changed['candidates'][0]['context']['ad_unit_id'] = '999999';
        $this->assertNull($this->targets($changed), 'Frozen parent evidence cannot be replaced.');
    }

    public function test_counter_groups_keep_absence_optional_unknowns_and_unsupported_metrics_separate(): void
    {
        $fixture = $this->counterFixture(); $targets = $this->targets($fixture);
        $fresh = [0 => ['days' => [$targets[0]['date'] => ['impressions' => 8, 'clicks' => 0, 'gross_revenue_minor' => -1,
            'ad_requests' => 0, 'matched_requests' => 0, 'active_view_viewable_impressions' => null,
            'active_view_measurable_impressions' => null]], 'source_rows' => 1, 'excluded_site_rows' => 0],
            1 => ['days' => [], 'source_rows' => 3, 'excluded_site_rows' => 3]];
        $group = Audit::counterGroups($targets, $fresh)[0];
        $this->assertSame(2, $group['rows']);
        $this->assertSame(1, $group['stored']['impressions']['nonzero']);
        $this->assertSame(1, $group['fresh']['exact_site_observed']);
        $this->assertSame(1, $group['fresh']['exact_site_absent']);
        $this->assertSame(2, $group['fresh']['unit_day_observed']);
        $this->assertSame(1, $group['fresh']['nonmatching_site_observed']);
        $this->assertSame(['zero' => 0, 'nonzero' => 1, 'unknown' => 1], $group['fresh']['revenue']);
        $this->assertSame(['zero' => 0, 'nonzero' => 1, 'unknown' => 1, 'unavailable' => 0], $group['fresh']['fields']['impressions']);
        $this->assertSame(['zero' => 0, 'nonzero' => 0, 'unknown' => 1, 'unavailable' => 1], $group['fresh']['fields']['active_view_viewable_impressions']);
        foreach (array_diff(Audit::COUNTER_FIELDS, Audit::FRESH_COUNTER_FIELDS) as $field) {
            $this->assertSame(['zero' => 0, 'nonzero' => 0, 'unknown' => 0, 'unavailable' => 2], $group['fresh']['fields'][$field]);
        }
        $unknown = $targets; $unknown[0]['row']['fact']['impressions'] = null;
        $this->assertSame(1, Audit::counterGroups($unknown)[0]['stored']['impressions']['unknown']);
        $json = json_encode($group);
        foreach (['synthetic-', '2020-', 'example.test', 'revenue_micros', 'gross_revenue_minor'] as $private) $this->assertStringNotContainsString($private, $json);
    }

    public function test_counter_probe_rolls_back_before_google_and_rechecks_core_without_changing_evidence(): void
    {
        $fixture = $this->counterFixture(); $frozen = serialize($fixture); $bundle = $this->bundle($fixture);
        $active = false; $reads = $starts = $polls = 0; $elapsed = 0; $optional = [];
        $pdo = new class($active) {
            public function __construct(public bool &$active) {}
            public function exec(string $sql): void { if (str_starts_with($sql, 'START')) $this->active = true; }
            public function inTransaction(): bool { return $this->active; }
            public function rollBack(): void { $this->active = false; }
        };
        $connection = new class($pdo) {
            public function __construct(public object $pdo) {}
            public function getDriverName(): string { return 'mysql'; }
            public function transactionLevel(): int { return 0; }
            public function useWriteConnectionWhenReading(): void {}
            public function setReconnector(callable $callback): void {}
            public function getPdo(): object { return $this->pdo; }
        };
        $result = Audit::probeCounters(
            function ($contexts) use ($connection, $bundle, &$reads): array {
                $reads++;
                if ($reads === 2) $this->assertCount(2, $contexts);
                return Audit::withReadOnlySnapshot($connection, fn () => $bundle);
            },
            function ($target) use (&$active): array {
                $this->assertFalse($active);
                return array_replace($target['parent_context'], ['from' => $target['date'], 'to' => $target['date']]);
            },
            function ($context, $activeView) use (&$active, &$starts, &$optional): array {
                $this->assertFalse($active); $starts++; $optional[] = $activeView; return ['status' => 'PENDING'];
            },
            function ($context, $job) use (&$active, &$polls): array {
                $this->assertFalse($active); $polls++;
                return ['status' => 'COMPLETED', 'id' => 'synthetic-private-report', 'result' => [
                    'days' => [], 'exact_site_observed' => false, 'source_rows' => 1, 'excluded_site_rows' => 1]];
            }, static function () use (&$elapsed) { return $elapsed; }, static function ($seconds) use (&$elapsed): void { $elapsed += $seconds; },
        );
        $this->assertSame('OK', $result['status']);
        $this->assertSame('COMPLETE', $result['counter_probe']['status']);
        $this->assertSame([2, 2, 2], [$reads, $starts, $polls]);
        $this->assertSame([false, true], $optional);
        $this->assertNotContains(false, $result['counter_probe']['checks']);
        $this->assertSame(2, $result['counter_probe']['groups'][0]['fresh']['exact_site_absent']);
        $this->assertSame($frozen, serialize($fixture));
        $this->assertSame($bundle['result']['counts'], $result['counts']);
        $this->assertSame($bundle['result']['checks'], $result['checks']);
        $this->assertProbePublicOnly($result);
    }

    public function test_counter_probe_pending_jobs_stop_at_six_polls_and_preserve_verified_money(): void
    {
        $bundle = $this->bundle($this->counterFixture()); $reads = $starts = $polls = 0; $time = 0;
        $result = Audit::probeCounters(function () use ($bundle, &$reads) { $reads++; return $bundle; },
            fn ($target) => array_replace($target['parent_context'], ['from' => $target['date'], 'to' => $target['date']]),
            function () use (&$starts) { $starts++; return ['status' => 'PENDING']; },
            function ($context, $job) use (&$polls) { $polls++; return $job; },
            static function () use (&$time) { return $time; }, static function ($seconds) use (&$time): void { $time += $seconds; });
        $this->assertSame([2, 2, 6], [$reads, $starts, $polls]);
        $this->assertSame('OK', $result['status']);
        $this->assertSame('INCONCLUSIVE', $result['counter_probe']['status']);
        $this->assertSame('REPORT_TIMEOUT', $result['counter_probe']['reason']);
        $this->assertSame(61, $result['counts']['remaining_money_zero']);
        $this->assertNotContains(false, $result['counter_probe']['checks']);
        $this->assertSame(2, $result['counter_probe']['groups'][0]['fresh']['fields']['impressions']['unknown']);
        $this->assertSame(0, $result['counter_probe']['groups'][0]['fresh']['exact_site_absent']);
        $this->assertProbePublicOnly($result);
    }

    public function test_counter_probe_time_budget_and_google_failures_are_bounded_and_sanitized(): void
    {
        $bundle = $this->bundle($this->counterFixture()); $starts = 0; $time = 0;
        $context = fn ($target) => array_replace($target['parent_context'], ['from' => $target['date'], 'to' => $target['date']]);
        $result = Audit::probeCounters(fn () => $bundle, $context,
            function () use (&$starts, &$time) { $starts++; $time = Audit::PROBE_SECONDS; return ['status' => 'PENDING']; },
            fn () => $this->fail('No polls after the total budget.'),
            static function () use (&$time) { return $time; }, static fn () => null);
        $this->assertSame(1, $starts);
        $this->assertSame(0, $result['counter_probe']['polls']);
        $this->assertSame('REPORT_TIMEOUT', $result['counter_probe']['reason']);
        $result = Audit::probeCounters(fn () => $bundle, $context,
            static fn () => throw new \RuntimeException('synthetic-secret report-id https://private.example.test 12345'),
            fn () => $this->fail('Failed starts cannot poll.'), static fn () => 0, static fn () => null);
        $this->assertSame(2, $result['counter_probe']['reports_started']);
        $this->assertSame(0, $result['counter_probe']['polls']);
        $this->assertSame('GOOGLE_REPORT_FAILED', $result['counter_probe']['reason']);
        $this->assertSame('OK', $result['status']);
        $this->assertProbePublicOnly($result);
    }

    public function test_counter_probe_preflight_core_targets_and_binding_guards_prevent_google(): void
    {
        $good = $this->bundle($this->counterFixture());
        foreach (['core', 'targets', 'scope'] as $guard) {
            $bundle = $good;
            if ($guard === 'core') $bundle['result'] = Audit::failure('AUDIT_MISMATCH');
            if ($guard === 'targets') $bundle['targets'] = null;
            if ($guard === 'scope') $bundle['scope_valid'] = false;
            $never = fn () => $this->fail('Google must not run without all preflight guards.');
            $result = Audit::probeCounters(fn () => $bundle, $never, $never, $never);
            $this->assertSame('FAILED', $result['status']);
            $this->assertSame('SKIPPED', $result['counter_probe']['status']);
            $this->assertSame(0, $result['counter_probe']['reports_started']);
        }
        $result = Audit::probeCounters(fn () => $good,
            fn ($target) => array_replace($target['parent_context'], ['from' => $target['date'], 'to' => $target['date'], 'ad_unit_id' => '999999']),
            fn () => $this->fail('A rebound unit must never be queried.'), fn () => $this->fail('No jobs exist.'),
            static fn () => 0, static fn () => null);
        $this->assertSame(0, $result['counter_probe']['reports_started']);
        $this->assertSame('RECHECK_FAILED', $result['counter_probe']['reason']);
        $this->assertSame('FAILED', $result['status']);
        $this->assertFalse($result['counter_probe']['checks']['scope_rechecked']);
        $this->assertFalse($result['checks']['provenance_valid']);
    }

    public function test_counter_probe_skips_unsupported_only_targets_without_any_google_call(): void
    {
        $bundle = $this->bundle($this->fixture([], [95 => ['video_starts' => 1], 96 => ['unfilled_requests' => 1]]));
        $reads = 0;
        $never = fn () => $this->fail('Unsupported-only counters cannot benefit from an AdX report.');
        $result = Audit::probeCounters(function () use ($bundle, &$reads) { $reads++; return $bundle; }, $never, $never, $never);
        $this->assertSame(2, $reads);
        $this->assertSame('OK', $result['status']);
        $this->assertSame('COMPLETE', $result['counter_probe']['status']);
        $this->assertSame('UNSUPPORTED_COUNTERS', $result['counter_probe']['reason']);
        $this->assertSame(0, $result['counter_probe']['reports_started']);
        $this->assertSame(2, $result['counter_probe']['reports_skipped']);
        $this->assertSame(2, $result['counter_probe']['groups'][0]['fresh']['unsupported_only_skipped']);
        $this->assertSame(2, $result['counter_probe']['groups'][0]['fresh']['fields']['impressions']['unknown']);
        $this->assertSame(0, $result['counter_probe']['groups'][0]['fresh']['exact_site_absent']);
        $this->assertProbePublicOnly($result);
    }

    public function test_counter_probe_stops_existing_jobs_when_the_second_context_changes(): void
    {
        $bundle = $this->bundle($this->counterFixture()); $contexts = 0; $starts = 0;
        $result = Audit::probeCounters(fn () => $bundle,
            function ($target) use (&$contexts) {
                $context = array_replace($target['parent_context'], ['from' => $target['date'], 'to' => $target['date']]);
                if (++$contexts === 2) $context['network_currency'] = 'EUR';
                return $context;
            },
            function () use (&$starts) { $starts++; return ['status' => 'PENDING']; },
            fn () => $this->fail('An observed scope change must stop every further Google call.'),
            static fn () => 0, static fn () => null);
        $this->assertSame(1, $starts);
        $this->assertSame(0, $result['counter_probe']['polls']);
        $this->assertSame('FAILED', $result['status']);
        $this->assertFalse($result['counter_probe']['checks']['scope_rechecked']);
    }

    public function test_counter_probe_poll_scope_drift_is_sticky_despite_unchanged_final_database(): void
    {
        $bundle = $this->bundle($this->counterFixture());
        $result = Audit::probeCounters(fn () => $bundle,
            fn ($target) => array_replace($target['parent_context'], ['from' => $target['date'], 'to' => $target['date']]),
            static fn () => ['status' => 'PENDING'],
            static fn () => throw new \RuntimeException('NETWORK_IDENTITY_CHANGED'), static fn () => 0, static fn () => null);
        $this->assertSame('FAILED', $result['status']);
        $this->assertTrue($result['counter_probe']['checks']['core_rechecked']);
        $this->assertFalse($result['counter_probe']['checks']['scope_rechecked']);
        $this->assertSame('RECHECK_FAILED', $result['counter_probe']['reason']);
        $this->assertFalse($result['checks']['provenance_valid']);
    }

    public function test_counter_probe_final_drift_uses_latest_core_and_cannot_keep_stale_success(): void
    {
        $fixture = $this->counterFixture(); $before = $this->bundle($fixture);
        $fixture['facts'][95]['fact']['net_revenue_minor'] = 1;
        $after = $this->bundle($fixture); $reads = 0;
        $result = Audit::probeCounters(function () use (&$reads, $before, $after) { return ++$reads === 1 ? $before : $after; },
            fn ($target) => array_replace($target['parent_context'], ['from' => $target['date'], 'to' => $target['date']]),
            static fn () => ['status' => 'PENDING'], static fn ($context, $job) => $job,
            static fn () => 0, static fn () => null);
        $this->assertSame('FAILED', $result['status']);
        $this->assertSame(1, $result['counts']['remaining_money_nonzero']);
        $this->assertFalse($result['checks']['remaining_hashes_match']);
        $this->assertTrue($result['counter_probe']['checks']['initial_core_verified']);
        $this->assertFalse($result['counter_probe']['checks']['core_rechecked']);
        $this->assertSame('RECHECK_FAILED', $result['counter_probe']['reason']);
    }

    public function test_counter_probe_uses_only_fixed_one_day_adx_report_contract(): void
    {
        $target = $this->targets($this->counterFixture())[0];
        $context = array_replace($target['parent_context'], ['from' => $target['date'], 'to' => $target['date']]);
        $query = Audit::counterQuery($context, true);
        $this->assertSame(array_keys(\App\Services\Reporting\SiteGamReportMetrics::CORE_COLUMNS), Audit::counterQuery($context)['columns']);
        $this->assertSame(['DATE', 'AD_UNIT_ID', 'SITE_NAME'], $query['dimensions']);
        $this->assertSame(array_keys(\App\Services\Reporting\SiteGamReportMetrics::COLUMNS), $query['columns']);
        $this->assertCount(7, $query['columns']);
        $this->assertSame('FLAT', $query['adUnitView']);
        $this->assertSame('USD', $query['reportCurrency']);
        $this->assertSame('PUBLISHER', $query['timeZoneType']);
        $this->assertSame($query['startDate'], $query['endDate']);
        $this->assertSame('WHERE AD_UNIT_ID = :unit', $query['statement']['query']);
    }

    private function counterFixture(): array
    {
        return $this->fixture([], [95 => ['impressions' => 17, 'video_starts' => 2],
            96 => ['clicks' => 3, 'active_view_viewable_impressions' => 8]]);
    }

    private function targets(array $fixture): ?array
    {
        return Audit::counterTargets($fixture['manifest'], $fixture['inventory'], $fixture['facts'], $fixture['candidates'], $this->inspect($fixture));
    }

    private function bundle(array $fixture): array
    {
        return ['result' => $this->inspect($fixture), 'targets' => $this->targets($fixture), 'scope_valid' => true];
    }

    private function assertProbePublicOnly(array $result): void
    {
        $this->assertSame(['schema_version', 'status', 'reason', 'counts', 'checks', 'counter_probe'], array_keys($result));
        $probe = $result['counter_probe'];
        $this->assertSame(['status', 'reason', 'target_rows', 'reports_started', 'reports_completed', 'reports_skipped', 'polls', 'checks', 'groups'], array_keys($probe));
        $this->assertSame(Audit::PROBE_CHECKS, array_keys($probe['checks']));
        foreach ($probe['groups'] as $group) {
            $this->assertSame(Audit::COUNTER_FIELDS, array_keys($group['stored']));
            $this->assertSame(Audit::COUNTER_FIELDS, array_keys($group['fresh']['fields']));
            foreach ($group['stored'] as $states) $this->assertSame($group['rows'], array_sum($states));
            foreach ($group['fresh']['fields'] as $states) $this->assertSame($group['rows'], array_sum($states));
        }
        $json = json_encode($result);
        foreach (['synthetic-', '2020-', 'example.test', '12345', 'revenue_micros', ...Audit::MONEY_FIELDS] as $private) $this->assertStringNotContainsString($private, $json);
    }

    private function inspect(array $fixture): array
    {
        return Audit::inspect($fixture['manifest'], $fixture['inventory'], $fixture['facts'], $fixture['receipts'],
            $fixture['candidates'], $fixture['parity'], $fixture['expected']);
    }

    private function snapshot(array $facts): array
    {
        $snapshot = ['facts' => $facts, 'hourly' => [], 'finance' => [], 'adjustments' => 'synthetic'];
        return $snapshot + ['fingerprint' => Audit::hash($snapshot)];
    }

    private function candidateDigest(array $candidate): string
    {
        return Audit::hash(['version' => 1, 'candidate_id' => $candidate['id'], 'actor_id' => $candidate['actor_id'],
            'context' => $candidate['context'], 'snapshot' => $candidate['snapshot']['fingerprint'],
            'query_hash' => $candidate['query_hash'], 'job' => $candidate['job'], 'proposal' => $candidate['proposal']]);
    }

    private function fixture(array $remainingFields = [], array $targetFields = []): array
    {
        $operation = Audit::hash(['synthetic-operation']);
        $dimension = ['id' => 'synthetic-dimension', 'dimension_hash' => 'synthetic-dimension-hash',
            'site_id' => 'synthetic-site', 'publisher_id' => 'synthetic-publisher',
            'organization_id' => 'synthetic-organization', 'gam_connection_id' => 'synthetic-gam'];
        $original = [];
        for ($i = 1; $i <= 96; $i++) {
            $date = (new \DateTimeImmutable('2020-01-01'))->modify('+'.($i - 1).' days')->format('Y-m-d');
            $fact = ['id' => 'synthetic-fact-'.$i, 'currency' => 'USD', 'report_date' => $date,
                'report_dimension_id' => $dimension['id'], 'revision' => 1, 'organization_id' => 'synthetic-organization',
                'report_source_connection_id' => 'synthetic-source-1'];
            $fact += array_fill_keys([...Audit::MONEY_FIELDS, ...Audit::COUNTER_FIELDS], 0);
            if ($i > 35) $fact = array_replace($fact, $remainingFields);
            if (isset($targetFields[$i])) $fact = array_replace($fact, $targetFields[$i]);
            $original[] = ['fact' => $fact, 'dimension' => $dimension];
        }
        $facts = $original;
        $context = ['site_id' => 'synthetic-site', 'source_connection_id' => 'synthetic-source-1', 'currency' => 'USD',
            'organization_id' => 'synthetic-organization', 'publisher_id' => 'synthetic-publisher', 'gam_connection_id' => 'synthetic-gam',
            'binding_id' => 'synthetic-binding', 'binding_fingerprint' => Audit::hash(['synthetic-binding']),
            'hostname' => 'synthetic.example.test', 'ad_unit_id' => '12345', 'from' => '2020-01-01', 'to' => '2020-04-05',
            'metric_basis' => 'AD_EXCHANGE_V1', 'network_currency' => 'USD'];
        $parent = ['id' => 'synthetic-parent', 'status' => 'BLOCKED', 'actor_id' => 'synthetic-actor',
            'context' => $context, 'snapshot' => $this->snapshot($original), 'query_hash' => Audit::hash(['synthetic-query']),
            'job' => ['status' => 'COMPLETED', 'result' => ['days' => []]], 'proposal' => []];
        $parent['digest'] = $this->candidateDigest($parent);
        $candidates = [$parent]; $receipts = $records = $coverage = $inventoryCoverage = [];
        $parentKey = Audit::hash(['synthetic-parent-window']);
        $parentWindow = ['key' => $parentKey, 'state' => 'PARTITIONED', 'candidate_id' => $parent['id'],
            'candidate_digest' => $parent['digest'], 'fact_ids' => array_column(array_column($original, 'fact'), 'id'),
            'partition' => ['unresolved' => [], 'evidence_hash' => Audit::evidenceHash($parent)]];
        $windows = [$parentWindow];
        for ($w = 0; $w < 6; $w++) {
            $before = array_slice($original, $w * 6, $w === 5 ? 5 : 6);
            $after = $before;
            foreach ($after as $j => &$row) {
                $row['fact']['revision'] = 2;
                $row['dimension']['dimension_hash'] = 'synthetic-corrected-dimension';
                $facts[$w * 6 + $j] = $row;
            }
            unset($row);
            $candidate = ['id' => 'synthetic-candidate-'.$w, 'status' => 'APPLIED', 'actor_id' => 'synthetic-actor',
                'context' => $context, 'snapshot' => $this->snapshot($before), 'query_hash' => Audit::hash(['synthetic-query', $w]),
                'job' => ['status' => 'COMPLETED'], 'proposal' => ['synthetic' => true]];
            $candidate['digest'] = $this->candidateDigest($candidate);
            $candidates[] = $candidate;
            $receiptId = 'synthetic-receipt-'.$w;
            $receipt = ['before' => $this->snapshot($before), 'after' => $this->snapshot($after), 'context' => $context];
            $receipt['attributes'] = ['id' => $receiptId, 'correction_id' => $candidate['id'], 'digest' => $candidate['digest'],
                'approved_by' => 'synthetic-actor', 'before_hash' => $receipt['before']['fingerprint'], 'after_hash' => $receipt['after']['fingerprint'],
                'before' => json_encode($receipt['before']), 'after' => json_encode($receipt['after']), 'context' => json_encode($context)];
            $receipts[] = $receipt;
            $window = ['key' => Audit::hash(['synthetic-window', $w]), 'state' => 'APPLIED', 'receipt_id' => $receiptId,
                'candidate_id' => $candidate['id'], 'candidate_digest' => $candidate['digest'],
                'fact_ids' => array_column(array_column($after, 'fact'), 'id'),
                'ancestor_evidence' => [['candidate_id' => $parent['id'], 'digest' => $parent['digest'], 'evidence_hash' => Audit::evidenceHash($parent)]]] + $context;
            $windows[] = $window;
            $records['receipt:'.$receiptId] = Audit::hash($receipt['attributes']);
            foreach ($after as $row) {
                $coverage['daily:'.$row['fact']['id']] = ['state' => 'CORRECTED', 'receipt_id' => $receiptId, 'receipt_digest' => $candidate['digest']];
                $inventoryCoverage['daily:'.$row['fact']['id']] = ['state' => 'CORRECTED'];
            }
        }
        foreach ($facts as $index => $row) {
            $key = 'daily:'.$row['fact']['id'];
            $records[$key] = Audit::hash([$row['fact'], $row['dimension']]);
            if ($index < 35) continue;
            $date = $row['fact']['report_date'];
            $coverage[$key] = ['state' => 'BLOCKED', 'reason' => 'NO_EXACT_SITE_ROW', 'parent_window' => $parentKey, 'date' => $date];
            $inventoryCoverage[$key] = ['state' => 'ELIGIBLE'];
            $windows[0]['partition']['unresolved'][$date] = ['fact_id' => $row['fact']['id'], 'reason' => 'NO_EXACT_SITE_ROW'];
        }
        for ($i = 1; $i <= 3; $i++) $records['source:synthetic-source-'.$i] = Audit::hash(['synthetic-source', $i]);
        $batchRecords = $records;
        unset($batchRecords['receipt:synthetic-receipt-5']);
        foreach ($receipts[5]['before']['facts'] as $row) $batchRecords['daily:'.$row['fact']['id']] = Audit::hash([$row['fact'], $row['dimension']]);
        $manifest = ['version' => 2, 'operation' => $operation, 'actor_id' => 'synthetic-actor',
            'contract' => ['schema_version' => 1, 'operation' => $operation, 'through' => '2020-04-05'],
            'initial_counts' => ['daily_facts' => 96, 'sources' => 3, 'hourly_facts' => 0, 'forward_facts' => 0, 'corrected_facts' => 0],
            'coverage' => $coverage, 'records' => $records, 'windows' => $windows, 'batch' => [$windows[6]['key']],
            'batch_coverage' => $coverage, 'batch_inventory_digest' => Audit::hash($batchRecords)];
        $manifest['digest'] = Audit::manifestDigest($manifest);
        $inventory = ['records' => $records, 'coverage' => $inventoryCoverage];
        $expected = ['operation' => $operation, 'digest' => $manifest['digest'], 'through' => '2020-04-05'];
        $parity = ['admin' => true, 'publisher' => true];
        return compact('manifest', 'inventory', 'facts', 'receipts', 'candidates', 'expected', 'parity');
    }

    private function assertPublicOnly(array $result, array $fixture): void
    {
        $this->assertSame(['schema_version', 'status', 'reason', 'counts', 'checks'], array_keys($result));
        $this->assertSame(Audit::COUNT_KEYS, array_keys($result['counts']));
        $this->assertSame(Audit::CHECK_KEYS, array_keys($result['checks']));
        foreach ($result['counts'] as $count) { $this->assertIsInt($count); $this->assertGreaterThanOrEqual(0, $count); }
        foreach ($result['checks'] as $check) $this->assertIsBool($check);
        $json = json_encode($result);
        foreach (['synthetic-', '2020-', 'example.test', $fixture['expected']['digest'], $fixture['expected']['operation'], ...Audit::MONEY_FIELDS] as $private) {
            $this->assertStringNotContainsString($private, $json);
        }
    }
}
