<?php
/** Incident-scoped metadata and existing-report reads; never create a report or mutate financial rows. */
function horusReportingPrivateEvidence(): array
{
    // Temporary incident scope, evaluated in UTC and disabled after this investigation.
    if (now()->utc()->gte('2026-10-26')) return [];
    $site = App\Models\Site::withoutGlobalScopes()->whereNull('deleted_at')->where('primary_domain', 'natega.bluekl.com')->first();
    if (!$site) return ['site_found' => false, 'serving_inventory' => horusServingInventoryEvidence()];
    $evidence = ['serving_inventory' => horusServingInventoryEvidence(), 'site_found' => true, 'site_id' => $site->id, 'reporting' => [], 'inventory_paths' => [], 'accounts' => []];
    // Compare the actual read models used by both screens with stored facts.
    try {
        $publisher = App\Models\Publisher::withoutGlobalScopes()->where('organization_id', $site->organization_id)->find($site->publisher_id);
        foreach ([now()->subDay()->toDateString(), now()->toDateString()] as $day) {
            $admin = app(App\Services\Reporting\AdminWebsitePerformanceService::class)->summary($site, $day, $day, true);
            $evidence['screen_models'][$day]['admin'] = Illuminate\Support\Arr::only($admin, ['available', 'updated_at', 'impressions', 'gross_revenue_minor', 'publisher_earnings_minor', 'has_estimates']);
            if ($publisher) {
                $projection = app(App\Services\Reporting\PublisherPerformanceService::class)->summary($publisher, $day, $day);
                $evidence['screen_models'][$day]['publisher'] = Illuminate\Support\Arr::only($projection, ['available', 'updated_at', 'impressions', 'earnings_minor', 'has_estimates']);
            }
        }
        $evidence['today_model'] = app(App\Services\Reporting\SiteGamTodayReport::class)->forSite($site);
    } catch (Throwable $exception) { $evidence['projection_error'] = horusReportErrorClass($exception->getMessage()); }
    foreach (App\Models\GamConnection::withoutGlobalScopes()->whereNull('deleted_at')->where('is_enabled', true)->with('networks')->limit(30)->get() as $gam) {
        $evidence['accounts'][] = ['id' => $gam->id, 'network' => $gam->network_code, 'reporting_only' => $gam->is_reporting_only,
            'metadata' => $gam->networks->map(fn ($network) => $network->only(['currency_code', 'time_zone']))->all()];
    }
    $version = App\Models\ConfigVersion::withoutGlobalScopes()->where('site_id', $site->id)->where('organization_id', $site->organization_id)
        ->where('environment', 'PRODUCTION')->whereNotNull('published_at')->latest('published_at')->first();
    if ($version) {
        $scan = function ($value) use (&$scan, &$evidence): void {
            if (is_array($value)) { foreach ($value as $item) $scan($item); return; }
            if (!is_string($value)) return;
            foreach (horusFinanceTagMetadata($value)['paths'] as $path) $evidence['inventory_paths'][] = $path;
        };
        $scan($version->payload);
        $evidence['inventory_paths'] = array_values(array_unique($evidence['inventory_paths']));
    }
    foreach ([App\Models\SiteGamReportBinding::class, App\Models\SiteGamVideoReportBinding::class] as $model) {
        foreach ($model::withoutGlobalScopes()->where('site_id', $site->id)->where('organization_id', $site->organization_id)
            ->with(['connection', 'gamConnection'])->orderByDesc('starts_on')->limit(8)->get() as $binding) {
            $connection = $binding->connection;
            if (!$connection) continue;
            $entry = [
                'channel' => $binding->connectionType(), 'active' => (bool) $binding->active_site_id,
                'binding_id' => $binding->id, 'source_id' => $connection->id, 'network' => $binding->network_code,
                'unit_id' => $binding->ad_unit_id, 'unit_code' => $binding->ad_unit_code,
                'starts_on' => $binding->starts_on->toDateString(), 'ends_on' => $binding->ends_on?->toDateString(),
                'currency' => $connection->currency, 'source_currency' => data_get($connection->configuration, 'source_network_currency'),
                'timezone' => $connection->timezone, 'scope' => data_get($connection->configuration, 'site_report_scope'),
                'source_status' => $connection->status, 'last_successful_import_at' => $connection->last_successful_import_at,
                'last_attempted_at' => $connection->last_attempted_at,
                'sync_due' => data_get($connection->configuration, 'sync_due'),
                'error_class' => horusReportErrorClass((string) $connection->last_error),
                'recent_facts' => App\Models\DailyReport::withoutGlobalScopes()->where('report_source_connection_id', $connection->id)
                    ->where('report_date', '>=', now()->subDays(7)->toDateString())->orderBy('report_date')
                    ->get(['report_date', 'finality', 'impressions', 'gross_revenue_minor', 'publisher_earnings_minor', 'currency', 'updated_at',
                        'active_view_viewable_impressions', 'active_view_measurable_impressions', 'viewability_bp'])->toArray(),
                'recent_imports' => App\Models\ReportImportJob::withoutGlobalScopes()->where('report_source_connection_id', $connection->id)
                    ->latest('created_at')->limit(8)->get()->map(fn ($job) => $job->only(['period_start', 'period_end', 'finality', 'status', 'created_at', 'completed_at', 'next_retry_at', 'warnings'])
                        + ['error_class' => horusReportErrorClass((string) $job->error_message),
                            'private_error' => mb_substr((string) $job->error_message, 0, 1000)])->all(),
            ];
            if ($binding->active_site_id && $binding->gamConnection?->is_enabled) {
                try {
                    $transport = app(App\Services\Gam\GamOfficialSoapTransport::class)->withTimeout(15);
                    $network = $transport->call($binding->gamConnection, 'NetworkService', 'getCurrentNetwork');
                    $entry['live_network'] = array_intersect_key($network, array_flip(['networkCode', 'currencyCode', 'timeZone']));
                    $job = App\Models\ReportImportJob::withoutGlobalScopes()->where('report_source_connection_id', $connection->id)
                        ->whereIn('status', ['COMPLETED', 'FAILED'])->whereNotNull('external_report_id')
                        ->orderByDesc('period_end')->latest('completed_at')->first();
                    if ($job && preg_match('/^gam-unit:(\d+):/', $job->external_report_id, $match)) {
                        $entry['report_period'] = [$job->period_start->toDateString(), $job->period_end->toDateString()];
                        $download = $transport->call($binding->gamConnection, 'ReportService', 'getReportDownloadUrlWithOptions', [
                            'reportJobId' => $match[1], 'reportDownloadOptions' => ['exportFormat' => 'CSV_DUMP',
                                'includeReportProperties' => false, 'includeTotalsRow' => false, 'useGzipCompression' => false],
                        ]);
                        $url = (string) ($download['value'] ?? ''); $parts = parse_url($url); $host = strtolower($parts['host'] ?? '');
                        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['port'])
                            || !collect(['googleapis.com', 'google.com', 'doubleclick.net'])->contains(fn ($domain) => $host === $domain || str_ends_with($host, '.'.$domain))) throw new RuntimeException('Invalid report download location');
                        $response = Illuminate\Support\Facades\Http::connectTimeout(10)->timeout(25)
                            ->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 10])->get($url);
                        if (!$response->successful()) throw new RuntimeException('Report could not be downloaded');
                        $stream = $response->toPsrResponse()->getBody(); $csv = ''; $deadline = microtime(true) + 25;
                        while (!$stream->eof()) {
                            $csv .= $stream->read(65536);
                            if (strlen($csv) > 5 * 1024 * 1024 || microtime(true) > $deadline) throw new RuntimeException('Report download limit');
                        }
                        $stream->close();
                        $entry['google_csv'] = horusReportingCsvShape($csv, 'natega.bluekl.com');
                    }
                } catch (Throwable $exception) { $entry['live_read_error'] = horusReportErrorClass($exception->getMessage()); }
            }
            $evidence['reporting'][] = $entry;
        }
    }
    return $evidence;
}

