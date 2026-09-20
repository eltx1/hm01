<?php

namespace App\Console\Commands;

use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\SecretReferenceResolver;
use Illuminate\Console\Command;
use Throwable;

class CheckStaticDeliveryAutomation extends Command
{
    protected $signature = 'static-delivery:automation-check {--driver-only}';

    protected $description = 'Check active static delivery prerequisites without publishing or exposing credentials';

    public function handle(StaticDeliveryDriverInterface $driver, SecretReferenceResolver $secrets): int
    {
        if ($this->option('driver-only')) {
            $this->line($driver->name());

            return self::SUCCESS;
        }

        $this->line('Driver: '.$driver->name());
        if ($driver->name() !== 'cloudflare-pages-pipeline') {
            $this->error('ACTIVE_AUTOMATION_UNAVAILABLE: configured driver does not submit a production deployment.');

            return self::FAILURE;
        }
        try {
            $secrets->resolve((string) config('static-delivery.cloudflare.github_token_reference'));
        } catch (Throwable) {
            $this->error('CREDENTIAL_UNAVAILABLE: configure a persistent server GitHub credential reference.');

            return self::FAILURE;
        }
        if (config('static-delivery.cloudflare.dry_run')) {
            $this->error('DRY_RUN_ONLY: external writes are disabled.');

            return self::FAILURE;
        }
        $this->info('Local prerequisites present. Scheduler execution, GitHub permissions, and public CDN delivery still require verification.');
        $this->line('Batch interval: '.config('static-delivery.normal_batch_interval_minutes').' minutes.');

        return self::SUCCESS;
    }
}
