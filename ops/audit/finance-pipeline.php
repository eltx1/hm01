<?php
// Aggregate-only, read-only monthly-close diagnosis. No money or identities are selected.
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
    $rows = [];
    foreach (App\Models\FinancialPeriod::query()->orderByDesc('starts_on')->limit(24)->get() as $period) {
        $ready = $period->isClosed() ? ['ready' => true, 'blockers' => [], 'counts' => []]
            : app(App\Services\Reporting\FinancialPeriodService::class)->readiness($period);
        $reasons = [];
        $demandEvidence = [];
        foreach ($ready['blockers'] as $blocker) {
            foreach ($blocker['subjects'] ?? [] as $subject) {
                if ($subject['subject_type'] === 'DEMAND_ACCOUNT') {
                    $account = App\Models\DemandAccount::withoutGlobalScopes()->with('network')->findOrFail($subject['subject_id']);
                    if (count($demandEvidence) >= 100) throw new RuntimeException('Evidence limit exceeded');
                    $demandEvidence[] = horusFinanceDemandEvidence($account, $period);
                }
                foreach ($subject['reasons'] ?? [] as $reason) {
                    $key = $subject['subject_type'].':'.$reason['code'];
                    $reasons[$key] = ($reasons[$key] ?? 0) + 1;
                }
            }
        }
        $groups = $db->table('daily_reports as d')
            ->join('report_source_connections as c', 'c.id', '=', 'd.report_source_connection_id')
            ->join('report_dimensions as dim', 'dim.id', '=', 'd.report_dimension_id')
            ->where('d.financial_period_id', $period->id)
            ->selectRaw('c.connection_type, d.finality, d.settlement_eligible, COUNT(*) AS rows_count, SUM(CASE WHEN dim.publisher_id IS NOT NULL THEN 1 ELSE 0 END) AS publisher_rows, MIN(d.report_date) AS first_date, MAX(d.report_date) AS last_date')
            ->groupBy('c.connection_type', 'd.finality', 'd.settlement_eligible')->get();
        $rows[] = [
            'period' => $period->period_key, 'currency' => $period->currency, 'status' => $period->status->value,
            'ready' => $ready['ready'], 'counts' => $ready['counts'],
            'blockers' => array_column($ready['blockers'], 'code'), 'subject_reasons' => $reasons,
            'statement_count' => $period->statements()->count(), 'fact_groups' => $groups->all(),
            'demand_evidence' => $demandEvidence,
        ];
    }
    $db->rollBack();
    echo json_encode(['schema_version' => 2, 'release' => $release, 'periods' => $rows], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    if (isset($db) && $db && $db->transactionLevel() > 0) $db->rollBack();
    fwrite(STDERR, "Read-only finance diagnosis unavailable.\n");
    exit(1);
}
