<?php

declare(strict_types=1);

/** One private operational audit event; never acquires, releases or rewrites a reporting mutex. */
final class HorusMainReportingLeaseObservation
{
    public const EVENT = 'operations.main_reporting_lease_observed';
    public const SCHEMA = 'main_reporting_lease_observation_v1';
    public const KNOWN_RELEASES = [
        '5c3861f8ffdb97cec42c35476894e22b845beb3b',
        'c1514c9daf9324a63b6b758c1068e1ab530766da',
        '4d3e0adbe403b45e8975fe7b1580d015e4ddcdb8',
        '130a0d8e9dc78cfcfa2371ffa7af789f56ea3a74',
    ];

    public static function beginReadOnlyTransaction(object $connection, bool &$sessionProbeEnabled): void
    {
        // SET TRANSACTION affects the next transaction. Callback guards must not
        // issue an autocommit SELECT between that SET and PDO beginTransaction.
        $sessionProbeEnabled = false;
        try {
            $connection->statement('SET TRANSACTION READ ONLY');
            $connection->beginTransaction();
        } finally {
            $sessionProbeEnabled = true;
        }
    }

    public static function release(string $marker, string $expected): string
    {
        if (! preg_match('/^[a-f0-9]{40}$/D', $expected)
            || preg_match_all('/^release_id=(.*)$/m', $marker, $matches) !== 1
            || ! in_array($matches[1][0], [...self::KNOWN_RELEASES, $expected], true)) {
            throw new RuntimeException('UNAVAILABLE');
        }
        return $matches[1][0];
    }

    public static function snapshot(string $link, string $expected): array
    {
        clearstatcache(true);
        $root = realpath($link);
        if ($link === '' || $link[0] !== '/' || $root === false || ! is_dir($root)
            || is_link($root.'/.horus-release') || ! is_file($root.'/.horus-release')) {
            throw new RuntimeException('UNAVAILABLE');
        }
        $marker = @file_get_contents($root.'/.horus-release', false, null, 0, 16385);
        if (! is_string($marker) || strlen($marker) > 16384) throw new RuntimeException('UNAVAILABLE');
        return ['root' => $root, 'marker' => $marker, 'release' => self::release($marker, $expected)];
    }

    public static function assertSnapshot(string $link, string $expected, array $snapshot): void
    {
        if (self::snapshot($link, $expected) !== $snapshot || getcwd() !== $snapshot['root']) {
            throw new RuntimeException('UNAVAILABLE');
        }
    }

    public static function isMainCommand(string $command): bool
    {
        // A whole shell token: a video command, suffix or similarly named command cannot match.
        return preg_match('/(?:^|[\s\'\"])reporting:sync-site-gam(?=$|[\s\'\"])/D', $command) === 1;
    }

    public static function lease(?int $expiration, int $observedAt, int $ttlMinutes): array
    {
        if ($observedAt < 1 || $ttlMinutes < 1 || $ttlMinutes > 10080 || ($expiration !== null && $expiration < 1)) {
            throw new RuntimeException('UNAVAILABLE');
        }
        return [
            'status' => $expiration === null ? 'ABSENT' : ($expiration <= $observedAt ? 'EXPIRED' : 'ACTIVE'),
            'observed_at_utc' => gmdate('Y-m-d\TH:i:s\Z', $observedAt),
            'expiration_utc' => $expiration === null ? null : gmdate('Y-m-d\TH:i:s\Z', $expiration),
            // Signed age relative to expiry, not an invented lease creation time.
            'expiry_age_seconds' => $expiration === null ? null : $observedAt - $expiration,
            'remaining_seconds' => $expiration === null ? null : max(0, $expiration - $observedAt),
            'configured_schedule_ttl_seconds' => $ttlMinutes * 60,
            'duration_relation' => $expiration !== null && $expiration - $observedAt > $ttlMinutes * 60
                ? 'LEGACY_OR_DIFFERENT_DURATION' : 'UNPROVEN',
            'lease_age_seconds' => 'UNKNOWN',
            'lease_created_at' => 'UNPROVEN',
            'original_lease_ttl' => 'UNPROVEN',
        ];
    }

