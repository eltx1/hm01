<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignNetworkStatus;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignDeliveryLog;
use App\Models\CampaignNetworkInstance;
use App\Models\GamRemoteObject;
use App\Services\Gam\GamConnectorManager;
use RuntimeException;
use Throwable;

final class CampaignReportingService
{
    public function __construct(
        private readonly GamConnectorManager $connectors,
        private readonly CampaignNetworkPlanner $planner,
    ) {
    }

    public function requestDeliveryReports(Campaign $campaign): array
    {
        $results = [];
        foreach ($campaign->networkInstances as $instance) {
            $lineItem = $this->lineItemMapping($instance);
            if (! $lineItem) {
                $results[$instance->id] = ['success' => false, 'error' => 'Line item is not deployed.'];
                continue;
            }
            $result = $this->connectors->for($instance->connection)->runReport([
                'dimensions' => ['DATE', 'LINE_ITEM_ID'],
                'columns' => ['AD_SERVER_IMPRESSIONS', 'AD_SERVER_CLICKS', 'VIDEO_VIEWERSHIP_START', 'AD_SERVER_CPM_AND_CPC_REVENUE'],
                'dateRangeType' => 'CUSTOM_DATE',
                'startDate' => $campaign->starts_at->toDateString(),
                'endDate' => ($campaign->ends_at->isPast() ? $campaign->ends_at : now())->toDateString(),
                'statement' => [
                    'query' => 'WHERE LINE_ITEM_ID = :lineItemId',
                    'values' => [['key' => 'lineItemId', 'value' => ['__type' => 'NumberValue', 'value' => $lineItem->remote_object_id]]],
                ],
            ], [
                'dry_run' => false,
                'idempotency_key' => 'campaign-report:'.hash('sha256', $instance->id.'|'.now()->toDateString()),
            ]);
            $results[$instance->id] = ['success' => $result->success, 'data' => $result->data, 'error' => $result->errorMessage];
        }
        return $results;
    }

    public function recordAggregated(CampaignNetworkInstance $instance, array $rows): int
    {
        $count = 0;
        foreach ($rows as $row) {
            CampaignDeliveryLog::withoutGlobalScopes()->updateOrCreate(
                [
                    'campaign_network_instance_id' => $instance->id,
                    'report_date' => $row['report_date'],
                    'source' => $row['source'] ?? 'GAM',
                    'external_report_id' => (string) $row['external_report_id'],
                ],
                [
                    'organization_id' => $instance->campaign->organization_id,
                    'campaign_id' => $instance->campaign_id,
                    'impressions' => (int) ($row['impressions'] ?? 0),
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'views' => (int) ($row['views'] ?? 0),
                    'spend_minor' => (int) ($row['spend_minor'] ?? 0),
                    'dimensions' => $row['dimensions'] ?? [],
                    'imported_at' => now(),
                ],
            );
            $count++;
        }
        $this->refreshGoals($instance->campaign);
        return $count;
    }

    public function summary(Campaign $campaign): array
    {
        $query = CampaignDeliveryLog::withoutGlobalScopes()->where('campaign_id', $campaign->id);
        return [
            'impressions' => (int) (clone $query)->sum('impressions'),
            'clicks' => (int) (clone $query)->sum('clicks'),
            'views' => (int) (clone $query)->sum('views'),
            'spend_minor' => (int) (clone $query)->sum('spend_minor'),
            'by_network' => (clone $query)->selectRaw('campaign_network_instance_id, SUM(impressions) impressions, SUM(clicks) clicks, SUM(views) views, SUM(spend_minor) spend_minor')
                ->groupBy('campaign_network_instance_id')->get()->keyBy('campaign_network_instance_id')->toArray(),
            'daily' => (clone $query)->selectRaw('report_date, SUM(impressions) impressions, SUM(clicks) clicks, SUM(views) views, SUM(spend_minor) spend_minor')
                ->groupBy('report_date')->orderBy('report_date')->get()->toArray(),
        ];
    }

    public function reconcile(Campaign $campaign): array
    {
        $results = [];
        foreach ($campaign->networkInstances as $instance) {
            try {
                $plan = $this->planner->plan($instance);
                $mapping = $this->lineItemMapping($instance);
                if (! $mapping) throw new RuntimeException('Remote line-item mapping is missing.');
                $remote = $this->connectors->for($instance->connection)->getObjectByRemoteId('LineItemService', $mapping->remote_object_id, ['method' => 'getLineItemsByStatement']);
                if (! $remote->success) throw new RuntimeException($remote->errorMessage ?: 'Remote line item could not be read.');
                $remoteStatus = (string) (data_get($remote->data, 'rval.results.0.status') ?? data_get($remote->data, 'results.0.status') ?? data_get($remote->data, 'status') ?? 'UNKNOWN');
                $expectedPaused = $campaign->status === CampaignStatus::Paused;
                $statusDrift = $expectedPaused ? ! in_array($remoteStatus, ['PAUSED', 'INACTIVE'], true) : ($campaign->status === CampaignStatus::Active && $remoteStatus === 'PAUSED');
                $payloadDrift = $plan['pendingObjects'] > 0;
                $drift = $statusDrift || $payloadDrift;
                $instance->update([
                    'remote_status' => $remoteStatus,
                    'last_synced_at' => now(),
                    'drift_detected_at' => $drift ? now() : null,
                    'status' => $drift ? CampaignNetworkStatus::Drifted : $instance->status,
                    'last_error' => null,
                ]);
                $results[$instance->id] = ['success' => true, 'drift' => $drift, 'status_drift' => $statusDrift, 'payload_drift' => $payloadDrift, 'remote_status' => $remoteStatus];
            } catch (Throwable $exception) {
                $instance->update(['status' => CampaignNetworkStatus::Drifted, 'drift_detected_at' => now(), 'last_error' => $exception->getMessage()]);
                $results[$instance->id] = ['success' => false, 'drift' => true, 'error' => $exception->getMessage()];
            }
        }
        return $results;
    }

    private function refreshGoals(Campaign $campaign): void
    {
        $summary = $this->summary($campaign);
        foreach ($campaign->goals as $goal) {
            $delivered = match ($goal->goal_type) {
                'IMPRESSIONS' => $summary['impressions'],
                'CLICKS' => $summary['clicks'],
                'VIEWS' => $summary['views'],
                default => $goal->delivered_value,
            };
            $goal->update(['delivered_value' => $delivered]);
        }
    }

    private function lineItemMapping(CampaignNetworkInstance $instance): ?GamRemoteObject
    {
        return GamRemoteObject::withoutGlobalScopes()
            ->where('gam_connection_id', $instance->gam_connection_id)
            ->where('local_object_type', 'campaign_network_instance')
            ->where('local_object_id', $instance->id)
            ->where('remote_object_type', 'line_item')->first();
    }
}
