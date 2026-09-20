<?php

namespace App\Console\Commands;

use App\Models\StaticGlobalArtifactChange;
use App\Services\Audit\AuditRecorder;
use App\Services\StaticDelivery\StaticDeliverySnapshotBuilder;
use App\Services\StaticDelivery\StaticDeliveryWindow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class QueueStaticAssets extends Command
{
    protected $signature = 'static-delivery:queue-assets {--apply : Queue changed release assets; otherwise preview only}';
    protected $description = 'Queue changed compiled CDN assets without publishing or accelerating the normal delivery window';

    public function handle(StaticDeliverySnapshotBuilder $snapshots, StaticDeliveryWindow $window, AuditRecorder $audit): int
    {
        $fingerprint = $snapshots->assetFingerprint();
        if (! $this->option('apply')) {
            $this->line('Dry run: release asset fingerprint '.$fingerprint.'. No changes queued.');

            return self::SUCCESS;
        }
        Cache::lock('static-delivery:queue-assets', 30)->block(2, function () use ($fingerprint, $window, $audit): void {
            $latest = StaticGlobalArtifactChange::query()->where('artifact_type', 'STATIC_ASSETS')
                ->latest('created_at')->latest('id')->first();
            if (data_get($latest?->context, 'asset_fingerprint') === $fingerprint) {
                $this->line('Release assets unchanged; no additional delivery queued.');

                return;
            }
            $change = StaticGlobalArtifactChange::query()->create([
                'artifact_type' => 'STATIC_ASSETS', 'status' => 'PENDING', 'priority' => 'NORMAL',
                'context' => ['asset_fingerprint' => $fingerprint],
                'attempts' => 0, 'available_at' => $window->nextNormalBoundary(),
            ]);
            $audit->record('static.assets.queued', null, null, $change, newValues: ['asset_fingerprint' => $fingerprint]);
            $this->line('Changed release assets queued for '.$change->available_at->toIso8601String().'; the scheduler will publish them.');
        });

        return self::SUCCESS;
    }
}
