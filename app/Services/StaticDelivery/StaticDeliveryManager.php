<?php

namespace App\Services\StaticDelivery;

use App\Enums\ConfigVersionStatus;
use App\Enums\StaticDeliveryPriority;
use App\Enums\StaticDeliveryStatus;
use App\Models\ConfigVersion;
use App\Models\StaticDeliveryBatch;
use App\Models\StaticDeliveryItem;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Contracts\StaticDeliveryStatusProbeInterface;
use App\Services\StaticDelivery\Data\StaticDeliveryResult;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use Illuminate\Support\Facades\DB;
use App\Services\Audit\AuditRecorder;
use Throwable;

final class StaticDeliveryManager
{
    public function __construct(
        private readonly StaticDeliveryDriverInterface $driver,
        private readonly StaticDeliverySnapshotBuilder $snapshots,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function processPending(): ?StaticDeliveryBatch
    {
        $batch = DB::transaction(function (): ?StaticDeliveryBatch {
            $items = StaticDeliveryItem::withoutGlobalScopes()
                ->with('configVersion:id,version')
                ->whereIn('status', [StaticDeliveryStatus::Pending->value, StaticDeliveryStatus::RetryScheduled->value])
                ->where('available_at', '<=', now())
                ->orderByRaw("CASE WHEN priority = 'URGENT' THEN 0 ELSE 1 END")
                ->orderBy('created_at')
                ->lockForUpdate()
                ->limit(max(1, (int) config('static-delivery.batch_size', 250)))
                ->get();
            if ($items->isEmpty()) {
                return null;
            }

            $selected = $items->groupBy(fn (StaticDeliveryItem $item) => $item->site_id.'|'.$item->environment->value)
                ->map(fn ($group) => $group->sortByDesc(fn (StaticDeliveryItem $item) => $item->configVersion->version)->first());
            $selectedIds = $selected->pluck('id');
            $supersededIds = $items->pluck('id')->diff($selectedIds);
            if ($supersededIds->isNotEmpty()) {
                $supersededVersionIds = StaticDeliveryItem::withoutGlobalScopes()->whereIn('id', $supersededIds)->pluck('config_version_id');
                StaticDeliveryItem::withoutGlobalScopes()->whereIn('id', $supersededIds)
                    ->update(['status' => StaticDeliveryStatus::Superseded->value]);
                ConfigVersion::withoutGlobalScopes()->whereIn('id', $supersededVersionIds)
                    ->update(['status' => ConfigVersionStatus::Superseded->value]);
            }

            $priority = $selected->contains(fn (StaticDeliveryItem $item) => $item->priority === StaticDeliveryPriority::Urgent)
                ? StaticDeliveryPriority::Urgent : StaticDeliveryPriority::Normal;
            $this->assertDeploymentBudget($priority);
            $batch = StaticDeliveryBatch::create([
                'status' => StaticDeliveryStatus::Batching,
                'priority' => $priority,
                'driver' => $this->driver->name(),
                'item_count' => $selected->count(),
                'created_by' => $selected->pluck('created_by')->filter()->first(),
                'started_at' => now(),
            ]);
            StaticDeliveryItem::withoutGlobalScopes()->whereIn('id', $selectedIds)->update([
                'batch_id' => $batch->id,
                'status' => StaticDeliveryStatus::Batching->value,
                'attempts' => DB::raw('attempts + 1'),
            ]);

            return $batch;
        });

        if (! $batch) {
            return null;
        }

        try {
            $snapshot = $this->snapshots->build();
            $batch->update([
                'manifest_hash' => $snapshot->manifestHash,
                'file_count' => count($snapshot->files),
                'total_bytes' => $snapshot->totalBytes,
            ]);
            $duplicate = StaticDeliveryBatch::query()
                ->where('id', '!=', $batch->id)
                ->where('manifest_hash', $snapshot->manifestHash)
                ->where('status', StaticDeliveryStatus::Deployed->value)
                ->latest('deployed_at')->first();
            if ($duplicate) {
                $this->confirm($batch, new StaticDeliveryResult(
                    remoteId: (string) $duplicate->remote_deployment_id,
                    remoteUrl: $duplicate->remote_url,
                    confirmedDeployed: true,
                    metadata: ['deduplicated_from_batch' => $duplicate->id, 'manifest_hash' => $snapshot->manifestHash],
                ));

                return $batch->refresh();
            }

            $batch->items()->update(['status' => StaticDeliveryStatus::Uploading->value]);
            $batch->update(['status' => StaticDeliveryStatus::Uploading, 'attempts' => $batch->attempts + 1]);
            $result = $this->driver->deliver($snapshot, $batch->refresh());
            $batch->update([
                'remote_deployment_id' => $result->remoteId,
                'remote_url' => $result->remoteUrl,
                'provider_metadata' => $this->sanitizeMetadata($result->metadata),
                'submitted_at' => now(),
            ]);
            if ($result->confirmedDeployed) {
                $this->confirm($batch, $result);
            }
        } catch (Throwable $exception) {
            $this->fail($batch, $exception);
        }

        return $batch->refresh();
    }

    public function reconcileUploading(): int
    {
        if (! $this->driver instanceof StaticDeliveryStatusProbeInterface) {
            return 0;
        }
        $count = 0;
        StaticDeliveryBatch::query()->where('status', StaticDeliveryStatus::Uploading->value)
            ->whereNotNull('submitted_at')->oldest('submitted_at')->limit(25)->get()
            ->each(function (StaticDeliveryBatch $batch) use (&$count): void {
                try {
                    $result = $this->driver->probe($batch);
                    if ($result?->confirmedDeployed) {
                        $this->confirm($batch, $result);
                        $count++;
                    }
                } catch (Throwable $exception) {
                    $this->fail($batch, $exception);
                }
            });

        return $count;
    }

    public function retry(StaticDeliveryBatch $batch): void
    {
        if (! in_array($batch->status, [StaticDeliveryStatus::Failed, StaticDeliveryStatus::RetryScheduled], true)) {
            throw new StaticDeliveryException('BATCH_NOT_RETRYABLE', 'Only failed or scheduled batches can be retried.');
        }
        DB::transaction(function () use ($batch): void {
            $batch->update([
                'status' => StaticDeliveryStatus::RetryScheduled,
                'next_retry_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ]);
            $batch->items()->update([
                'batch_id' => null,
                'status' => StaticDeliveryStatus::RetryScheduled->value,
                'available_at' => now(),
            ]);
        });
    }

    private function confirm(StaticDeliveryBatch $batch, StaticDeliveryResult $result): void
    {
        DB::transaction(function () use ($batch, $result): void {
            $batch->update([
                'status' => StaticDeliveryStatus::Deployed,
                'remote_deployment_id' => $result->remoteId,
                'remote_url' => $result->remoteUrl,
                'provider_metadata' => $this->sanitizeMetadata($result->metadata),
                'deployed_at' => now(),
                'next_retry_at' => null,
                'error_code' => null,
                'error_message' => null,
            ]);
            $versionIds = $batch->items()->pluck('config_version_id');
            $batch->items()->update(['status' => StaticDeliveryStatus::Deployed->value, 'delivered_at' => now()]);
            ConfigVersion::withoutGlobalScopes()->whereIn('id', $versionIds)->update([
                'status' => ConfigVersionStatus::Deployed->value,
                'published_at' => now(),
            ]);
            ConfigVersion::withoutGlobalScopes()->whereIn('id', $versionIds)->get()->each(function (ConfigVersion $version): void {
                $column = match ($version->environment->value) {
                    'PREVIEW' => 'preview_version',
                    'TEST' => 'test_version',
                    default => 'production_version',
                };
                $version->siteConfig()->update([$column => $version->version]);
                if ($version->environment->value === 'PRODUCTION') {
                    $settings = $version->site->servingSettings()->first();
                    $settings?->update(['configuration_version' => max((int) $settings->configuration_version + 1, $version->version)]);
                }
            });
        });
        $fresh = $batch->refresh();
        $this->audit->record('static.delivery.deployed', null, $fresh->creator, $fresh, newValues: [
            'batch_id' => $fresh->id,
            'manifest_hash' => $fresh->manifest_hash,
            'remote_deployment_id' => $fresh->remote_deployment_id,
            'file_count' => $fresh->file_count,
        ]);
    }

    private function fail(StaticDeliveryBatch $batch, Throwable $exception): void
    {
        $attempts = max(1, (int) $batch->refresh()->attempts);
        $retryable = $attempts < max(1, (int) config('static-delivery.max_attempts', 5));
        $status = $retryable ? StaticDeliveryStatus::RetryScheduled : StaticDeliveryStatus::Failed;
        $delay = max(1, (int) config('static-delivery.retry_delay_seconds', 300)) * (2 ** min(4, max(0, $attempts - 1)));
        DB::transaction(function () use ($batch, $exception, $attempts, $retryable, $status, $delay): void {
            $versionIds = $batch->items()->pluck('config_version_id');
            $batch->update([
                'status' => $status,
                'attempts' => $attempts,
                'error_code' => $exception instanceof StaticDeliveryException ? $exception->category : 'DELIVERY_ERROR',
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'next_retry_at' => $retryable ? now()->addSeconds($delay) : null,
            ]);
            $batch->items()->update([
                'batch_id' => $retryable ? null : $batch->id,
                'status' => $status->value,
                'available_at' => $retryable ? now()->addSeconds($delay) : now(),
            ]);
            ConfigVersion::withoutGlobalScopes()->whereIn('id', $versionIds)
                ->update(['status' => ConfigVersionStatus::DeliveryFailed->value]);
        });
        $fresh = $batch->refresh();
        $this->audit->record('static.delivery.failed', null, $fresh->creator, $fresh, newValues: [
            'batch_id' => $fresh->id,
            'manifest_hash' => $fresh->manifest_hash,
            'error_code' => $fresh->error_code,
            'retry_scheduled' => $retryable,
        ]);
    }

    private function assertDeploymentBudget(StaticDeliveryPriority $priority): void
    {
        $budget = max(1, (int) config('static-delivery.monthly_deployment_budget', 450));
        $reserve = min($budget, max(0, (int) config('static-delivery.emergency_reserve', 25)));
        $used = StaticDeliveryBatch::query()->where('created_at', '>=', now()->startOfMonth())
            ->whereIn('status', [StaticDeliveryStatus::Uploading->value, StaticDeliveryStatus::Deployed->value])->count();
        $allowed = $priority === StaticDeliveryPriority::Urgent ? $budget : $budget - $reserve;
        if ($used >= $allowed) {
            throw new StaticDeliveryException('DEPLOYMENT_BUDGET_EXHAUSTED', 'Configured monthly static deployment safety budget is exhausted.');
        }
    }

    /** @param array<string, mixed> $metadata */
    private function sanitizeMetadata(array $metadata): array
    {
        return collect($metadata)->only(['delivery_commit', 'workflow_run_id', 'manifest_hash', 'deduplicated_from_batch'])->all();
    }
}
