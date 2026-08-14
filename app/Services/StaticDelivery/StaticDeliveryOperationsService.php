<?php

namespace App\Services\StaticDelivery;

use App\Enums\StaticDeliveryPriority;
use App\Enums\StaticDeliveryStatus;
use App\Models\StaticDeliveryBatch;
use App\Models\StaticDeliveryItem;

final class StaticDeliveryOperationsService
{
    public function __construct(private readonly StaticDeliveryWindow $window) {}

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $pendingStatuses = [StaticDeliveryStatus::Pending->value, StaticDeliveryStatus::RetryScheduled->value];
        $pendingQuery = StaticDeliveryItem::withoutGlobalScopes()->whereIn('status', $pendingStatuses);
        $pendingCount = (clone $pendingQuery)->count();
        $oldestPending = (clone $pendingQuery)->oldest('created_at')->first();
        $nextNormalAt = StaticDeliveryItem::withoutGlobalScopes()
            ->where('status', StaticDeliveryStatus::Pending->value)
            ->where('priority', StaticDeliveryPriority::Normal->value)
            ->min('available_at');

        $latestSuccessful = StaticDeliveryBatch::query()
            ->where('status', StaticDeliveryStatus::Deployed->value)
            ->latest('deployed_at')
            ->first();
        $latestRemote = StaticDeliveryBatch::query()
            ->where('status', StaticDeliveryStatus::Deployed->value)
            ->whereNotNull('submitted_at')
            ->latest('deployed_at')
            ->first();

        $budget = max(1, (int) config('static-delivery.monthly_deployment_budget', 450));
        $reserve = min($budget, max(0, (int) config('static-delivery.emergency_reserve', 25)));
        $normalBudget = max(0, $budget - $reserve);
        $used = StaticDeliveryBatch::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->whereNotNull('submitted_at')
            ->where('is_deduplicated', false)
            ->count();
        $warningThreshold = min($normalBudget, max(0, (int) config('static-delivery.budget_warning_threshold', 400)));
        $projected = $used === 0
            ? 0
            : (int) ceil($used * now()->daysInMonth / max(1, now()->day));

        $fileWarning = max(1, (int) config('static-delivery.file_budget.warning_threshold', 18000));
        $fileLimit = max($fileWarning, (int) config('static-delivery.file_budget.hard_limit', 20000));
        $failedCount = StaticDeliveryBatch::query()->where('status', StaticDeliveryStatus::Failed->value)->count();
        $retryCount = StaticDeliveryBatch::query()->where('status', StaticDeliveryStatus::RetryScheduled->value)->count();
        $uploadingCount = StaticDeliveryBatch::query()->where('status', StaticDeliveryStatus::Uploading->value)->count();
        $overdueCount = StaticDeliveryItem::withoutGlobalScopes()
            ->where('status', StaticDeliveryStatus::Pending->value)
            ->where('priority', StaticDeliveryPriority::Normal->value)
            ->where('available_at', '<', now()->subMinutes(max(0, (int) config('static-delivery.pending_stale_grace_minutes', 5))))
            ->count();

        $warnings = [];
        if ($used >= $normalBudget) {
            $warnings[] = $this->warning('NORMAL_BUDGET_EXHAUSTED', 'danger', 'The monthly normal deployment budget is exhausted. Only genuine urgent work may use remaining emergency reserve.');
        } elseif ($warningThreshold > 0 && $used >= $warningThreshold) {
            $warnings[] = $this->warning('NORMAL_BUDGET_APPROACHING', 'warning', 'The monthly normal deployment budget is approaching its configured warning threshold.');
        }
        if ($used > $normalBudget) {
            $warnings[] = $this->warning('EMERGENCY_RESERVE_CONSUMED', 'danger', 'Urgent deployments are consuming the configured emergency reserve.');
        }
        if (($latestSuccessful?->file_count ?? 0) >= $fileWarning) {
            $warnings[] = $this->warning('STATIC_FILE_BUDGET_APPROACHING', 'warning', 'The latest confirmed snapshot is near the configured static file hard limit.');
        }
        if ($failedCount > 0 || $retryCount > 0) {
            $warnings[] = $this->warning('STATIC_DELIVERY_FAILURE', 'danger', 'A failed or retry-scheduled static deployment requires operational attention.');
        }
        if ($overdueCount > 0) {
            $warnings[] = $this->warning('STATIC_DELIVERY_OVERDUE', 'warning', 'Pending normal work has remained beyond its expected batch boundary.');
        }

        return [
            'current' => [
                'pending_count' => $pendingCount,
                'oldest_pending_at' => $oldestPending?->created_at,
                'next_normal_batch_at' => $nextNormalAt ?: $this->window->nextNormalBoundary(),
                'manifest_hash' => $latestSuccessful?->manifest_hash,
                'last_successful' => $latestSuccessful,
                'last_remote' => $latestRemote,
                'status' => $this->status($uploadingCount, $failedCount, $retryCount, $pendingCount, $latestSuccessful !== null),
            ],
            'budget' => [
                'used' => $used,
                'normal_budget' => $normalBudget,
                'normal_remaining' => max(0, $normalBudget - $used),
                'emergency_reserve' => $reserve,
                'emergency_remaining' => max(0, $budget - max($used, $normalBudget)),
                'emergency_consumed' => max(0, $used - $normalBudget),
                'total' => $budget,
                'warning_threshold' => $warningThreshold,
                'projected' => $projected,
                'state' => $used >= $normalBudget ? 'EXHAUSTED' : (($warningThreshold > 0 && $used >= $warningThreshold) ? 'WARNING' : 'HEALTHY'),
            ],
            'snapshot' => [
                'file_count' => (int) ($latestSuccessful?->file_count ?? 0),
                'total_bytes' => (int) ($latestSuccessful?->total_bytes ?? 0),
                'warning_threshold' => $fileWarning,
                'hard_limit' => $fileLimit,
                'near_limit' => (int) ($latestSuccessful?->file_count ?? 0) >= $fileWarning,
            ],
            'recent' => StaticDeliveryBatch::query()->with('creator')->latest()->limit(25)->get(),
            'warnings' => $warnings,
            'warning_count' => count($warnings),
        ];
    }

    /** @return array{code: string, severity: string, message: string} */
    private function warning(string $code, string $severity, string $message): array
    {
        return compact('code', 'severity', 'message');
    }

    private function status(int $uploading, int $failed, int $retry, int $pending, bool $hasDeployment): string
    {
        return match (true) {
            $uploading > 0 => 'UPLOADING',
            $failed > 0 => 'FAILED',
            $retry > 0 => 'RETRY_SCHEDULED',
            $pending > 0 => 'PENDING',
            $hasDeployment => 'DEPLOYED',
            default => 'IDLE',
        };
    }
}