    public static function auditId(string $operation): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $operation)) throw new RuntimeException('UNAVAILABLE');
        // A valid deterministic ULID provides primary-key uniqueness without a new mutex or schema.
        $bytes = hex2bin(substr(hash('sha256', self::EVENT.'|'.$operation), 0, 32));
        $bits = '00';
        foreach (unpack('C*', $bytes) as $byte) $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $id = '';
        for ($i = 0; $i < 130; $i += 5) $id .= $alphabet[bindec(substr($bits, $i, 5))];
        return $id;
    }

    public static function publicResult(array $metadata, string $auditStatus): array
    {
        $leaseStatus = $metadata['lease']['status'] ?? null;
        $configuration = $metadata['configuration_status'] ?? null;
        if (($metadata['schema'] ?? null) !== self::SCHEMA
            || ! in_array($auditStatus, ['RECORDED', 'ALREADY_RECORDED'], true)
            || ! in_array($leaseStatus, ['ABSENT', 'EXPIRED', 'ACTIVE'], true)
            || ! in_array($configuration, ['PASS', 'FAIL'], true)
            || ($metadata['single_writer_inventory'] ?? null) !== 'UNPROVEN'
            || ($metadata['nonmultiplexed_topology'] ?? null) !== 'UNPROVEN') {
            throw new RuntimeException('UNAVAILABLE');
        }
        return ['schema_version' => 1, 'observation' => 'MAIN_REPORTING_LEASE',
            'audit_status' => $auditStatus, 'lease_status' => $leaseStatus,
            'configuration_status' => $configuration, 'global_topology' => 'UNPROVEN'];
    }

    public static function matchesExisting(object $audit, string $operation): bool
    {
        return $audit->event === self::EVENT && $audit->actor_id === null && $audit->actor_type === null
            && $audit->organization_id === null && $audit->auditable_id === null && $audit->auditable_type === null
            && ($audit->metadata['operation_id'] ?? null) === $operation
            && ($audit->metadata['schema'] ?? null) === self::SCHEMA;
    }
}

