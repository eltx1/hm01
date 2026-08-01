<?php

namespace App\Console\Commands;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Drivers\LocalFilesystemStaticDeliveryDriver;
use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use Illuminate\Console\Command;

class BuildStaticDeliverySnapshot extends Command
{
    protected $signature = 'static-delivery:build {path=cloudflare-pages-dist}';
    protected $description = 'Build a complete validated Cloudflare Pages static snapshot without a remote deployment';

    public function handle(StaticDeliverySnapshotBuilder $builder, LocalFilesystemStaticDeliveryDriver $driver): int
    {
        $path = $this->argument('path');
        $root = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        config(['static-delivery.local_root' => $root]);
        $snapshot = $builder->build();
        $driver->deliver($snapshot, new StaticDeliveryBatch);
        $this->info("Built ".count($snapshot->files)." files ({$snapshot->totalBytes} bytes), manifest {$snapshot->manifestHash}.");

        return self::SUCCESS;
    }
}
