<?php

use App\Models\Site;
use App\Models\SyntheticProbeResult;
use App\Services\Compliance\AdsTxtVerifier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Symfony\Component\Console\Command\Command;

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
        } catch (Throwable $exception) {
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

    return Command::SUCCESS;
})->purpose('Probe static Horus runtime without publisher impression telemetry.');

Artisan::command('supply-chain:check {--site=}', function (AdsTxtVerifier $verifier): int {
    $sites = Site::withoutGlobalScopes()->when($this->option('site'), fn ($query, $id) => $query->whereKey($id))->get();
    $errors = 0;
    foreach ($sites as $site) {
        try {
            $check = $verifier->verify($site, 'SCHEDULED');
            $this->line($site->display_name.': '.$check->status);
        } catch (Throwable $exception) {
            $this->error($site->display_name.': verifier error');
            $errors++;
        }
    }

    return $errors === 0
        ? Command::SUCCESS
        : Command::FAILURE;
})->purpose('Safely verify publisher ads.txt 1.1 compliance and retain deduplicated history.');

Schedule::command('operations:heartbeat scheduler')->everyMinute()->withoutOverlapping();
Schedule::command('static-delivery:process')->everyMinute()->withoutOverlapping(10);
Schedule::command('adtech:probe')->everyFifteenMinutes()->withoutOverlapping(10);
Schedule::command('supply-chain:check')->dailyAt('03:20')->withoutOverlapping(30);
Schedule::command('support:sla-monitor')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('notifications:deliver-email')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('queue:work database --stop-when-empty --max-time='.(int) config('operations.queue_max_time', 50).' --tries='.(int) config('operations.queue_tries', 3))
    ->everyMinute()->withoutOverlapping();
Schedule::command('campaigns:monitor --reconcile')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('audit-logs:prune')->dailyAt('02:15');
Schedule::command('reporting:import hourly --retry-failed')->hourlyAt(12)->withoutOverlapping();
Schedule::command('reporting:import daily --retry-failed')->dailyAt('04:10')->withoutOverlapping();
Schedule::command('reporting:close-period --force')->monthlyOn(2, '05:20')->withoutOverlapping();
