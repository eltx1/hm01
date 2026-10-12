<?php
// Incident-scoped Google report preview. Only API audit records may be written.
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
    $db->rollBack();
    if (now()->utc()->gte('2026-10-15')) throw new RuntimeException('Incident window expired');
    $db->beforeExecuting(function (string $sql) use ($db): void {
        if (!horusFinancePreviewSqlAllowed($sql, $db->getTablePrefix())) throw new RuntimeException('Preview write outside API audit');
    });
    $binding = App\Models\SiteGamVideoReportBinding::withoutGlobalScopes()
        ->whereHas('site', fn ($q) => $q->withoutGlobalScopes()->where('primary_domain', 'natega.bluekl.com'))
        ->where('ad_unit_code', 'video-bluekl.com')->whereNotNull('active_site_id')->sole();
    $evidence = horusFinanceVideoPreview($binding, 'c28435da8df8d5adde9e0aadc671912ac1af7869b433a110c6e8eba5fe3163db');
    $sealed = horusFinanceSealEvidence($evidence, base64_decode((string) getenv('HM_FINANCE_DIAGNOSTIC_PUBLIC_KEY'), true) ?: '');
    echo json_encode(['schema_version' => 3, 'release' => $release, 'periods' => [], 'private_evidence' => $sealed], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if (isset($db) && $db && $db->transactionLevel() > 0) $db->rollBack();
    fwrite(STDERR, "Read-only finance diagnosis unavailable.\n");
    exit(1);
}
