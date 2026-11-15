<?php

namespace App\Services\Diagnostics;

use App\Models\Site;
use App\Services\StaticDelivery\SecretReferenceResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Reads aggregate Analytics Engine rows only. Never imports raw visitor events. */
final class PerformanceReport
{
    public function __construct(private readonly SecretReferenceResolver $secrets) {}

    public function forSite(Site $site): array
    {
        $account = (string) (config('diagnostics.account_id') ?: config('static-delivery.cloudflare.account_id'));
        $reference = config('diagnostics.api_token_reference') ?: config('static-delivery.cloudflare.api_token_reference');
        if (! preg_match('/^[a-f0-9]{32}$/iD', $account) || ! is_string($reference) || $reference === '') return $this->result('SETUP_REQUIRED');
        if (! preg_match('/^[A-Za-z0-9_-]{3,64}$/D', $site->public_key)) return $this->result('UNAVAILABLE');

        $version = (int) ($site->siteConfig?->production_version ?? 0);
        if ($version < 1 || $version > 1000000000) return $this->result('NO_CONFIRMED_VERSION');
        return Cache::remember('diagnostics:aggregate:'.hash('sha256', $account.'|'.$site->public_key.'|'.$version), 60, function () use ($account, $reference, $site, $version): array {
            try {
                $token = $this->secrets->resolve($reference);
                $query = "SELECT blob2 AS device, blob3 AS browser, blob4 AS metric, COUNT() AS stored_rows, SUM(_sample_interval) AS samples, "
                    ."SUM(_sample_interval * double2) AS estimated_events, quantileExactWeighted(0.5)(double1, _sample_interval) AS p50_ms, "
                    ."quantileExactWeighted(0.75)(double1, _sample_interval) AS p75_ms, quantileExactWeighted(0.95)(double1, _sample_interval) AS p95_ms "
                    ."FROM horus_platform_diagnostics_v1 WHERE index1 = '".$site->public_key."' AND blob5 = '".$version."' AND timestamp >= NOW() - INTERVAL '1' DAY "
                    ."GROUP BY device, browser, metric ORDER BY device, browser, metric LIMIT 200";
                $response = Http::connectTimeout(1)->timeout(3)->withToken($token)->withBody($query, 'text/plain')
                    ->post('https://api.cloudflare.com/client/v4/accounts/'.$account.'/analytics_engine/sql');
                if (! $response->successful() || strlen($response->body()) > 262144) return $this->result('UNAVAILABLE');
                $data = $response->json('data');
                if (! is_array($data) || count($data) > 200) return $this->result('UNAVAILABLE');
                $rows = [];
                foreach ($data as $row) {
                    if (! is_array($row) || ! in_array($row['device'] ?? null, ['android', 'ios', 'desktop', 'other'], true)
                        || ! in_array($row['browser'] ?? null, ['chrome', 'safari', 'firefox', 'edge', 'other'], true)
                        || ! in_array($row['metric'] ?? null, ['pages', 'config', 'cloudflare_total', 'first_ad', 'after_pass', 'outcome:rendered', 'outcome:empty', 'outcome:incomplete', 'outcome:error'], true)) return $this->result('UNAVAILABLE');
                    $clean = array_intersect_key($row, array_flip(['device', 'browser', 'metric']));
                    foreach (['stored_rows', 'samples', 'estimated_events', 'p50_ms', 'p75_ms', 'p95_ms'] as $field) {
                        $value = $row[$field] ?? null;
                        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || (float) $value > (str_ends_with($field, '_ms') ? 60000 : 1000000000000000)) return $this->result('UNAVAILABLE');
                        $clean[$field] = (float) $value;
                    }
                    $rows[] = $clean;
                }

                return $this->result($rows === [] ? 'NO_OBSERVATIONS' : 'AVAILABLE', $rows) + ['configuration_version' => $version];
            } catch (Throwable) {
                return $this->result('UNAVAILABLE');
            }
        });
    }

    private function result(string $state, array $rows = []): array
    {
        return ['state' => $state, 'as_of_utc' => now()->utc()->toIso8601String(), 'window_hours' => 24,
            'scope' => 'Sampled client-reported observations; not a fraud score or a financial record.', 'rows' => $rows];
    }
}
