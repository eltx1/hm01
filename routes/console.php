<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use App\Models\Site;
use App\Models\SyntheticProbeResult;
use App\Models\SupplyChainCheck;
use App\Services\Campaigns\RemoteUrlSafetyValidator;

Artisan::command('adtech:probe {--site=} {--environment=production}', function (): int {
    $environment = strtolower((string) $this->option('environment'));
    $sites = Site::withoutGlobalScopes()->when($this->option('site'), fn ($query, $id) => $query->whereKey($id))->get();
    foreach ($sites as $site) {
        $started = hrtime(true);
        $url = rtrim((string) config('horus.cdn_url'), '/').'/configs/'.$site->public_key.'/manifest.json';
        try {
            $response = Http::timeout(10)->acceptJson()->get($url);
            $manifest = $response->json();
            $entry = data_get($manifest, 'environments.'.$environment);
            $checks = [
                'http' => $response->successful(), 'siteKey' => data_get($manifest, 'siteKey') === $site->public_key,
                'environment' => is_array($entry), 'checksum' => preg_match('/^[a-f0-9]{64}$/', (string) data_get($entry, 'sha256')) === 1,
            ];
            $status = ! in_array(false, $checks, true) ? 'PASS' : 'FAIL';
        } catch (\Throwable $exception) {
            $checks = ['http' => false, 'error' => mb_substr($exception->getMessage(), 0, 500)];
            $status = 'FAIL';
        }
        SyntheticProbeResult::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id, 'site_id' => $site->id, 'probe' => 'STATIC_RUNTIME',
            'environment' => strtoupper($environment), 'status' => $status,
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000), 'checks' => $checks,
            'release' => (string) data_get($entry ?? [], 'version'), 'observed_at' => now(),
        ]);
        $this->line($site->display_name.': '.$status);
    }
    return \Symfony\Component\Console\Command\Command::SUCCESS;
})->purpose('Probe static Horus runtime without publisher impression telemetry.');

Artisan::command('supply-chain:check {--site=}', function (RemoteUrlSafetyValidator $urls): int {
    $sites = Site::withoutGlobalScopes()->when($this->option('site'), fn ($query, $id) => $query->whereKey($id))->get();
    foreach ($sites as $site) {
        $url = 'https://'.$site->primary_domain.'/ads.txt';
        try {
            $address = $urls->publicAddresses($url, 'ads_txt_url')[0];
            $host = (string) parse_url($url, PHP_URL_HOST);
            $response = Http::timeout(10)
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => [$host.':443:'.$address]]])
                ->withHeaders(['User-Agent' => 'HorusMedia-SupplyChain-Audit/1.0'])->get($url);
            $body = $response->successful() ? $response->body() : '';
            $findings = [
                'ownerDomain' => preg_match('/^OWNERDOMAIN=/mi', $body) === 1,
                'managerDomain' => preg_match('/^MANAGERDOMAIN=/mi', $body) === 1,
                'records' => collect(preg_split('/\r\n|\r|\n/', $body))->filter(fn ($line) => preg_match('/^[^#=]+,[^,]+,(?:DIRECT|RESELLER)/i', trim($line)))->count(),
            ];
            $status = $response->successful() && $findings['ownerDomain'] && $findings['managerDomain'] ? 'PASS' : 'WARN';
            $httpStatus = $response->status();
        } catch (\Throwable $exception) {
            $body = ''; $findings = ['error' => mb_substr($exception->getMessage(), 0, 500)]; $status = 'FAIL'; $httpStatus = null;
        }
        SupplyChainCheck::withoutGlobalScopes()->create([
            'organization_id' => $site->organization_id, 'site_id' => $site->id, 'check_type' => 'ADS_TXT',
            'status' => $status, 'url' => $url, 'http_status' => $httpStatus,
            'checksum' => $body !== '' ? hash('sha256', $body) : null, 'findings' => $findings, 'checked_at' => now(),
        ]);
        $this->line($site->display_name.': '.$status);
    }
    return \Symfony\Component\Console\Command\Command::SUCCESS;
})->purpose('Audit publisher ads.txt 1.1 delivery and store crawler state.');

Schedule::command('operations:heartbeat scheduler')->everyMinute()->withoutOverlapping();
Schedule::command('static-delivery:process')->everyMinute()->withoutOverlapping(10);
Schedule::command('adtech:probe')->everyFifteenMinutes()->withoutOverlapping(10);
Schedule::command('supply-chain:check')->dailyAt('03:20')->withoutOverlapping(30);
Schedule::command('queue:work database --stop-when-empty --max-time='.(int) config('operations.queue_max_time', 50).' --tries='.(int) config('operations.queue_tries', 3))
    ->everyMinute()->withoutOverlapping();
Schedule::command('campaigns:monitor --reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('audit-logs:prune')->dailyAt('02:15');
Schedule::command('reporting:import hourly --retry-failed')->hourlyAt(12)->withoutOverlapping();
Schedule::command('reporting:import daily --retry-failed')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('reporting:close-period --force')->monthlyOn(2, '05:20')->withoutOverlapping();
