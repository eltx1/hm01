<?php

declare(strict_types=1);

/** Manual bounded REST diagnostic. Existing access only; never changes report facts. */
final class HorusRestUnfilledDiagnosticUrl
{
    public static function baseUrl(string $configured): string
    {
        $base = rtrim($configured, '/');
        if (! preg_match('~^https://admanager\.googleapis\.com/v[1-9][0-9]*(?:(?:alpha|beta)[0-9]*)?$~D', $base)) {
            throw new RuntimeException('REST_FAILED');
        }
        return $base;
    }
}

if (defined('HORUS_REST_UNFILLED_DIAGNOSTIC_LIBRARY_ONLY')) return;
ini_set('display_errors', '0');
try {
    $expected = getenv('HORUS_EXPECTED_RELEASE');
    $marker = @file_get_contents(getcwd().'/.horus-release');
    if (! is_string($expected) || ! preg_match('/^[a-f0-9]{40}$/D', $expected)
        || ! is_string($marker) || ! in_array('release_id='.$expected, explode("\n", trim($marker)), true)) {
        echo json_encode(['schema_version' => 1, 'diagnostic' => 'REST_UNFILLED_ONLY', 'reason' => 'RELEASE_MISMATCH']).PHP_EOL;
        return;
    }
    require getcwd().'/vendor/autoload.php';
    $app = require getcwd().'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $bindings = App\Models\SiteGamReportBinding::withoutGlobalScopes()->with(['connection', 'gamConnection', 'site'])
        ->whereNotNull('active_site_id')->whereHas('connection', fn ($q) => $q->where('is_enabled', true))
        ->whereHas('gamConnection', fn ($q) => $q->where('is_enabled', true))->orderBy('id')->limit(26)->get();
    $cases = $bindings->map(function ($binding): array {
        if (! $binding->site || $binding->site->organization_id !== $binding->organization_id
            || $binding->connection->organization_id !== $binding->organization_id
            || $binding->connection->connection_type !== 'SITE_GAM_AD_UNIT'
            || $binding->connection->connection_id !== $binding->id) throw new RuntimeException('INVALID_CASE');
        $end = Carbon\CarbonImmutable::now($binding->connection->timezone)->subDay();
        return ['connection' => $binding->gamConnection, 'network_code' => (string) $binding->network_code,
            'unit_id' => (string) $binding->ad_unit_id, 'timezone' => (string) $binding->connection->timezone,
            'hostname' => app(App\Services\Reporting\SiteGamReportScope::class)->hostname($binding->site->primary_domain),
            'from' => $end->subDays(6)->toDateString(), 'to' => $end->toDateString()];
    })->all();
    // Raw aggregate evidence stays on the existing production host, never in Actions.
    $directory = storage_path('app/private/gam-rest-unfilled-diagnostic/'.bin2hex(random_bytes(12)));
    if (! mkdir($directory, 0700, true)) throw new RuntimeException('EVIDENCE_WRITE_FAILED');
    $restEvidence = static function (int $index, array $value) use ($directory): void {
        $path = $directory.'/rest-'.$index.'.jsonl';
        $file = fopen($path, 'ab');
        if ($file === false) throw new RuntimeException('EVIDENCE_WRITE_FAILED');
        chmod($path, 0600);
        try {
            if (fwrite($file, json_encode($value, JSON_THROW_ON_ERROR).PHP_EOL) === false) throw new RuntimeException('EVIDENCE_WRITE_FAILED');
        } finally { fclose($file); }
    };
    $baseUrl = HorusRestUnfilledDiagnosticUrl::baseUrl((string) config('gam.rest.base_url'));
    $accessErrorReasons = [];
    $restRequest = static function (array $case, string $verb, string $path, array $payload) use (&$accessErrorReasons, $baseUrl): array {
        // Fixed official origin and closed endpoint families; no credential-bearing
        // URL, redirect, cross-network name, persistent grant or retry is allowed.
        $network = 'networks/'.$case['network_code'];
        $suffix = substr($path, strlen($network));
        if (! str_starts_with($path, $network) || ! in_array($verb, ['GET', 'POST'], true)
            || ! preg_match('~^(|/reports|/reports/[0-9]+|/reports/[0-9]+:run|/operations/reports/runs/[A-Za-z0-9_-]+|/reports/[0-9]+/results/[A-Za-z0-9_-]+:fetchRows)$~D', $suffix)
            || ($verb === 'POST' && ! preg_match('~^/reports(?:/[0-9]+:run)?$~D', $suffix))) throw new RuntimeException('REST_FAILED');
        $connection = $case['connection'];
        $audit = app(App\Services\Audit\AuditRecorder::class);
        $audit->record('reporting.true_unfilled.rest_requested', $connection->organization_id, auditable: $connection,
            metadata: ['http_method' => $verb, 'resource' => $path, 'metric' => 'UNFILLED_IMPRESSIONS', 'diagnostic_only' => true]);
        $status = null;
        try {
            $request = Illuminate\Support\Facades\Http::withToken(app(App\Services\Gam\GamOAuthTokenProvider::class)->accessToken($connection))
                ->acceptJson()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false, 'stream' => true, 'read_timeout' => 15]);
            $url = $baseUrl.'/'.$path;
            $response = $verb === 'GET' ? $request->get($url, $payload)
                : ($payload === [] ? $request->withBody('', 'application/json')->send('POST', $url)
                    : $request->withBody(json_encode($payload, JSON_THROW_ON_ERROR), 'application/json')->send('POST', $url));
            $status = $response->status();
            $stream = $response->toPsrResponse()->getBody(); $body = ''; $deadline = microtime(true) + 15;
            try {
                while (! $stream->eof()) {
                    $body .= $stream->read(65536);
                    if (strlen($body) > 4 * 1024 * 1024 || microtime(true) > $deadline) throw new RuntimeException('REST_TIMEOUT');
                }
            } finally { $stream->close(); }
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) throw new RuntimeException('INVALID_RESPONSE');
            if (in_array($status, [401, 403], true)) {
                $classified = false;
                foreach ($decoded['error']['details'] ?? [] as $detail) {
                    if (is_array($detail) && ($detail['@type'] ?? '') === 'type.googleapis.com/google.rpc.ErrorInfo'
                        && ($detail['domain'] ?? '') === 'googleapis.com'
                        && in_array($detail['reason'] ?? '', ['SERVICE_DISABLED', 'ACCESS_TOKEN_SCOPE_INSUFFICIENT',
                            'IAM_PERMISSION_DENIED', 'CONSUMER_INVALID', 'PROJECT_DELETED', 'BILLING_DISABLED',
                            'SECURITY_POLICY_VIOLATED', 'ACCESS_TOKEN_EXPIRED', 'ACCESS_TOKEN_TYPE_UNSUPPORTED', 'CREDENTIALS_MISSING'], true)) {
                        $accessErrorReasons[$detail['reason']] = true; $classified = true;
                    }
                }
                if (! $classified) $accessErrorReasons['UNCLASSIFIED'] = true;
            }
            if (! $response->successful() && ! isset($decoded['error'])) {
                throw new RuntimeException(in_array($status, [401, 403], true) ? 'REST_ACCESS_BLOCKED' : 'REST_FAILED');
            }
            return $decoded;
        } catch (Throwable $error) {
            // HTTP exceptions can include identifiers; the helper returns only enums.
            throw new RuntimeException(HorusGamRestUnfilledProbe::safeError($error));
        } finally {
            $audit->record('reporting.true_unfilled.rest_finished', $connection->organization_id, auditable: $connection,
                metadata: ['http_method' => $verb, 'http_status' => $status, 'metric' => 'UNFILLED_IMPRESSIONS', 'diagnostic_only' => true]);
        }
    };
    $result = HorusGamRestUnfilledProbe::run($cases, $restRequest, $restEvidence, dryRun: false);
    $result['access_error_reasons'] = array_keys($accessErrorReasons);
    sort($result['access_error_reasons']);
    $restEvidence(0, ['summary' => $result]);
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['schema_version' => 1, 'diagnostic' => 'REST_UNFILLED_ONLY',
        'reason' => HorusGamRestUnfilledProbe::safeError($error)], JSON_THROW_ON_ERROR).PHP_EOL;
}
