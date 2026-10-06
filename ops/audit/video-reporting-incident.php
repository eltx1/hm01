<?php
/** Read-only, bounded incident evidence. Never emit amounts, private IDs or errors. */
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    $expected = (string) getenv('HM_EXPECTED_SHA');
    $marker = (string) @file_get_contents('.horus-release');
    preg_match('/^release_id=([a-f0-9]{40})$/m', $marker, $match);
    if (!preg_match('/^[a-f0-9]{40}$/D', $expected) || ($match[1] ?? '') !== $expected) {
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
    $stamp = static fn ($v) => $v ? (string) $v : null;
    $state = static fn ($v) => $v === null ? 'missing' : ((int) $v === 0 ? 'zero' : ((int) $v > 0 ? 'positive' : 'negative'));
    $heartbeat = $db->table('system_heartbeats')->where('key', 'scheduler')->first(['last_seen_at', 'status']);
    $report = [
        'schema_version' => 1, 'release_sha' => $expected, 'observed_at_utc' => gmdate('c'),
        'php_version' => PHP_VERSION, 'storage_writable' => is_writable('storage'),
        'bootstrap_cache_writable' => is_writable('bootstrap/cache'),
        'scheduler_last_seen_at' => $stamp($heartbeat?->last_seen_at),
        'scheduler_age_seconds' => $heartbeat?->last_seen_at ? max(0, $now - strtotime($heartbeat->last_seen_at.' UTC')) : null,
        'cache_driver' => $originalCache['default'], 'schedule_events' => [],
    ];
    $cron = function_exists('shell_exec') ? @shell_exec('crontab -l 2>/dev/null') : null;
    $report['cron_readable'] = is_string($cron);
    $report['scheduler_cron_entries'] = [];
    foreach (explode("\n", (string) $cron) as $line) {
        if (preg_match('/^\s*#/', $line) || !str_contains($line, 'artisan schedule:run')) continue;
        $entry = ['every_minute' => preg_match('/^\s*\*\s+\*\s+\*\s+\*\s+\*\s+/', $line) === 1];
        if (preg_match('~(/[A-Za-z0-9/_.-]+php[0-9.]*)\s+(/[A-Za-z0-9/_.-]+artisan)\s+schedule:run~', $line, $paths)) {
            $entry['php_executable'] = is_executable($paths[1]);
            $entry['artisan_exists'] = is_file($paths[2]);
            $entry['artisan_matches_current_release'] = realpath($paths[2]) === realpath('artisan');
        } else $entry['path_parse_available'] = false;
        $report['scheduler_cron_entries'][] = $entry;
    }
    // Query lock rows directly; the cache API can evict expired entries and is excluded.
    foreach (app(Illuminate\Console\Scheduling\Schedule::class)->events() as $event) {
        $command = (string) ($event->command ?? '');
        foreach (['operations:heartbeat scheduler', 'reporting:sync-site-gam-video', 'reporting:sync-site-gam', 'static-delivery:process'] as $name) {
            if (!str_contains($command, $name) || ($name === 'reporting:sync-site-gam' && str_contains($command, 'reporting:sync-site-gam-video'))) continue;
            $entry = ['command' => $name, 'expression' => $event->expression, 'without_overlapping' => (bool) $event->withoutOverlapping];
            $storeName = $event->mutex->store ?? $originalScheduleStore;
            $store = $originalCache['stores'][$storeName] ?? [];
            $entry['lock_read_available'] = ($store['driver'] ?? null) === 'database';
            if ($entry['lock_read_available']) {
                $cacheDb = Illuminate\Support\Facades\DB::connection($store['lock_connection'] ?? $store['connection'] ?? null);
                $key = (string) ($store['prefix'] ?? $originalCache['prefix']).$event->mutexName();
                $lock = $cacheDb->table($store['lock_table'] ?? 'cache_locks')->where('key', $key)->first(['expiration']);
                $entry['lock_exists'] = $lock !== null;
                $entry['lock_expires_in_seconds'] = $lock ? (int) $lock->expiration - $now : null;
            }
            $report['schedule_events'][] = $entry;
        }
    }
    $sites = $db->table('sites')->where('primary_domain', 'natega.bluekl.com')->whereNull('deleted_at')->get(['id', 'organization_id']);
    $report['site_match_count'] = $sites->count();
    $report['video_bindings'] = [];
    if ($sites->count() === 1) {
        $site = $sites->first();
        $mainBindings = $db->table('site_gam_report_bindings')->where('site_id', $site->id)->where('organization_id', $site->organization_id)->whereNotNull('active_site_id')->get(['network_code', 'ad_unit_code', 'report_source_connection_id']);
        $report['main_served_path_matches_binding'] = $mainBindings->count() === 1
            && (string) $mainBindings->first()->network_code === '23055873217'
            && (string) $mainBindings->first()->ad_unit_code === 'natega.bluekl.com';
        $report['main_days'] = [];
        if ($mainBindings->count() === 1) {
            $mainRows = $db->table('daily_reports as r')->join('report_dimensions as d', 'd.id', '=', 'r.report_dimension_id')
                ->where('r.organization_id', $site->organization_id)->where('d.organization_id', $site->organization_id)
                ->where('d.site_id', $site->id)->where('r.report_source_connection_id', $mainBindings->first()->report_source_connection_id)
                ->whereBetween('r.report_date', ['2026-10-04', '2026-10-06'])
                ->get(['r.report_date', 'r.finality', 'r.ad_requests', 'r.matched_requests', 'r.impressions', 'r.gross_revenue_minor', 'r.updated_at', 'd.external_dimensions']);
            foreach ($mainRows as $row) {
                $external = json_decode($row->external_dimensions ?? '{}', true) ?: [];
                $report['main_days'][] = ['date' => $row->report_date, 'finality' => $row->finality,
                    'requests_state' => $state($row->ad_requests), 'responses_state' => $state($row->matched_requests),
                    'impressions_state' => $state($row->impressions),
                    'match_rate_category' => $row->ad_requests === null || $row->matched_requests === null
                        || $row->ad_requests < 0 || $row->matched_requests < 0 || $row->matched_requests > $row->ad_requests
                        || ($external['gam_report_basis'] ?? '') !== 'AD_EXCHANGE_V1'
                        || ($external['gam_report_site'] ?? '') !== 'natega.bluekl.com'
                        ? 'UNAVAILABLE' : ((int) $row->ad_requests === 0 ? 'NO_REQUESTS'
                            : ((int) $row->matched_requests === 0 ? 'ZERO_RESPONSES'
                                : ($row->matched_requests * 100 < $row->ad_requests ? 'BELOW_1_PERCENT'
                                    : ($row->matched_requests * 10 < $row->ad_requests ? 'BELOW_10_PERCENT' : 'AT_LEAST_10_PERCENT')))),
                    'gross_state' => $state($row->gross_revenue_minor), 'updated_at' => $row->updated_at,
                    'basis_valid' => ($external['gam_report_basis'] ?? '') === 'AD_EXCHANGE_V1',
                    'hostname_matches' => ($external['gam_report_site'] ?? '') === 'natega.bluekl.com'];
            }
        }

        $bindings = $db->table('site_gam_video_report_bindings')->where('site_id', $site->id)->where('organization_id', $site->organization_id)->get();
        foreach ($bindings as $binding) {
            $connection = $db->table('report_source_connections')->where('id', $binding->report_source_connection_id)->where('organization_id', $site->organization_id)->first();
            if (!$connection) continue;
            $configuration = json_decode($connection->configuration ?? '{}', true) ?: [];
            $scope = $configuration['site_report_scope'] ?? [];
            $entry = [
                'active' => $binding->active_site_id !== null, 'enabled' => (bool) $connection->is_enabled,
                'starts_on' => $binding->starts_on, 'ends_on' => $binding->ends_on,
                'status' => $connection->status, 'timezone' => $connection->timezone, 'currency' => $connection->currency,
                'scope_hostname_matches' => ($scope['hostname'] ?? '') === 'natega.bluekl.com',
                'scope_effective_from' => $scope['effective_from'] ?? null,
                'last_attempted_at' => $connection->last_attempted_at,
                'last_successful_import_at' => $connection->last_successful_import_at,
                'has_error' => trim((string) $connection->last_error) !== '',
                'imports' => [], 'days' => [],
            ];
            $imports = $db->table('report_import_jobs')->where('organization_id', $site->organization_id)
                ->where('report_source_connection_id', $connection->id)->where('created_at', '>=', '2026-10-05 00:00:00')
                ->orderByDesc('created_at')->limit(12)->get(['status', 'period_start', 'period_end', 'finality', 'created_at', 'completed_at', 'next_retry_at']);
            foreach ($imports as $import) $entry['imports'][] = (array) $import;
            $rows = $db->table('daily_reports as r')->join('report_dimensions as d', 'd.id', '=', 'r.report_dimension_id')
                ->where('r.organization_id', $site->organization_id)->where('d.organization_id', $site->organization_id)
                ->where('d.site_id', $site->id)->where('r.report_source_connection_id', $connection->id)
                ->whereBetween('r.report_date', ['2026-10-05', '2026-10-06'])
                ->get(['r.report_date', 'r.finality', 'r.currency', 'r.impressions', 'r.gross_revenue_minor', 'r.publisher_earnings_minor', 'r.updated_at', 'd.external_dimensions']);
            foreach ($rows as $row) {
                $external = json_decode($row->external_dimensions ?? '{}', true) ?: [];
                $entry['days'][] = ['date' => $row->report_date, 'finality' => $row->finality, 'currency' => $row->currency,
                    'impressions_state' => $state($row->impressions), 'gross_state' => $state($row->gross_revenue_minor),
                    'publisher_state' => $state($row->publisher_earnings_minor), 'updated_at' => $row->updated_at,
                    'basis_valid' => ($external['gam_report_basis'] ?? '') === 'AD_EXCHANGE_V1',
                    'hostname_matches' => ($external['gam_report_site'] ?? '') === 'natega.bluekl.com'];
            }
            $report['video_bindings'][] = $entry;
        }
    }
    $db->rollBack();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    // No raw exception messages: SQL and transport errors can contain private values.
    file_put_contents('php://stderr', 'Read-only incident audit failed: '.get_class($exception).PHP_EOL);
    exit(1);
}
