<?php

namespace App\Console\Commands;

use App\Services\StaticDelivery\StaticDeliveryManager;
use Illuminate\Console\Command;

class ProcessStaticDelivery extends Command
{
    protected $signature = 'static-delivery:process {--reconcile-only}';
    protected $description = 'Batch, submit, and reconcile static Cloudflare Pages delivery outbox items';

    public function handle(StaticDeliveryManager $manager): int
    {
        $confirmed = $manager->reconcileUploading();
        if (! $this->option('reconcile-only')) {
            $batch = $manager->processPending();
            $this->line($batch ? "Batch {$batch->id}: {$batch->status->value}" : 'No pending static delivery items.');
        }
        $this->line("Confirmed deployments: {$confirmed}");

        return self::SUCCESS;
    }
}
