<?php

namespace App\Services\StaticDelivery;

use App\Enums\StaticDeliveryPriority;
use App\Enums\StaticDeliveryStatus;
use App\Models\StaticGlobalArtifactChange;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class SupplyChainStaticPublisher
{
    public function __construct(private readonly StaticDeliveryWindow $window) {}

    /** @param array<string, mixed> $context */
    public function queueNormal(array $context = [], ?User $actor = null): StaticGlobalArtifactChange
    {
        return $this->queue(StaticDeliveryPriority::Normal, $context, $actor);
    }

    /** @param array<string, mixed> $context */
    public function queueUrgent(array $context = [], ?User $actor = null): StaticGlobalArtifactChange
    {
        return $this->queue(StaticDeliveryPriority::Urgent, $context, $actor);
    }

    /** @param array<string, mixed> $context */
    public function queueForModel(Model $model, bool $urgent = false, array $context = []): StaticGlobalArtifactChange
    {
        return $this->queue($urgent ? StaticDeliveryPriority::Urgent : StaticDeliveryPriority::Normal, array_merge([
            'model' => $model::class,
            'model_id' => (string) $model->getKey(),
        ], $context));
    }

    /** @param array<string, mixed> $context */
    private function queue(StaticDeliveryPriority $priority, array $context, ?User $actor): StaticGlobalArtifactChange
    {
        return DB::transaction(function () use ($priority, $context, $actor): StaticGlobalArtifactChange {
            $change = StaticGlobalArtifactChange::query()
                ->where('artifact_type', StaticGlobalArtifactChange::SUPPLY_CHAIN)
                ->where('status', StaticDeliveryStatus::Pending->value)
                ->whereNull('batch_id')
                ->lockForUpdate()
                ->oldest('created_at')
                ->first();

            if ($change) {
                $merged = collect($change->context ?? [])->merge($context)->all();
                $updates = [
                    'event_count' => (int) $change->event_count + 1,
                    'context' => $merged,
                ];
                if ($priority === StaticDeliveryPriority::Urgent) {
                    $updates['priority'] = StaticDeliveryPriority::Urgent;
                    $updates['available_at'] = now()->utc();
                }
                if ($actor && ! $change->created_by) {
                    $updates['created_by'] = $actor->id;
                }
                $change->update($updates);

                return $change->refresh();
            }

            return StaticGlobalArtifactChange::create([
                'artifact_type' => StaticGlobalArtifactChange::SUPPLY_CHAIN,
                'status' => StaticDeliveryStatus::Pending,
                'priority' => $priority,
                'event_count' => 1,
                'context' => $context,
                'attempts' => 0,
                'available_at' => $priority === StaticDeliveryPriority::Urgent
                    ? now()->utc()
                    : $this->window->nextNormalBoundary(),
                'created_by' => $actor?->id,
            ]);
        });
    }
}
