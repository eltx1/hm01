<?php
/** Read-only generic scheduler classification. No tenant, reporting, financial or source data is read. */
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    $expected = (string) getenv('HM_EXPECTED_SHA');
    $marker = (string) @file_get_contents('.horus-release');
    preg_match('/^release_id=([a-f0-9]{40})$/m', $marker, $match);
    $previous = (string) getenv('HM_ALLOWED_PREVIOUS_SHA');
    $release = $match[1] ?? '';
    if (!preg_match('/^[a-f0-9]{40}$/D', $expected) || !in_array($release, [$expected, '554be0bf91ecbf5136999db636704093e9c58f8c'], true)
        || ($release !== $expected && $previous !== '554be0bf91ecbf5136999db636704093e9c58f8c')) {
        throw new RuntimeException('Release mismatch');
    }
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $db = null;
    $originalCache = [];
    $originalScheduleStore = null;
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app) use (&$originalCache, &$originalScheduleStore): void {
        $config = $app->make('config');
        // Failures and deprecations must not create production log files.
        $config->set(['logging.default' => 'null', 'logging.deprecations.channel' => 'null', 'app.debug' => false]);
        $originalCache = $config->get('cache');
        $originalScheduleStore = $config->get('cache.schedule_store', Illuminate\Support\Env::get('SCHEDULE_CACHE_DRIVER',
            static fn () => Illuminate\Support\Env::get('SCHEDULE_CACHE_STORE'))) ?? $originalCache['default'];

        // RegisterFacades/Providers can rebuild missing or stale manifests. Abort instead.
        if (!$app->make('config_loaded_from_cache')) throw new RuntimeException('Cached configuration required');
        $packagesPath = $app->getCachedPackagesPath();
        $servicesPath = $app->getCachedServicesPath();
        if (!is_file($packagesPath) || !is_readable($packagesPath)
            || !is_file($servicesPath) || !is_readable($servicesPath)) throw new RuntimeException('Cached manifests required');
        $packages = require $packagesPath;
        $services = require $servicesPath;
        if (!is_array($packages) || !is_array($services)
            || !is_array($services['providers'] ?? null)) throw new RuntimeException('Invalid cached manifests');
        $manifest = $app->make(Illuminate\Foundation\PackageManifest::class);
        $manifest->manifest = $packages;
        $providers = (new Illuminate\Support\Collection($config->get('app.providers')))
            ->partition(static fn ($provider) => str_starts_with($provider, 'Illuminate\\'));
        $providers->splice(1, 0, [$manifest->providers()]);
        if ($services['providers'] != $providers->collapse()->toArray()) throw new RuntimeException('Stale service manifest');
    });
    $app->beforeBootstrapping(Illuminate\Foundation\Bootstrap\BootProviders::class, function () use (&$db): void {
        // Settings provider uses remember() during boot. Keep that cache process-only.
        config(['cache.default' => 'array']);
        $db = Illuminate\Support\Facades\DB::connection();
        if ($db->getDriverName() !== 'mysql') throw new RuntimeException('Expected production MySQL');
        $db->statement('SET TRANSACTION READ ONLY');
        $db->beginTransaction();
    });
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (!$db) throw new RuntimeException('Read-only bootstrap guard was not applied');
    $now = time();
    $heartbeat = $db->table('system_heartbeats')->where('key', 'scheduler')->first(['last_seen_at']);
    $age = $heartbeat?->last_seen_at ? max(0, $now - strtotime($heartbeat->last_seen_at.' UTC')) : null;
    $report = [
        'schema_version' => 1, 'diagnostic' => 'SCHEDULER_HEALTH',
        'scheduler_status' => $age === null ? 'MISSING' : ($age <= 300 ? 'FRESH' : 'STALE'),
        'storage_status' => is_writable('storage') ? 'READY' : 'NOT_WRITABLE',
        'bootstrap_cache_status' => is_writable('bootstrap/cache') ? 'READY' : 'NOT_WRITABLE',
        'cron_status' => 'UNAVAILABLE', 'mutex_statuses' => [],
    ];
    $cron = function_exists('shell_exec') ? @shell_exec('crontab -l 2>/dev/null') : null;
    if (is_string($cron)) {
        $entries = [];
        foreach (explode("\n", $cron) as $line) {
            if (preg_match('/^\s*#/', $line) || !str_contains($line, 'artisan schedule:run')) continue;
            $valid = preg_match('/^\s*\*\s+\*\s+\*\s+\*\s+\*\s+/', $line) === 1;
            if (preg_match('~(/[A-Za-z0-9/_.-]+php[0-9.]*)\s+(/[A-Za-z0-9/_.-]+artisan)\s+schedule:run~', $line, $paths)) {
                $valid = $valid && is_executable($paths[1]) && is_file($paths[2]) && realpath($paths[2]) === realpath('artisan');
                $entries[] = $valid ? 'VALID_TARGET' : 'INVALID_TARGET';
            } else $entries[] = 'UNCLASSIFIED';
        }
        $report['cron_status'] = count($entries) === 0 ? 'NO_USER_ENTRY'
            : (count($entries) > 1 ? 'MULTIPLE' : ($entries[0] === 'VALID_TARGET' ? 'SINGLE_VALID_TARGET' : $entries[0]));
    }
    // Never return owners, keys, command lines, expiry timestamps or durations.
    foreach (app(Illuminate\Console\Scheduling\Schedule::class)->events() as $event) {
        $command = (string) ($event->command ?? '');
        foreach (['operations:heartbeat scheduler' => 'HEARTBEAT', 'reporting:sync-site-gam-video' => 'VIDEO_SYNC',
            'reporting:sync-site-gam' => 'MAIN_SYNC', 'static-delivery:process' => 'STATIC_DELIVERY'] as $name => $label) {
            if (!str_contains($command, $name) || ($label === 'MAIN_SYNC' && str_contains($command, 'reporting:sync-site-gam-video'))) continue;
            $storeName = $event->mutex->store ?? $originalScheduleStore;
            $store = $originalCache['stores'][$storeName] ?? [];
            $status = 'UNAVAILABLE';
            if (($store['driver'] ?? null) === 'database') {
                $cacheDb = Illuminate\Support\Facades\DB::connection($store['lock_connection'] ?? $store['connection'] ?? null);
                $key = (string) ($store['prefix'] ?? $originalCache['prefix']).$event->mutexName();
                $lock = $cacheDb->table($store['lock_table'] ?? 'cache_locks')->where('key', $key)->first(['expiration']);
                $status = !$lock ? 'ABSENT' : ((int) $lock->expiration <= $now ? 'EXPIRED' : 'ACTIVE');
            }
            $report['mutex_statuses'][$label] = $status;
        }
    }
    $db->rollBack();
    echo json_encode($report, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    file_put_contents('php://stderr', 'Read-only scheduler audit failed.'.PHP_EOL);
    exit(1);
}
