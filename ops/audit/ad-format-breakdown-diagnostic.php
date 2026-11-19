<?php

declare(strict_types=1);

/**
 * Read-only post-deployment check of "Breakdown by ad format".
 *
 * Reads stored sync checkpoints, format rows and ledger facts only. It never
 * calls Google, never writes, and prints closed codes and counts only: no
 * money, hostnames, ad unit IDs or publisher identities reach public logs.
 */
ini_set('display_errors', '0');
$print = static fn (array $result) => print(json_encode(['schema_version' => 1, 'diagnostic' => 'AD_FORMAT_BREAKDOWN'] + $result, JSON_UNESCAPED_SLASHES).PHP_EOL);
try {
    $expected = getenv('HORUS_EXPECTED_RELEASE');
    $marker = @file_get_contents(getcwd().'/.horus-release');
    if (! is_string($expected) || ! preg_match('/^[a-f0-9]{40}$/D', $expected)
        || ! is_string($marker) || ! in_array('release_id='.$expected, explode("\n", trim($marker)), true)) {
        $print(['reason' => 'RELEASE_MISMATCH']);

        return;
    }
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! Illuminate\Support\Facades\Schema::hasTable('site_gam_ad_format_reports')) {
        $print(['reason' => 'MIGRATION_MISSING']);

        return;
    }
    $unscoped = fn ($query) => $query->withoutGlobalScopes();
    $bindings = App\Models\SiteGamReportBinding::withoutGlobalScopes()
        ->with(['connection' => $unscoped, 'site' => $unscoped])
        ->whereNotNull('active_site_id')->whereHas('connection', fn ($q) => $q->withoutGlobalScopes()->where('is_enabled', true))
        ->orderBy('id')->limit(200)->get();
    $states = [];
    $errors = [];
    $formats = [];
    $daysCompared = 0;
    $daysExact = 0;
    $daysOffByRounding = 0;
    $daysDifferent = 0;
    $rows = 0;
    $sync = app(App\Services\Reporting\SiteGamAdFormatSynchronizer::class);
    foreach ($bindings as $binding) {
        $status = $sync->status($binding->site);
        $states[$status['state']] = ($states[$status['state']] ?? 0) + 1;
        $error = data_get($binding->connection->configuration, App\Services\Reporting\SiteGamAdFormatSynchronizer::STATE_KEY.'.error');
        if (is_string($error)) $errors[$error] = ($errors[$error] ?? 0) + 1;
        $formatRows = App\Models\SiteGamAdFormatReport::withoutGlobalScopes()
            ->where('report_source_connection_id', $binding->report_source_connection_id);
        $latest = (clone $formatRows)->max('report_date');
        // The 14 most recent days that have format data.
        $stored = $latest === null ? collect() : (clone $formatRows)
            ->whereDate('report_date', '>=', Carbon\CarbonImmutable::parse($latest)->subDays(13)->toDateString())->get();
        $rows += $stored->count();
        foreach ($stored->pluck('ad_format')->unique() as $format) $formats[$format] = ($formats[$format] ?? 0) + 1;
        $scopeFrom = (string) data_get($binding->connection->configuration, 'site_report_scope.effective_from', '9999-12-31');
        $today = Carbon\CarbonImmutable::now($binding->connection->timezone)->toDateString();
        foreach ($stored->groupBy(fn ($row) => $row->report_date->toDateString()) as $day => $dayRows) {
            // Completed exact-site Ad Exchange days only; today's estimates move during the day.
            if ($day < $scopeFrom || $day >= $today) continue;
            $ledger = App\Models\DailyReport::withoutGlobalScopes()
                ->where('report_source_connection_id', $binding->report_source_connection_id)
                ->whereDate('report_date', $day)->get();
            if ($ledger->isEmpty()) continue;
            $micros = (int) $dayRows->sum('revenue_micros');
            $formatMinor = ($micros < 0 ? -1 : 1) * intdiv(abs($micros) + 5000, 10000);
            $difference = abs($formatMinor - (int) $ledger->sum('gross_revenue_minor'));
            $daysCompared++;
            if ($difference === 0) $daysExact++;
            elseif ($difference <= 1) $daysOffByRounding++;
            else $daysDifferent++;
        }
    }
    ksort($states);
    ksort($errors);
    ksort($formats);
    $print([
        'reason' => 'OK',
        'active_bindings' => $bindings->count(),
        'states' => $states,
        'errors' => $errors,
        'google_accepted_format_dimension' => $rows > 0 ? true : (($errors['FORMAT_DIMENSION_UNSUPPORTED'] ?? 0) > 0 ? false : null),
        'rows_last_14_days' => $rows,
        // Google format keys (e.g. BANNER, INTERSTITIAL) with the number of websites that report them.
        'formats_by_website_count' => $formats,
        'completed_days_compared_with_ledger' => $daysCompared,
        'days_exact_match' => $daysExact,
        'days_within_one_cent' => $daysOffByRounding,
        'days_different' => $daysDifferent,
    ]);
} catch (Throwable $error) {
    $print(['reason' => 'EXECUTION_FAILED', 'class' => (new ReflectionClass($error))->getShortName()]);
}