function horusReportingCsvShape(string $csv, string $hostname): array
{
    $stream = fopen('php://temp', 'w+'); fwrite($stream, $csv); rewind($stream);
    try {
        $headers = fgetcsv($stream, escape: ''); $groups = [];
        if (!is_array($headers)) throw new RuntimeException('Missing header');
        $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
        if (!in_array('Dimension.SITE_NAME', $headers, true)) throw new RuntimeException('Missing site dimension');
        while (($values = fgetcsv($stream, escape: '')) !== false) {
            if ($values === [null]) continue;
            if (count($values) !== count($headers)) throw new RuntimeException('Invalid CSV shape');
            $row = array_combine($headers, $values);
            $site = strtolower(rtrim(trim($row['Dimension.SITE_NAME']), '.'));
            $parent = implode('.', array_slice(explode('.', $hostname), 1));
            $kind = $site === $hostname ? 'EXACT' : ($site === $parent ? 'PARENT' : ($site === 'www.'.$hostname ? 'WWW' : 'OTHER'));
            $groups[$kind] ??= ['rows' => 0, 'nonzero_impressions' => false, 'nonzero_revenue' => false];
            $groups[$kind]['rows']++;
            $groups[$kind]['nonzero_impressions'] = $groups[$kind]['nonzero_impressions'] || ((int) ($row['Column.AD_EXCHANGE_LINE_ITEM_LEVEL_IMPRESSIONS'] ?? 0) !== 0);
            $money = preg_replace('/^[^\d-]*/u', '', $row['Column.AD_EXCHANGE_LINE_ITEM_LEVEL_REVENUE'] ?? '0');
            $groups[$kind]['nonzero_revenue'] = $groups[$kind]['nonzero_revenue'] || (is_numeric($money) && (float) $money !== 0.0);
        }
        return $groups;
    } finally { fclose($stream); }
}

