<?php

namespace App\Services\ControlPlane\Actions;

use App\Enums\GamHealthStatus;
use App\Enums\GamOperationStatus;
use App\Enums\StaticDeliveryStatus;
use App\Models\GamApiOperation;
use App\Models\GamConnection;
use App\Models\StaticDeliveryBatch;
use App\Models\User;
use App\Services\ControlPlane\Contracts\ActionCenterProvider;
use Illuminate\Support\Facades\DB;

final class IntegrationActions implements ActionCenterProvider
{
    public function actions(User $user): array
    {
        $items = [];

        if ($user->hasPermission('gam.connections.view')) {
            $items[] = $this->item('gam-health', 'Unhealthy GAM connections', GamConnection::withoutGlobalScopes()
                ->where('is_enabled', true)->whereIn('health_status', [GamHealthStatus::Degraded->value, GamHealthStatus::Failed->value])->count(),
                'Enabled GAM connections report degraded or failed health.', 'admin.gam.connections.index', 10);
        }

        if ($user->hasPermission('operations.view')) {
            $operationFailures = GamApiOperation::withoutGlobalScopes()->where('status', GamOperationStatus::Failed->value)->count();
            $deliveryFailures = StaticDeliveryBatch::query()->whereIn('status', [StaticDeliveryStatus::Failed->value, StaticDeliveryStatus::RetryScheduled->value])->count();
            $queueFailures = DB::table('failed_jobs')->count();
            $items[] = $this->item('production-failures', 'Failed production operations', $operationFailures + $deliveryFailures + $queueFailures,
                'GAM operations, static delivery, or queued work require remediation.', 'admin.operations.index', 5, 'danger');
        }

        return $items;
    }

    private function item(string $key, string $label, int $count, string $description, string $route, int $priority, string $severity = 'warning'): array
    {
        return compact('key', 'label', 'count', 'description', 'route', 'priority', 'severity') + ['parameters' => []];
    }
}
