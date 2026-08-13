<?php

namespace App\Services\Operations;

use App\Enums\StaticDeliveryStatus;
use App\Models\AuditLog;
use App\Models\DemandAccount;
use App\Models\GamConnection;
use App\Models\Placement;
use App\Models\PlatformControl;
use App\Models\ReportImportJob;
use App\Models\Site;
use App\Models\StaticDeliveryBatch;
use App\Models\StaticDeliveryItem;
use Illuminate\Support\Facades\DB;

final class OperationsOverviewService
{
    /**
     * Build a production-operations snapshot exclusively from persisted control-plane state.
     * No third-party API calls or server-resource telemetry are performed here.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $failedQueueJobs = DB::table('failed_jobs')->count();
        $failedImports = ReportImportJob::withoutGlobalScopes()->where('status', 'FAILED')->count();
        $failedDeliveries = StaticDeliveryItem::withoutGlobalScopes()->where('status', StaticDeliveryStatus::Failed->value)->count();
        $retryScheduledDeliveries = StaticDeliveryItem::withoutGlobalScopes()->where('status', StaticDeliveryStatus::RetryScheduled->value)->count();

        return [
            'failed_queue_jobs' => $failedQueueJobs,
            'failed_report_imports' => $failedImports,
            'failed_static_deliveries' => $failedDeliveries,
            'retry_scheduled_deliveries' => $retryScheduledDeliveries,
            'gam_unhealthy' => GamConnection::withoutGlobalScopes()
                ->where('is_enabled', true)
                ->whereIn('health_status', ['DEGRADED', 'FAILED', 'UNKNOWN'])
                ->count(),
            'demand_unhealthy' => DemandAccount::withoutGlobalScopes()
                ->where('is_enabled', true)
                ->where(function ($query): void {
                    $query->where('approval_status', '!=', 'APPROVED')
                        ->orWhereNull('last_successful_sync_at');
                })->count(),
            'paused_or_disabled_sites' => Site::withoutGlobalScopes()
                ->whereIn('status', ['SUSPENDED', 'ARCHIVED'])
                ->count(),
            'disabled_placements' => Placement::withoutGlobalScopes()
                ->where('status', 'DISABLED')
                ->count(),
            'active_controls' => PlatformControl::query()->where('is_disabled', true)->count(),
            'stale_configuration' => $this->staleConfigurationCount(),
            'latest_events' => AuditLog::query()
                ->where(function ($query): void {
                    $query->where('event', 'like', 'operations.%')
                        ->orWhere('event', 'like', 'static.delivery.%')
                        ->orWhere('event', 'like', 'gam.%')
                        ->orWhere('event', 'like', 'reporting.%')
                        ->orWhere('event', 'like', 'demand.%');
                })
                ->latest('created_at')
                ->limit(12)
                ->get(),
            'retry_exhausted' => StaticDeliveryBatch::query()
                ->where('status', StaticDeliveryStatus::Failed->value)
                ->whereNull('next_retry_at')
                ->count(),
        ];
    }

    private function staleConfigurationCount(): int
    {
        return Site::withoutGlobalScopes()
            ->whereHas('siteConfig', function ($query): void {
                $query->whereColumn('preview_version', '>', 'production_version');
            })
            ->count();
    }
}
