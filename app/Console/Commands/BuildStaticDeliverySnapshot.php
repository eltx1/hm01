<?php

namespace App\Console\Commands;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Drivers\LocalFilesystemStaticDeliveryDriver;
use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use Illuminate\Console\Command;

class BuildStaticDeliverySnapshot extends Command
{
    protected $signature = 'static-delivery:build {path=cloudflare-pages-dist} {--confirmed : Use the health observation captured in the confirmed direct deployment}';
    protected $description = 'Build a complete validated Cloudflare Pages static snapshot without a remote deployment';

    public function handle(StaticDeliverySnapshotBuilder $builder, LocalFilesystemStaticDeliveryDriver $driver): int
    {
        $path = $this->argument('path');
        $root = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        config(['static-delivery.local_root' => $root]);
        $confirmed = $this->option('confirmed') ? StaticDeliveryBatch::query()
            ->where('driver', 'cloudflare-pages-direct')->where('status', 'DEPLOYED')->latest('deployed_at')->first() : null;
        $health = $confirmed?->provider_metadata['snapshot_health'] ?? null;
        if ($this->option('confirmed') && (! $confirmed || ! is_array($health))) {
            $this->error('No confirmed direct snapshot health evidence is available yet.');

            return self::FAILURE;
        }
        $snapshot = $builder->build(confirmedHealth: $health);
        if ($confirmed && ! hash_equals((string) $confirmed->manifest_hash, $snapshot->manifestHash)) {
            $this->error('Serving files differ from the confirmed deployment; waiting for automatic publication.');

            return self::FAILURE;
        }
        $driver->deliver($snapshot, new StaticDeliveryBatch);
        $this->info("Built ".count($snapshot->files)." files ({$snapshot->totalBytes} bytes), manifest {$snapshot->manifestHash}.");

        return self::SUCCESS;
    }
}
