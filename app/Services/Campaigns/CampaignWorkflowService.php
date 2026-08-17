<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignCreativeStatus;
use App\Enums\CampaignPricingModel;
use App\Enums\CampaignStatus;
use App\Models\Advertiser;
use App\Models\Campaign;
use App\Models\CampaignApprovalLog;
use App\Models\CampaignCreative;
use App\Models\CampaignGoal;
use App\Models\CampaignPlacement;
use App\Models\CampaignSite;
use App\Models\CampaignTarget;
use App\Models\Placement;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CampaignWorkflowService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly AdvertiserInvoiceService $invoices,
        private readonly CampaignDeliveryCapabilityService $deliveryCapability,
    ) {
    }

    public function create(Advertiser $advertiser, array $data, User $actor): Campaign
    {
        if (! $this->deliveryCapability->featureEnabled()) {
            throw ValidationException::withMessages([
                'campaign_feature' => 'Advertiser Campaign creation is currently unavailable.',
            ]);
        }

        return DB::transaction(function () use ($advertiser, $data, $actor): Campaign {
            $campaign = Campaign::withoutGlobalScopes()->create([
                'public_key' => 'HC_'.Str::upper(Str::random(20)),
                'organization_id' => $advertiser->organization_id,
                'advertiser_id' => $advertiser->id,
                'name' => trim((string) $data['name']),
                'objective' => trim((string) $data['objective']),
                'pricing_model' => CampaignPricingModel::from((string) $data['pricing_model']),
                'status' => CampaignStatus::Draft,
                'starts_at' => $data['starts_at'],
                'ends_at' => $data['ends_at'],
                'currency' => strtoupper((string) ($data['currency'] ?? 'USD')),
                'total_budget_minor' => (int) $data['total_budget_minor'],
                'daily_budget_minor' => isset($data['daily_budget_minor']) ? (int) $data['daily_budget_minor'] : null,
                'frequency_cap_impressions' => isset($data['frequency_cap_impressions']) ? (int) $data['frequency_cap_impressions'] : null,
                'frequency_cap_days' => isset($data['frequency_cap_days']) ? (int) $data['frequency_cap_days'] : null,
                'landing_url' => $data['landing_url'] ?? null,
                'advertiser_notes' => $data['advertiser_notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $campaign->budget()->create([
                'organization_id' => $campaign->organization_id,
                'pricing_model' => $campaign->pricing_model,
                'currency' => $campaign->currency,
                'total_minor' => $campaign->total_budget_minor,
                'daily_minor' => $campaign->daily_budget_minor,
                'unit_price_minor' => (int) ($data['unit_price_minor'] ?? 0),
            ]);
            $this->syncGoals($campaign, $data);
            $this->syncTargets($campaign, $data);
            $this->syncInventory($campaign, $data['site_ids'] ?? [], $data['placement_ids'] ?? []);
            $this->log($campaign, $actor, 'CREATED', null, CampaignStatus::Draft->value);
            $this->audit->record('campaign.created', $campaign->organization_id, $actor, $campaign, [], $campaign->toArray());

            return $campaign->fresh(['goals', 'targets', 'sites.site', 'placements.placement', 'budget']);
        });
    }

    public function update(Campaign $campaign, array $data, User $actor): Campaign
    {
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Rejected, CampaignStatus::Approved], true)) {
            throw ValidationException::withMessages(['status' => 'This campaign can no longer be edited in its current status.']);
        }

        return DB::transaction(function () use ($campaign, $data, $actor): Campaign {
            $before = $campaign->toArray();
            $campaign->update([
                'name' => trim((string) ($data['name'] ?? $campaign->name)),
                'objective' => trim((string) ($data['objective'] ?? $campaign->objective)),
                'pricing_model' => isset($data['pricing_model']) ? CampaignPricingModel::from((string) $data['pricing_model']) : $campaign->pricing_model,
                'starts_at' => $data['starts_at'] ?? $campaign->starts_at,
                'ends_at' => $data['ends_at'] ?? $campaign->ends_at,
                'currency' => strtoupper((string) ($data['currency'] ?? $campaign->currency)),
                'total_budget_minor' => (int) ($data['total_budget_minor'] ?? $campaign->total_budget_minor),
                'daily_budget_minor' => array_key_exists('daily_budget_minor', $data) ? ($data['daily_budget_minor'] !== null ? (int) $data['daily_budget_minor'] : null) : $campaign->daily_budget_minor,
                'frequency_cap_impressions' => array_key_exists('frequency_cap_impressions', $data) ? ($data['frequency_cap_impressions'] !== null ? (int) $data['frequency_cap_impressions'] : null) : $campaign->frequency_cap_impressions,
                'frequency_cap_days' => array_key_exists('frequency_cap_days', $data) ? ($data['frequency_cap_days'] !== null ? (int) $data['frequency_cap_days'] : null) : $campaign->frequency_cap_days,
                'landing_url' => $data['landing_url'] ?? $campaign->landing_url,
                'advertiser_notes' => $data['advertiser_notes'] ?? $campaign->advertiser_notes,
                'updated_by' => $actor->id,
            ]);
            $campaign->budget()->updateOrCreate([], [
                'organization_id' => $campaign->organization_id,
                'pricing_model' => $campaign->pricing_model,
                'currency' => $campaign->currency,
                'total_minor' => $campaign->total_budget_minor,
                'daily_minor' => $campaign->daily_budget_minor,
                'unit_price_minor' => (int) ($data['unit_price_minor'] ?? $campaign->budget?->unit_price_minor ?? 0),
            ]);
            if (array_key_exists('impression_goal', $data) || array_key_exists('click_goal', $data)) $this->syncGoals($campaign, $data);
            if (array_key_exists('countries', $data) || array_key_exists('devices', $data)) $this->syncTargets($campaign, $data);
            if (array_key_exists('site_ids', $data) || array_key_exists('placement_ids', $data)) {
                $this->syncInventory($campaign, $data['site_ids'] ?? $campaign->sites()->pluck('site_id')->all(), $data['placement_ids'] ?? $campaign->placements()->pluck('placement_id')->all());
            }
            if ($campaign->status === CampaignStatus::Rejected) $campaign->update(['status' => CampaignStatus::Draft]);
            $this->log($campaign, $actor, 'UPDATED', $before['status'] ?? null, $campaign->status->value);
            $this->audit->record('campaign.updated', $campaign->organization_id, $actor, $campaign, $before, $campaign->fresh()->toArray());

            return $campaign->fresh(['goals', 'targets', 'sites.site', 'placements.placement', 'budget']);
        });
    }

    public function submit(Campaign $campaign, User $actor): Campaign
    {
        if (! in_array($campaign->status, [CampaignStatus::Draft, CampaignStatus::Rejected], true)) {
            throw ValidationException::withMessages(['status' => 'Only draft or rejected campaigns may be submitted.']);
        }
        $campaign->loadMissing(['sites', 'creatives', 'budget']);
        $errors = [];
        if ($campaign->sites->where('is_active', true)->isEmpty()) $errors['site_ids'] = 'Select at least one website.';
        if ($campaign->creatives->where('is_active', true)->isEmpty()) $errors['creatives'] = 'Upload at least one creative.';
        if ($campaign->ends_at <= $campaign->starts_at) $errors['ends_at'] = 'The end date must be after the start date.';
        if ($campaign->total_budget_minor <= 0 && ! in_array($campaign->pricing_model, [CampaignPricingModel::House, CampaignPricingModel::Bonus], true)) $errors['total_budget_minor'] = 'A positive campaign budget is required.';
        if ($errors) throw ValidationException::withMessages($errors);
        $this->deliveryCapability->requireAvailable($campaign, 'submission');

        return DB::transaction(function () use ($campaign, $actor): Campaign {
            $from = $campaign->status->value;
            $campaign->creatives()->where('status', CampaignCreativeStatus::Draft->value)->update(['status' => CampaignCreativeStatus::PendingReview->value]);
            $campaign->update(['status' => CampaignStatus::PendingReview, 'submitted_at' => now(), 'updated_by' => $actor->id]);
            $this->log($campaign, $actor, 'SUBMITTED', $from, CampaignStatus::PendingReview->value);
            $this->audit->record('campaign.submitted', $campaign->organization_id, $actor, $campaign, ['status' => $from], ['status' => CampaignStatus::PendingReview->value]);
            return $campaign->fresh();
        });
    }

    public function reviewCreative(CampaignCreative $creative, bool $approved, User $actor, ?string $reason = null): CampaignCreative
    {
        $from = $creative->status->value;
        $status = $approved ? CampaignCreativeStatus::Approved : CampaignCreativeStatus::Rejected;
        $creative->update([
            'status' => $status,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'rejection_reason' => $approved ? null : $reason,
        ]);
        $this->log($creative->campaign, $actor, $approved ? 'CREATIVE_APPROVED' : 'CREATIVE_REJECTED', $from, $status->value, $reason, $creative);
        return $creative->fresh();
    }

    public function approve(Campaign $campaign, User $actor): Campaign
    {
        if ($campaign->status !== CampaignStatus::PendingReview) throw ValidationException::withMessages(['status' => 'The campaign must be pending review.']);
        $campaign->loadMissing('creatives');
        if ($campaign->creatives->where('is_active', true)->contains(fn (CampaignCreative $creative) => $creative->status !== CampaignCreativeStatus::Approved)) {
            throw ValidationException::withMessages(['creatives' => 'Every active creative must be approved first.']);
        }
        $this->deliveryCapability->requireAvailable($campaign, 'approval');
        $from = $campaign->status->value;
        $campaign->update(['status' => CampaignStatus::Approved, 'approved_at' => now(), 'updated_by' => $actor->id]);
        $this->invoices->ensureForCampaign($campaign);
        $this->log($campaign, $actor, 'APPROVED', $from, CampaignStatus::Approved->value);
        return $campaign->fresh();
    }

    public function reject(Campaign $campaign, User $actor, string $reason): Campaign
    {
        if (! in_array($campaign->status, [CampaignStatus::PendingReview, CampaignStatus::Approved], true)) throw ValidationException::withMessages(['status' => 'The campaign is not reviewable.']);
        $from = $campaign->status->value;
        $campaign->update(['status' => CampaignStatus::Rejected, 'updated_by' => $actor->id]);
        $this->log($campaign, $actor, 'REJECTED', $from, CampaignStatus::Rejected->value, $reason);
        return $campaign->fresh();
    }

    public function schedule(Campaign $campaign, User $actor): Campaign
    {
        if ($campaign->status !== CampaignStatus::Approved) throw ValidationException::withMessages(['status' => 'Approve the campaign before scheduling it.']);
        $this->deliveryCapability->requireAvailable($campaign, 'scheduling');
        $status = $campaign->starts_at->isFuture() ? CampaignStatus::Scheduled : CampaignStatus::Active;
        $campaign->update([
            'status' => $status, 'scheduled_at' => now(),
            'activated_at' => $status === CampaignStatus::Active ? now() : null,
            'updated_by' => $actor->id,
        ]);
        $this->log($campaign, $actor, 'SCHEDULED', CampaignStatus::Approved->value, $status->value);
        return $campaign->fresh();
    }

    public function pause(Campaign $campaign, User $actor): Campaign
    {
        if (! in_array($campaign->status, [CampaignStatus::Scheduled, CampaignStatus::Active], true)) throw ValidationException::withMessages(['status' => 'Only scheduled or active campaigns may be paused.']);
        $from = $campaign->status->value;
        $campaign->update(['status' => CampaignStatus::Paused, 'paused_at' => now(), 'updated_by' => $actor->id]);
        $this->log($campaign, $actor, 'PAUSED', $from, CampaignStatus::Paused->value);
        return $campaign->fresh();
    }

    public function resume(Campaign $campaign, User $actor): Campaign
    {
        if ($campaign->status !== CampaignStatus::Paused) throw ValidationException::withMessages(['status' => 'Only paused campaigns may be resumed.']);
        $this->deliveryCapability->requireAvailable($campaign, 'resume');
        $status = $campaign->starts_at->isFuture() ? CampaignStatus::Scheduled : CampaignStatus::Active;
        $campaign->update(['status' => $status, 'paused_at' => null, 'activated_at' => $status === CampaignStatus::Active ? ($campaign->activated_at ?? now()) : $campaign->activated_at, 'updated_by' => $actor->id]);
        $this->log($campaign, $actor, 'RESUMED', CampaignStatus::Paused->value, $status->value);
        return $campaign->fresh();
    }

    public function complete(Campaign $campaign, User $actor): Campaign
    {
        if (in_array($campaign->status, [CampaignStatus::Completed, CampaignStatus::Archived], true)) return $campaign;
        $from = $campaign->status->value;
        $campaign->update(['status' => CampaignStatus::Completed, 'completed_at' => now(), 'updated_by' => $actor->id]);
        $this->log($campaign, $actor, 'COMPLETED', $from, CampaignStatus::Completed->value);
        return $campaign->fresh();
    }

    public function addBonus(Campaign $campaign, int $units, string $note, User $actor): Campaign
    {
        $budget = $campaign->budget()->firstOrFail();
        $before = $budget->bonus_units;
        $budget->update(['bonus_units' => $before + $units, 'bonus_note' => $note]);
        $this->log($campaign, $actor, 'BONUS_ADDED', null, null, $note, null, ['units' => $units, 'total_bonus_units' => $budget->fresh()->bonus_units]);
        return $campaign->fresh('budget');
    }

    public function changeTargeting(Campaign $campaign, array $data, User $actor): Campaign
    {
        return DB::transaction(function () use ($campaign, $data, $actor): Campaign {
            $this->syncTargets($campaign, $data);
            $this->syncInventory($campaign, $data['site_ids'] ?? $campaign->sites()->pluck('site_id')->all(), $data['placement_ids'] ?? []);
            $campaign->networkInstances()->update(['status' => 'PENDING', 'deployment_plan' => null, 'cursor' => 0, 'completed_objects' => 0, 'last_error' => null]);
            $this->log($campaign, $actor, 'TARGETING_CHANGED', $campaign->status->value, $campaign->status->value, metadata: $data);
            return $campaign->fresh(['targets', 'sites', 'placements', 'networkInstances']);
        });
    }

    private function syncGoals(Campaign $campaign, array $data): void
    {
        foreach (['IMPRESSIONS' => 'impression_goal', 'CLICKS' => 'click_goal'] as $type => $field) {
            if (! array_key_exists($field, $data)) continue;
            $value = (int) ($data[$field] ?? 0);
            if ($value > 0) CampaignGoal::withoutGlobalScopes()->updateOrCreate(['campaign_id' => $campaign->id, 'goal_type' => $type], ['organization_id' => $campaign->organization_id, 'target_value' => $value]);
            else CampaignGoal::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('goal_type', $type)->delete();
        }
    }

    private function syncTargets(Campaign $campaign, array $data): void
    {
        foreach (['COUNTRY' => 'countries', 'DEVICE' => 'devices'] as $dimension => $field) {
            if (! array_key_exists($field, $data)) continue;
            $values = collect((array) $data[$field])->map(fn ($value) => strtoupper(trim((string) $value)))->filter()->unique()->values()->all();
            if ($values) CampaignTarget::withoutGlobalScopes()->updateOrCreate(['campaign_id' => $campaign->id, 'dimension' => $dimension, 'operator' => 'INCLUDE'], ['organization_id' => $campaign->organization_id, 'values' => $values]);
            else CampaignTarget::withoutGlobalScopes()->where('campaign_id', $campaign->id)->where('dimension', $dimension)->delete();
        }
    }

    private function syncInventory(Campaign $campaign, array $siteIds, array $placementIds): void
    {
        $sites = Site::withoutGlobalScopes()->with('publisher')->whereIn('id', array_values(array_unique($siteIds)))->get();
        if ($sites->count() !== count(array_unique($siteIds))) throw ValidationException::withMessages(['site_ids' => 'One or more selected websites do not exist.']);

        CampaignPlacement::withoutGlobalScopes()->where('campaign_id', $campaign->id)->delete();
        CampaignSite::withoutGlobalScopes()->where('campaign_id', $campaign->id)->delete();
        $siteMap = [];
        foreach ($sites as $site) {
            $row = CampaignSite::withoutGlobalScopes()->create([
                'organization_id' => $campaign->organization_id,
                'campaign_id' => $campaign->id,
                'publisher_id' => $site->publisher_id,
                'site_id' => $site->id,
                'budget_weight' => 1,
                'is_active' => true,
            ]);
            $siteMap[$site->id] = $row;
        }

        if ($placementIds === []) return;
        $placements = Placement::withoutGlobalScopes()->whereIn('id', array_values(array_unique($placementIds)))->get();
        foreach ($placements as $placement) {
            if (! isset($siteMap[$placement->site_id])) throw ValidationException::withMessages(['placement_ids' => 'Every selected placement must belong to a selected website.']);
            CampaignPlacement::withoutGlobalScopes()->create([
                'organization_id' => $campaign->organization_id,
                'campaign_id' => $campaign->id,
                'campaign_site_id' => $siteMap[$placement->site_id]->id,
                'placement_id' => $placement->id,
                'is_active' => true,
            ]);
        }
    }

    private function log(Campaign $campaign, User $actor, string $action, ?string $from = null, ?string $to = null, ?string $reason = null, ?CampaignCreative $creative = null, array $metadata = []): void
    {
        CampaignApprovalLog::withoutGlobalScopes()->create([
            'organization_id' => $campaign->organization_id,
            'campaign_id' => $campaign->id,
            'campaign_creative_id' => $creative?->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
