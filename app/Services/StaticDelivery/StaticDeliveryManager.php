<?php

namespace App\Services\StaticDelivery;

use App\Enums\ConfigVersionStatus;
use App\Enums\StaticDeliveryManualOutcome;
use App\Enums\StaticDeliveryPriority;
use App\Enums\StaticDeliveryStatus;
use App\Models\ConfigVersion;
use App\Models\StaticDeliveryBatch;
use App\Models\StaticDeliveryItem;
use App\Models\StaticGlobalArtifactChange;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use App\Services\StaticDelivery\Contracts\StaticDeliveryDriverInterface;
use App\Services\StaticDelivery\Contracts\StaticDeliveryStatusProbeInterface;
use App\Services\StaticDelivery\Data\StaticDeliveryManualResult;
use App\Services\StaticDelivery\Data\StaticDeliveryResult;
use App\Services\StaticDelivery\Exceptions\StaticDeliveryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class StaticDeliveryManager
{
    public const PROCESS_LOCK = 'static-delivery:process';

    public function __construct(
        private readonly StaticDeliveryDriverInterface $driver,
        private readonly StaticDeliverySnapshotBuilder $snapshots,
        private readonly AuditRecorder $audit,
    ) {}

    public function processPending(): ?StaticDeliveryBatch
    {
        $lock = Cache::lock(self::PROCESS_LOCK, max(30, (int) config('static-delivery.process_lock_seconds', 180)));
        if (! $lock->get()) {
            return null;
        }

        try {
            return $this->processPendingLocked();
        } finally {
            $lock->release();
        }
    }

    public function deployPendingNow(User $actor, string $reason): StaticDeliveryManualResult
    {
        $lock = Cache::lock(self::PROCESS_LOCK, max(30, (int) config('static-delivery.process_lock_seconds', 180)));
        if (! $lock->get()) {
            $this->auditManualDeployment($actor, $reason, StaticDeliveryManualOutcome::Busy);

            return new StaticDeliveryManualResult(StaticDeliveryManualOutcome::Busy);
        }

        try {
            [$itemIds, $globalIds] = DB::transaction(function (): array {
                $items = StaticDeliveryItem::withoutGlobalScopes()
                    ->where('status', StaticDeliveryStatus::Pending->value)
                    ->where('priority', StaticDeliveryPriority::Normal->value)
                    ->lockForUpdate()->get(['id']);
                $globals = StaticGlobalArtifactChange::query()
                    ->where('status', StaticDeliveryStatus::Pending->value)
                    ->where('priority', StaticDeliveryPriority::Normal->value)
                    ->lockForUpdate()->get(['id']);
                if ($items->isNotEmpty()) {
                    StaticDeliveryItem::withoutGlobalScopes()->whereIn('id', $items->pluck('id'))->update(['available_at' => now()->utc()]);
                }
                if ($globals->isNotEmpty()) {
                    StaticGlobalArtifactChange::query()->whereIn('id', $globals->pluck('id'))->update(['available_at' => now()->utc()]);
                }

                return [$items->pluck('id')->all(), $globals->pluck('id')->all()];
            });

            $hasEligibleWork = StaticDeliveryItem::withoutGlobalScopes()
                ->whereIn('status', [StaticDeliveryStatus::Pending->value, StaticDeliveryStatus::RetryScheduled->value])
                ->where('available_at', '<=', now())->exists()
                || StaticGlobalArtifactChange::query()
                    ->whereIn('status', [StaticDeliveryStatus::Pending->value, StaticDeliveryStatus::RetryScheduled->value])
                    ->where('available_at', '<=', now())->exists();
            if ($itemIds === [] && $globalIds === [] && ! $hasEligibleWork) {
                $this->auditManualDeployment($actor, $reason, StaticDeliveryManualOutcome::NoPending);

                return new StaticDeliveryManualResult(StaticDeliveryManualOutcome::NoPending);
            }

            $batch = $this->processPendingLocked($actor, 'MANUAL');
            $accelerated = count($itemIds) + count($globalIds);
            $this->auditManualDeployment($actor, $reason, StaticDeliveryManualOutcome::Processed, $batch, $accelerated);

            return new StaticDeliveryManualResult(StaticDeliveryManualOutcome::Processed, $batch?->refresh(), $accelerated);
        } catch (Throwable $exception) {
            $this->audit->record('static.delivery.deploy_now.requested', $actor->organization_id, $actor, metadata: [
                'reason' => $reason,
                'outcome' => 'ERROR',
                'error_code' => $exception instanceof StaticDeliveryException ? $exception->category : 'DELIVERY_ERROR',
            ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function processPendingLocked(?User $batchActor = null, string $trigger = 'SCHEDULED'): ?StaticDeliveryBatch
    {
        $batch = DB::transaction(function () use ($batchActor, $trigger): ?StaticDeliveryBatch {
            $items = StaticDeliveryItem::withoutGlobalScopes()
                ->with('configVersion:id,version')
                ->whereIn('status', [StaticDeliveryStatus::Pending->value, StaticDeliveryStatus::RetryScheduled->value])
                ->where('available_at', '<=', now())
                ->orderByRaw("CASE WHEN priority = 'URGENT' THEN 0 ELSE 1 END")
                ->orderBy('created_at')->lockForUpdate()->get();
            $globals = StaticGlobalArtifactChange::query()
                ->whereIn('status', [StaticDeliveryStatus::Pending->value, StaticDeliveryStatus::RetryScheduled->value])
                ->where('available_at', '<=', now())
                ->orderByRaw("CASE WHEN priority = 'URGENT' THEN 0 ELSE 1 END")
                ->orderBy('created_at')->lockForUpdate()->get();
            if ($items->isEmpty() && $globals->isEmpty()) {
                return null;
            }

            $selected = $items->groupBy(fn (StaticDeliveryItem $item) => $item->site_id.'|'.$item->environment->value)
                ->map(fn ($group) => $group->sortByDesc(fn (StaticDeliveryItem $item) => $item->configVersion->version)->first());
            $selectedIds = $selected->pluck('id');
            $selectedVersions = $selected->mapWithKeys(fn (StaticDeliveryItem $item) => [
                $item->site_id.'|'.$item->environment->value => $item->configVersion->version,
            ]);
            $obsoleteOutstandingIds = StaticDeliveryItem::withoutGlobalScopes()
                ->with('configVersion:id,version')
                ->whereIn('status', [StaticDeliveryStatus::Pending->value, StaticDeliveryStatus::RetryScheduled->value])
                ->lockForUpdate()->get()
                ->filter(function (StaticDeliveryItem $item) use ($selectedVersions): bool {
                    $selectedVersion = $selectedVersions->get($item->site_id.'|'.$item->environment->value);
                    return $selectedVersion !== null && $item->configVersion->version < $selectedVersion;
                })->pluck('id');
            $supersededIds = $items->pluck('id')->diff($selectedIds)->merge($obsoleteOutstandingIds)
                ->diff($selectedIds)->unique()->values();
            if ($supersededIds->isNotEmpty()) {
                $supersededVersionIds = StaticDeliveryItem::withoutGlobalScopes()->whereIn('id', $supersededIds)
                    ->pluck('config_version_id')->filter();
                StaticDeliveryItem::withoutGlobalScopes()->whereIn('id', $supersededIds)
                    ->update(['status' => StaticDeliveryStatus::Superseded->value]);
                if ($supersededVersionIds->isNotEmpty()) {
                    ConfigVersion::withoutGlobalScopes()->whereIn('id', $supersededVersionIds)
                        ->update(['status' => ConfigVersionStatus::Superseded->value]);
                }
            }

            $globalSelected = $globals->groupBy('artifact_type')->map(function ($group) {
                return $group->sortByDesc(fn (StaticGlobalArtifactChange $change): string =>
                    ($change->priority === StaticDeliveryPriority::Urgent ? '1' : '0').$change->created_at?->format('U.u')
                )->first();
            });
            $globalSelectedIds = $globalSelected->pluck('id');
            $globalSupersededIds = $globals->pluck('id')->diff($globalSelectedIds);
            if ($globalSupersededIds->isNotEmpty()) {
                StaticGlobalArtifactChange::query()->whereIn('id', $globalSupersededIds)
                    ->update(['status' => StaticDeliveryStatus::Superseded->value]);
            }

            $urgent = $selected->contains(fn (StaticDeliveryItem $item) => $item->priority === StaticDeliveryPriority::Urgent)
                || $globalSelected->contains(fn (StaticGlobalArtifactChange $change) => $change->priority === StaticDeliveryPriority::Urgent);
            $priority = $urgent ? StaticDeliveryPriority::Urgent : StaticDeliveryPriority::Normal;
            $batch = StaticDeliveryBatch::create([
                'status' => StaticDeliveryStatus::Batching,
                'priority' => $priority,
                'trigger' => $trigger === 'MANUAL' ? 'MANUAL' : ($urgent ? 'URGENT' : 'SCHEDULED'),
                'driver' => $this->driver->name(),
                'item_count' => $selected->count() + $globalSelected->count(),
                'created_by' => $batchActor?->id
                    ?? $selected->pluck('created_by')->filter()->first()
                    ?? $globalSelected->pluck('created_by')->filter()->first(),
                'started_at' => now(),
            ]);
            if ($selectedIds->isNotEmpty()) {
                StaticDeliveryItem::withoutGlobalScopes()->whereIn('id', $selectedIds)->update([
                    'batch_id' => $batch->id,
                    'status' => StaticDeliveryStatus::Batching->value,
                    'attempts' => DB::raw('attempts + 1'),
                ]);
            }
            if ($globalSelectedIds->isNotEmpty()) {
                StaticGlobalArtifactChange::query()->whereIn('id', $globalSelectedIds)->update([
                    'batch_id' => $batch->id,
                    'status' => StaticDeliveryStatus::Batching->value,
                    'attempts' => DB::raw('attempts + 1'),
                ]);
            }

            return $batch;
        });

        if (! $batch) {
            return null;
        }

        try {
            $selectedVersionIds = $batch->items()->pluck('config_version_id')->filter()->values()->all();
            $snapshot = $this->snapshots->build($selectedVersionIds);
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
                $batch->update(['is_deduplicated' => true]);
                $this->confirm($batch, new StaticDeliveryResult(
                    remoteId: (string) $duplicate->remote_deployment_id,
                    remoteUrl: $duplicate->remote_url,
                    confirmedDeployed: true,
                    metadata: ['deduplicated_from_batch' => $duplicate->id, 'manifest_hash' => $snapshot->manifestHash],
                ));
                return $batch->refresh();
            }

            $this->assertDeploymentBudget($batch->priority);
            $batch->items()->update(['status' => StaticDeliveryStatus::Uploading->value]);
            $batch->globalChanges()->update(['status' => StaticDeliveryStatus::Uploading->value]);
            $batch->update([
                'status' => StaticDeliveryStatus::Uploading,
                'attempts' => $batch->attempts + 1,
                'submitted_at' => now(),
            ]);
            $result = $this->driver->deliver($snapshot, $batch->refresh());
            $batch->update([
                'remote_deployment_id' => $result->remoteId,
                'remote_url' => $result->remoteUrl,
                'provider_metadata' => $this->sanitizeMetadata($result->metadata),
            ]);
            if ($result->confirmedDeployed) {
                $this->confirm($batch, $result);
            }
        } catch (Throwable $exception) {
            if ($exception instanceof StaticDeliveryException && $exception->category === 'DEPLOYMENT_BUDGET_EXHAUSTED') {
                $this->deferForBudget($batch, $exception);
            } else {
                $this->fail($batch, $exception);
            }
        }

        return $batch->refresh();
    }

    public function reconcileUploading(): int
    {
        $lock = Cache::lock(self::PROCESS_LOCK, max(30, (int) config('static-delivery.process_lock_seconds', 180)));
        if (! $lock->get()) {
            return 0;
        }
        try {
            return $this->reconcileUploadingLocked();
        } finally {
            $lock->release();
        }
    }

    private function reconcileUploadingLocked(): int
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
        $lock = Cache::lock(self::PROCESS_LOCK, max(30, (int) config('static-delivery.process_lock_seconds', 180)));
        if (! $lock->get()) {
            throw new StaticDeliveryException('DELIVERY_BUSY', 'Static delivery is already being processed.');
        }
        try {
            $this->retryLocked($batch);
        } finally {
            $lock->release();
        }
    }

    private function retryLocked(StaticDeliveryBatch $batch): void
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
            $attributes = [
                'batch_id' => null,
                'status' => StaticDeliveryStatus::RetryScheduled->value,
                'available_at' => now(),
            ];
            $batch->items()->update($attributes);
            $batch->globalChanges()->update($attributes);
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
            $versionIds = $batch->items()->pluck('config_version_id')->filter();
            $batch->items()->update(['status' => StaticDeliveryStatus::Deployed->value, 'delivered_at' => now()]);
            $batch->globalChanges()->update(['status' => StaticDeliveryStatus::Deployed->value, 'delivered_at' => now()]);
            if ($versionIds->isNotEmpty()) {
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
            }
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
            $versionIds = $batch->items()->pluck('config_version_id')->filter();
            $batch->update([
                'status' => $status,
                'attempts' => $attempts,
                'error_code' => $exception instanceof StaticDeliveryException ? $exception->category : 'DELIVERY_ERROR',
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'next_retry_at' => $retryable ? now()->addSeconds($delay) : null,
            ]);
            $attributes = [
                'status' => $status->value,
                'available_at' => $retryable ? now()->addSeconds($delay) : now(),
            ];
            $batch->items()->update($attributes);
            $batch->globalChanges()->update($attributes);
            if ($versionIds->isNotEmpty()) {
                ConfigVersion::withoutGlobalScopes()->whereIn('id', $versionIds)
                    ->update(['status' => ConfigVersionStatus::DeliveryFailed->value]);
            }
        });
        $fresh = $batch->refresh();
        $this->audit->record('static.delivery.failed', null, $fresh->creator, $fresh, newValues: [
            'batch_id' => $fresh->id,
            'manifest_hash' => $fresh->manifest_hash,
            'error_code' => $fresh->error_code,
            'retry_scheduled' => $retryable,
        ]);
    }

    private function deferForBudget(StaticDeliveryBatch $batch, StaticDeliveryException $exception): void
    {
        $nextMonth = now()->utc()->addMonthNoOverflow()->startOfMonth();
        DB::transaction(function () use ($batch, $exception, $nextMonth): void {
            $batch->update([
                'status' => StaticDeliveryStatus::RetryScheduled,
                'error_code' => $exception->category,
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
                'next_retry_at' => $nextMonth,
            ]);
            $attributes = [
                'status' => StaticDeliveryStatus::RetryScheduled->value,
                'available_at' => $nextMonth,
            ];
            $batch->items()->update($attributes);
            $batch->globalChanges()->update($attributes);
        });
        $fresh = $batch->refresh();
        $this->audit->record('static.delivery.budget_deferred', null, $fresh->creator, $fresh, newValues: [
            'batch_id' => $fresh->id,
            'manifest_hash' => $fresh->manifest_hash,
            'error_code' => $fresh->error_code,
            'next_retry_at' => $fresh->next_retry_at?->toIso8601String(),
        ]);
    }

    private function assertDeploymentBudget(StaticDeliveryPriority $priority): void
    {
        $budget = max(1, (int) config('static-delivery.monthly_deployment_budget', 450));
        $reserve = min($budget, max(0, (int) config('static-delivery.emergency_reserve', 25)));
        $used = StaticDeliveryBatch::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereNotNull('submitted_at')
            ->where('is_deduplicated', false)->count();
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

    private function auditManualDeployment(
        User $actor,
        string $reason,
        StaticDeliveryManualOutcome $outcome,
        ?StaticDeliveryBatch $batch = null,
        int $acceleratedItems = 0,
    ): void {
        $this->audit->record('static.delivery.deploy_now.requested', $actor->organization_id, $actor, $batch, newValues: [
            'reason' => $reason,
            'outcome' => $outcome->value,
            'accelerated_items' => $acceleratedItems,
            'batch_id' => $batch?->id,
            'batch_status' => $batch?->status->value,
            'priority' => $batch?->priority->value,
            'manifest_hash' => $batch?->manifest_hash,
            'deduplicated' => (bool) $batch?->is_deduplicated,
        ]);
    }
}