// This constant can only be set by a PHP fixture, never by a process environment variable.
if (defined('HORUS_MAIN_LEASE_TEST_ONLY') && HORUS_MAIN_LEASE_TEST_ONLY === true) return;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$initialBufferLevel = ob_get_level();
ob_start();
$db = null;
try {
    $expected = (string) getenv('HM_EXPECTED_SHA');
    $link = (string) getenv('HM_APP_LINK');
    $operation = (string) getenv('HM_OPERATION_ID');
    $auditId = HorusMainReportingLeaseObservation::auditId($operation);
    $snapshot = HorusMainReportingLeaseObservation::snapshot($link, $expected);
    HorusMainReportingLeaseObservation::assertSnapshot($link, $expected, $snapshot);
    require $snapshot['root'].'/vendor/autoload.php';
    $app = require $snapshot['root'].'/bootstrap/app.php';
    $originalCache = [];
    $originalScheduleStore = null;
    $pdo = null;
    $default = null;
    $session = null;
    $sessionProbeEnabled = true;
    $guard = static function () use (&$db, &$pdo, &$default, &$session, &$sessionProbeEnabled, $link, $expected, $snapshot): void {
        HorusMainReportingLeaseObservation::assertSnapshot($link, $expected, $snapshot);
        if ($db !== null && ($db->getRawPdo() !== $pdo
            || ($db->getRawReadPdo() !== null && $db->getRawReadPdo() !== $pdo)
            || app('db')->getDefaultConnection() !== $default
            || (app('db')->getConnections()[$default] ?? null) !== $db)) throw new RuntimeException('UNAVAILABLE');
        if ($sessionProbeEnabled && $session !== null && (string) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn() !== $session) {
            throw new RuntimeException('UNAVAILABLE');
        }
    };
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) use (&$originalCache, &$originalScheduleStore): void {
        $config = $app->make('config');
        $config->set(['logging.default' => 'null', 'logging.deprecations.channel' => 'null', 'app.debug' => false]);
        $originalCache = $config->get('cache');
        if (! is_array($originalCache) || ($originalCache['stores']['array']['driver'] ?? null) !== 'array') {
            throw new RuntimeException('UNAVAILABLE');
        }
        $originalScheduleStore = $config->get('cache.schedule_store', Illuminate\Support\Env::get('SCHEDULE_CACHE_DRIVER',
            static fn () => Illuminate\Support\Env::get('SCHEDULE_CACHE_STORE'))) ?? $originalCache['default'];
        if (! $app->make('config_loaded_from_cache')) throw new RuntimeException('UNAVAILABLE');
        $packagesPath = $app->getCachedPackagesPath();
        $servicesPath = $app->getCachedServicesPath();
        if (! is_file($packagesPath) || ! is_readable($packagesPath)
            || ! is_file($servicesPath) || ! is_readable($servicesPath)) throw new RuntimeException('UNAVAILABLE');
        $packages = require $packagesPath;
        $services = require $servicesPath;
        if (! is_array($packages) || ! is_array($services) || ! is_array($services['providers'] ?? null)) {
            throw new RuntimeException('UNAVAILABLE');
        }
        $manifest = $app->make(Illuminate\Foundation\PackageManifest::class);
        $manifest->manifest = $packages;
        $providers = (new Illuminate\Support\Collection($config->get('app.providers')))
            ->partition(static fn ($provider) => str_starts_with($provider, 'Illuminate\\'));
        $providers->splice(1, 0, [$manifest->providers()]);
        if ($services['providers'] != $providers->collapse()->toArray()) throw new RuntimeException('UNAVAILABLE');
        // Provider remember() calls are process-only, including during registration.
        $config->set('cache.default', 'array');
    });
    $app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class, function () use (&$db, &$pdo, &$default, &$session, &$sessionProbeEnabled, $guard): void {
        config(['cache.default' => 'array']);
        $default = app('db')->getDefaultConnection();
        $db = Illuminate\Support\Facades\DB::connection();
        $configured = config('database.connections.'.$default);
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)
            || ! is_array($configured) || ! empty($configured['read']) || ! empty($configured['write'])
            || $db->getConfig('read') || $db->getConfig('write') || is_array($db->getConfig('host'))
            || $db->transactionLevel() !== 0) throw new RuntimeException('UNAVAILABLE');
        $pdo = $db->getPdo();
        if ($pdo->inTransaction() || $pdo->getAttribute(PDO::ATTR_PERSISTENT)
            || ($db->getRawReadPdo() !== null && $db->getRawReadPdo() !== $pdo)) throw new RuntimeException('UNAVAILABLE');
        $session = (string) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        if (! ctype_digit($session)) throw new RuntimeException('UNAVAILABLE');
        // Reconnection could escape the read-only transaction or change the observation target.
        $db->setReconnector(static function (): never { throw new RuntimeException('UNAVAILABLE'); });
        if (count(app('db')->getConnections()) !== 1) throw new RuntimeException('UNAVAILABLE');
        app('events')->listen(Illuminate\Database\Events\ConnectionEstablished::class, static function ($connection) use ($db): void {
            if ($connection->connection !== $db) throw new RuntimeException('UNAVAILABLE');
        });
        $db->beforeExecuting(static function () use ($guard): void { $guard(); });
        $db->beforeStartingTransaction(static function () use ($guard): void { $guard(); });
        $guard();
        HorusMainReportingLeaseObservation::beginReadOnlyTransaction($db, $sessionProbeEnabled);
        $guard();
    });
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! $db || $db->transactionLevel() !== 1 || ! $pdo->inTransaction()) throw new RuntimeException('UNAVAILABLE');
    $guard();
    $events = array_values(array_filter(app(Illuminate\Console\Scheduling\Schedule::class)->events(),
        static fn ($event): bool => HorusMainReportingLeaseObservation::isMainCommand((string) ($event->command ?? ''))));
    if (count($events) !== 1 || ! $events[0]->withoutOverlapping
        || ! $events[0]->mutex instanceof Illuminate\Console\Scheduling\CacheEventMutex) throw new RuntimeException('UNAVAILABLE');
    $event = $events[0];
    $scheduleStoreName = $event->mutex->store ?? $originalScheduleStore;
    $scheduleStore = $originalCache['stores'][$scheduleStoreName] ?? [];
    $defaultStore = $originalCache['stores'][$originalCache['default']] ?? [];
    $sameConnection = static function (array $store) use ($default): bool {
        $connection = $store['connection'] ?? $default;
        return ($store['driver'] ?? null) === 'database' && $connection === $default
            && ($store['lock_connection'] ?? $connection) === $default;
    };
    // No alternative database connection is opened by this observation.
    if (! $sameConnection($scheduleStore) || ! $sameConnection($defaultStore)) throw new RuntimeException('UNAVAILABLE');
    $key = (string) ($scheduleStore['prefix'] ?? $originalCache['prefix']).$event->mutexName();
    $lockTable = $scheduleStore['lock_table'] ?? 'cache_locks';
    if (! is_string($lockTable) || ! preg_match('/^[A-Za-z0-9_]+$/D', $lockTable)) throw new RuntimeException('UNAVAILABLE');
    // Query expiration directly. Cache get/has/exists may delete expired data or acquire a lock.
    $lock = $db->table($lockTable)->where('key', $key)->first(['expiration']);
    $expiration = $lock === null ? null : filter_var($lock->expiration, FILTER_VALIDATE_INT);
    if ($expiration === false) throw new RuntimeException('UNAVAILABLE');
    $lease = HorusMainReportingLeaseObservation::lease($expiration, time(), $event->expiresAt);
    $sql = 'SELECT CONNECTION_ID() AS session_id, @@GLOBAL.read_only AS read_only, DATABASE() IS NOT NULL AS database_selected';
    $first = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    $second = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);
    $host = strtolower(trim((string) $db->getConfig('host')));
    $checks = [
        'nonpersistent_session' => ! $pdo->getAttribute(PDO::ATTR_PERSISTENT),
        'no_read_write_split' => $db->getRawReadPdo() === null || $db->getRawReadPdo() === $pdo,
        'same_database_cache_connection' => $sameConnection($defaultStore) && $sameConnection($scheduleStore),
        'local_endpoint_configured' => (string) $db->getConfig('unix_socket') !== '' || in_array($host, ['localhost', '127.0.0.1', '::1'], true),
        'stable_session_observed' => is_array($first) && is_array($second)
            && (string) $first['session_id'] === $session && (string) $second['session_id'] === $session,
        'writable_current_database' => is_array($second) && (int) $second['read_only'] === 0 && (int) $second['database_selected'] === 1,
        'read_only_bootstrap_guard' => $db->transactionLevel() === 1 && $pdo->inTransaction(),
    ];
    $metadata = ['method' => 'CLI', 'route' => null, 'schema' => HorusMainReportingLeaseObservation::SCHEMA, 'operation_id' => $operation,
        'observed_release' => $snapshot['release'], 'lease' => $lease,
        'configuration_status' => in_array(false, $checks, true) ? 'FAIL' : 'PASS',
        'configuration_checks' => array_map(static fn (bool $pass): string => $pass ? 'PASS' : 'FAIL', $checks),
        'transport_class' => str_contains(strtolower((string) $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)), 'unix socket')
            ? 'LOCAL_UNIX_SOCKET_OBSERVED' : 'NON_SOCKET_TRANSPORT',
        // One connection configuration cannot inventory other writers or prove proxy behavior.
        'single_writer_inventory' => 'UNPROVEN', 'nonmultiplexed_topology' => 'UNPROVEN',
        'historical_financial_data' => 'NOT_QUERIED', 'reporting_mutex_mutation' => 'NONE'];
    $existing = App\Models\AuditLog::query()->find($auditId);
    if ($existing && ! HorusMainReportingLeaseObservation::matchesExisting($existing, $operation)) throw new RuntimeException('UNAVAILABLE');
    $guard();
    $db->rollBack();
    $auditStatus = 'ALREADY_RECORDED';
    if ($existing) {
        $metadata = $existing->metadata;
    } else {
        if (! $checks['stable_session_observed'] || ! $checks['writable_current_database']) throw new RuntimeException('UNAVAILABLE');
        // End the read-only bootstrap before the one permitted INSERT. Restore the real cache
        // configuration, but never invoke a cache method or any application command.
        config(['cache' => $originalCache]);
        $auditTable = $db->getQueryGrammar()->wrapTable((new App\Models\AuditLog)->getTable());
        $insertAvailable = true;
        $db->beforeExecuting(static function (string $query) use ($auditTable, &$insertAvailable): void {
            if (preg_match('/^\s*select\b/i', $query)) return;
            if ($insertAvailable && str_starts_with(strtolower($query), 'insert into '.strtolower($auditTable).' ')) {
                $insertAvailable = false;
                return;
            }
            throw new RuntimeException('UNAVAILABLE');
        });
        App\Models\AuditLog::creating(static function (App\Models\AuditLog $audit) use ($auditId, $operation): void {
            if (! HorusMainReportingLeaseObservation::matchesExisting($audit, $operation)) throw new RuntimeException('UNAVAILABLE');
            $audit->id = $auditId;
        });
        app('events')->listen(Illuminate\Database\Events\TransactionCommitting::class, static function ($transaction) use ($db, $guard): void {
            if ($transaction->connection !== $db) throw new RuntimeException('UNAVAILABLE');
            $guard();
        });
        $guard();
        $db->beginTransaction();
        try {
            // A blank internal Request prevents invented actor, IP, user agent or request data.
            $audit = app(App\Services\Audit\AuditRecorder::class)->record(
                HorusMainReportingLeaseObservation::EVENT, actor: null, metadata: $metadata,
                request: new Illuminate\Http\Request);
            if ($audit->getKey() !== $auditId || ! HorusMainReportingLeaseObservation::matchesExisting($audit, $operation)) throw new RuntimeException('UNAVAILABLE');
            $guard();
            $db->commit();
            $auditStatus = 'RECORDED';
        } catch (Illuminate\Database\UniqueConstraintViolationException $collision) {
            $db->rollBack();
            $existing = App\Models\AuditLog::query()->find($auditId);
            if (! $existing || ! HorusMainReportingLeaseObservation::matchesExisting($existing, $operation)) throw new RuntimeException('UNAVAILABLE');
            $metadata = $existing->metadata;
        }
    }
    $guard();
    $verified = App\Models\AuditLog::query()->find($auditId);
    if (! $verified || ! HorusMainReportingLeaseObservation::matchesExisting($verified, $operation)
        || $verified->metadata != $metadata) throw new RuntimeException('UNAVAILABLE');
    $guard();
    $result = HorusMainReportingLeaseObservation::publicResult($metadata, $auditStatus);
    while (ob_get_level() > $initialBufferLevel) ob_end_clean();
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable) {
    try { if ($db && $db->transactionLevel() > 0) $db->rollBack(); } catch (Throwable) {}
    while (ob_get_level() > $initialBufferLevel) ob_end_clean();
    echo '{"schema_version":1,"observation":"MAIN_REPORTING_LEASE","audit_status":"UNAVAILABLE","lease_status":"UNAVAILABLE","configuration_status":"UNAVAILABLE","global_topology":"UNPROVEN"}'.PHP_EOL;
    exit(1);
}
