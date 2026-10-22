<?php
/** Fixed diagnostic classes only: never return provider messages, account IDs, URLs or amounts. */
function horusReportErrorClass(string $message): string
{
    if ($message === '') return 'NONE';
    foreach ([
        'AUTHORIZATION' => ['invalid_grant', 'AuthenticationError', 'PERMISSION_DENIED', 'PermissionError', 'UNAUTHORIZED', 'unauthorized_client'],
        'CURRENCY_PROOF' => ['unexpected currency', 'canonical currency', 'converted revenue'],
        'INVALID_MONEY' => ['invalid or oversized revenue'],
        'NETWORK_IDENTITY' => ['network identity or timezone'],
        'REPORT_WINDOW' => ['dates or connection do not match', 'precede the exact-site reporting cutover'],
        'REPORT_DIMENSIONS' => ['COLUMNS_NOT_SUPPORTED', 'DIMENSIONS_NOT_SUPPORTED', 'INVALID_TIME_ZONE_TYPE', 'NOT_NULL'],
        'CSV_SHAPE' => ['missing required dimensions', 'incomplete CSV row', 'no CSV header', 'duplicate date/hour/site', 'invalid or oversized integer'],
        'REPORT_SCOPE' => ['report scope changed', 'pending Google report does not prove', 'data outside the selected ad unit', 'another reporting scope'],
        'DOWNLOAD' => ['could not be downloaded', 'invalid report download location'],
        'GOOGLE_JOB' => ['could not complete the ad-unit report', 'report preparation expired'],
        'LOCK' => ['lock', 'mutex', 'lease'],
        'QUOTA' => ['QuotaError', 'RATE_EXCEEDED', 'RESOURCE_EXHAUSTED'],
    ] as $class => $patterns) {
        foreach ($patterns as $pattern) if (stripos($message, $pattern) !== false) return $class;
    }
    return 'OTHER';
}
if (defined('HORUS_REPORT_DIAGNOSTIC_TEST_ONLY') && HORUS_REPORT_DIAGNOSTIC_TEST_ONLY === true) return;
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    $expected = (string) getenv('HM_EXPECTED_SHA');
    $marker = (string) @file_get_contents('.horus-release');
    preg_match('/^release_id=([a-f0-9]{40})$/m', $marker, $match);
    $release = $match[1] ?? '';
    if (!preg_match('/^[a-f0-9]{40}$/D', $expected) || !hash_equals($expected, $release)) {
        throw new RuntimeException('Release mismatch');
    }
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $db = null;
    $app->afterBootstrapping(Illuminate\Foundation\Bootstrap\LoadConfiguration::class, function ($app): void {
        $config = $app->make('config');
        // Failures and deprecations must not create production log files.
        $config->set(['logging.default' => 'null', 'logging.deprecations.channel' => 'null', 'app.debug' => false]);
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

    $groups = [];
    foreach (['MAIN' => App\Models\SiteGamReportBinding::class, 'VIDEO' => App\Models\SiteGamVideoReportBinding::class] as $channel => $model) {
        $bindings = $model::withoutGlobalScopes()->with(['connection.source', 'gamConnection'])->limit(1001)->get();
        if ($bindings->count() > 1000) throw new RuntimeException('Snapshot limit exceeded');
        foreach ($bindings as $binding) {
            $connection = $binding->connection;
            if (!$connection) { $class = 'MISSING_CONNECTION'; $network = 'OTHER'; $window = 'UNKNOWN'; $freshness = 'NONE'; }
            else {
                $currency = data_get($connection->configuration, 'source_network_currency');
                $network = $currency === 'GBP' ? 'GBP' : ($currency === 'AED' ? 'AED' : 'OTHER');
                $today = Carbon\CarbonImmutable::now($connection->timezone)->toDateString();
                $start = max($binding->starts_on->toDateString(), (string) data_get($connection->configuration, 'site_report_scope.effective_from', $binding->starts_on->toDateString()));
                $window = $start > $today ? 'FUTURE' : ($binding->ends_on && $binding->ends_on->toDateString() < $today ? 'ENDED' : 'CURRENT');
                $class = horusReportErrorClass((string) $connection->last_error);
                if (!$connection->is_enabled || !$connection->source?->is_enabled || !$binding->gamConnection?->is_enabled) $class = 'DISABLED';
                elseif ($connection->currency !== config('reporting.canonical_currency', 'USD')) $class = 'NON_CANONICAL_CURRENCY';
                elseif ((string) $binding->gamConnection->network_code !== (string) $binding->network_code) $class = 'NETWORK_IDENTITY';
                $latest = App\Models\DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $connection->id)
                    ->whereHas('import', fn ($q) => $q->where('status', 'COMPLETED'))->max('report_date');
                $freshness = $latest === null ? 'NONE' : ($latest >= $today ? 'TODAY' : ($latest >= Carbon\CarbonImmutable::parse($today)->subDay()->toDateString() ? 'YESTERDAY' : 'OLDER'));
            }
            $key = implode('|', [$channel, $network, $binding->active_site_id ? 'ACTIVE' : 'HISTORICAL', $window, $class, $freshness]);
            $groups[$key] = ($groups[$key] ?? 0) + 1;
        }
    }
    $private = horusReportingPrivateEvidence();
    $db->rollBack();
    $sealed = horusFinanceSealEvidence($private, base64_decode((string) getenv('HM_REPORT_DIAGNOSTIC_PUBLIC_KEY'), true) ?: '');
    ksort($groups);
    echo json_encode(['schema_version' => 1, 'release' => $release, 'groups' => (object) $groups, 'private_evidence' => $sealed], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if (isset($db) && $db && $db->transactionLevel() > 0) $db->rollBack();
    fwrite(STDERR, "Read-only reporting diagnosis unavailable.\n");
    exit(1);
}
