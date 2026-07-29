<?php

namespace App\Console\Commands;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Models\CampaignApprovalLog;
use App\Services\Campaigns\CampaignReportingService;
use Illuminate\Console\Command;
use Throwable;

class MonitorDirectCampaigns extends Command
{
    protected $signature = 'campaigns:monitor {--reconcile : Compare local plans and remote GAM state}';
    protected $description = 'Advance direct campaign lifecycle and request aggregated GAM delivery reports.';

    public function handle(CampaignReportingService $reporting): int
    {
        $campaigns = Campaign::withoutGlobalScopes()
            ->whereIn('status', [CampaignStatus::Scheduled->value, CampaignStatus::Active->value, CampaignStatus::Paused->value])
            ->with(['networkInstances.connection', 'goals'])
            ->get();
        $failures = 0;
        foreach ($campaigns as $campaign) {
            try {
                if ($campaign->status === CampaignStatus::Scheduled && $campaign->starts_at->isPast()) {
                    $campaign->update(['status' => CampaignStatus::Active, 'activated_at' => now()]);
                    $this->log($campaign, 'SYSTEM_ACTIVATED');
                }
                if ($campaign->ends_at->isPast() && $campaign->status !== CampaignStatus::Completed) {
                    $campaign->update(['status' => CampaignStatus::Completed, 'completed_at' => now()]);
                    $this->log($campaign, 'SYSTEM_COMPLETED');
                }
                $reporting->requestDeliveryReports($campaign);
                if ($this->option('reconcile')) $reporting->reconcile($campaign);
            } catch (Throwable $exception) {
                $failures++;
                $this->error($campaign->public_key.': '.$exception->getMessage());
            }
        }
        $this->info('Processed '.$campaigns->count().' campaign(s); '.$failures.' failure(s).');
        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function log(Campaign $campaign, string $action): void
    {
        CampaignApprovalLog::withoutGlobalScopes()->create([
            'organization_id' => $campaign->organization_id,
            'campaign_id' => $campaign->id,
            'action' => $action,
            'from_status' => null,
            'to_status' => $campaign->status->value,
            'created_at' => now(),
        ]);
    }
}