/** Bounded platform inventory audit, encrypted by the caller; no serving or ledger writes. */
function horusServingInventoryEvidence(): array
{
    $result = ['sites' => [], 'truncated' => false];
    $sites = App\Models\Site::withoutGlobalScopes()->whereNull('deleted_at')->orderBy('id')->limit(101)->get();
    $result['truncated'] = $sites->count() > 100;
    foreach ($sites->take(100) as $site) {
        $item = ['domain' => $site->primary_domain, 'site_key' => $site->public_key, 'versions' => [], 'bindings' => []];
        $versions = App\Models\ConfigVersion::withoutGlobalScopes()
            ->where('organization_id', $site->organization_id)->where('site_id', $site->id)
            ->where('environment', 'PRODUCTION')->whereNotNull('published_at')
            ->orderByDesc('published_at')->limit(2)->get();
        foreach ($versions as $version) {
            $payload = $version->payload ?? [];
            $paths = horusServingConfigPaths($payload);
            $item['versions'][] = ['version' => $version->version, 'published_at' => $version->published_at->toIso8601String(),
                'site_key' => $payload['siteKey'] ?? null, 'hosts' => $payload['allowedHostnames'] ?? [],
                'paths' => array_slice(array_values(array_unique($paths)), 0, 100)];
        }
        foreach ([App\Models\SiteGamReportBinding::class, App\Models\SiteGamVideoReportBinding::class] as $model) {
            foreach ($model::withoutGlobalScopes()->where('organization_id', $site->organization_id)->where('site_id', $site->id)
                ->whereNotNull('active_site_id')->limit(10)->get() as $binding) {
                $item['bindings'][] = ['channel' => $binding->connectionType(), 'network' => $binding->network_code,
                    'unit_id' => $binding->ad_unit_id, 'unit_code' => $binding->ad_unit_code];
            }
        }
        if (strlen(json_encode($result, JSON_THROW_ON_ERROR)) + strlen(json_encode($item, JSON_THROW_ON_ERROR)) > 150000) {
            $result['truncated'] = true;
            break;
        }
        $result['sites'][] = $item;
    }
    return $result;
}

function horusServingConfigPaths(array $payload): array
{
    $paths = [];
    foreach ($payload['placements'] ?? [] as $placement) {
        if (!empty($placement['adUnitPath'])) $paths[] = $placement['adUnitPath'];
    }
    foreach (['directDemand', 'nativeDemand'] as $engine) {
        foreach ($payload[$engine]['placements'] ?? [] as $placement) {
            foreach ($placement['candidates'] ?? [] as $candidate) {
                $attrs = $candidate['tag']['container']['attributes'] ?? [];
                if (!empty($attrs['data-hm-gpt-ad-unit-path'])) $paths[] = $attrs['data-hm-gpt-ad-unit-path'];
                $raw = horusServingDecodeVast($attrs);
                $path = horusFinanceUrlShape($raw)['unit_path'];
                if ($path) $paths[] = $path;
            }
        }
    }
    return array_values(array_unique(array_filter($paths, fn ($path) => is_string($path) && preg_match('#^/[0-9]{1,20}(?:,[0-9]{1,20})?/[A-Za-z0-9_./-]{1,240}$#D', $path))));
}

function horusServingDecodeVast(array $attributes): string
{
    $key = 'data-hm-vast-url';
    $encoded = $attributes[$key] ?? '';
    if ($encoded === '' && isset($attributes[$key.'-parts'])) {
        $count = filter_var($attributes[$key.'-parts'], FILTER_VALIDATE_INT);
        if ($count === false || $count < 1 || $count > 8) return '';
        for ($i = 0; $i < $count; $i++) {
            if (!isset($attributes[$key.'-'.$i]) || !is_string($attributes[$key.'-'.$i])) return '';
            $encoded .= $attributes[$key.'-'.$i];
            if (strlen($encoded) > 140000) return '';
        }
    }
    if (!is_string($encoded) || strlen($encoded) > 140000) return '';
    return base64_decode($encoded, true) ?: '';
}
