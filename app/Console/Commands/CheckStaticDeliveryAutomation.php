<?php

namespace App\Console\Commands;

use App\Models\SystemHeartbeat;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\SecretReferenceResolver;
use Illuminate\Console\Command;
use Throwable;

class CheckStaticDeliveryAutomation extends Command
{
    protected $signature = 'static-delivery:automation-check {--driver-only} {--require-scheduler}';

    protected $description = 'Check active static delivery prerequisites without publishing or exposing credentials';

    public function handle(StaticDeliveryDriverInterface $driver, SecretReferenceResolver $secrets): int
    {
        if ($this->option('driver-only')) {
            $this->line($driver->name());

            return self::SUCCESS;
        }

        $this->line('Driver: '.$driver->name());
        if (! in_array($driver->name(), ['cloudflare-pages-pipeline', 'cloudflare-pages-direct'], true)) {
            $this->error('ACTIVE_AUTOMATION_UNAVAILABLE: configured driver does not submit a production deployment.');

            return self::FAILURE;
        }
        try {
            $key = $driver->name() === 'cloudflare-pages-direct' ? 'api_token_reference' : 'github_token_reference';
            $secrets->resolve((string) config('static-delivery.cloudflare.'.$key));
        } catch (Throwable) {
            $this->error('CREDENTIAL_UNAVAILABLE: the configured private deployment credential could not be read.');

            return self::FAILURE;
        }
        if (config('static-delivery.cloudflare.dry_run')) {
            $this->error('DRY_RUN_ONLY: external writes are disabled.');

            return self::FAILURE;
        }
        if ($this->option('require-scheduler')) {
            $heartbeat = SystemHeartbeat::query()->where('key', 'scheduler')->first();
            if (! $heartbeat?->last_seen_at || $heartbeat->last_seen_at->lt(now()->subMinutes(5))) {
                $this->error('SCHEDULER_STALE: no scheduler heartbeat in the last five minutes.');

                return self::FAILURE;
            }
        }
        $this->info('Local prerequisites present. Scheduler execution, provider permissions, and public CDN delivery still require verification.');
        $this->line('Batch interval: '.config('static-delivery.normal_batch_interval_minutes').' minutes.');

        return self::SUCCESS;
    }
}
