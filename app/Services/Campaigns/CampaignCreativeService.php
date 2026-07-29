<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignCreativeStatus;
use App\Enums\CampaignCreativeType;
use App\Models\Campaign;
use App\Models\CampaignApprovalLog;
use App\Models\CampaignCreative;
use App\Models\CreativeFile;
use App\Models\User;
use App\Services\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class CampaignCreativeService
{
    public function __construct(
        private readonly CreativeValidationService $validator,
        private readonly AuditRecorder $audit,
    ) {
    }

    public function create(Campaign $campaign, array $data, ?UploadedFile $file, User $actor, ?CampaignCreative $replaces = null): CampaignCreative
    {
        $type = CampaignCreativeType::from((string) $data['type']);
        $validated = $this->validator->validateAndStore($campaign, $type, $data, $file);

        try {
            return DB::transaction(function () use ($campaign, $validated, $actor, $replaces): CampaignCreative {
                if ($replaces) {
                    $replaces->update(['status' => CampaignCreativeStatus::Replaced, 'is_active' => false]);
                }
                $creative = CampaignCreative::withoutGlobalScopes()->create(array_merge($validated['creative'], [
                    'organization_id' => $campaign->organization_id,
                    'campaign_id' => $campaign->id,
                    'replaces_creative_id' => $replaces?->id,
                    'status' => $campaign->submitted_at ? CampaignCreativeStatus::PendingReview : CampaignCreativeStatus::Draft,
                    'is_active' => true,
                ]));
                if ($validated['file']) {
                    CreativeFile::withoutGlobalScopes()->create(array_merge($validated['file'], [
                        'organization_id' => $campaign->organization_id,
                        'campaign_creative_id' => $creative->id,
                    ]));
                }
                CampaignApprovalLog::withoutGlobalScopes()->create([
                    'organization_id' => $campaign->organization_id,
                    'campaign_id' => $campaign->id,
                    'campaign_creative_id' => $creative->id,
                    'actor_id' => $actor->id,
                    'action' => $replaces ? 'CREATIVE_REPLACED' : 'CREATIVE_UPLOADED',
                    'metadata' => ['replaces_creative_id' => $replaces?->id],
                    'created_at' => now(),
                ]);
                $this->audit->record($replaces ? 'campaign.creative.replaced' : 'campaign.creative.created', $campaign->organization_id, $actor, $creative, [], $creative->toArray());
                return $creative->fresh('files');
            });
        } catch (Throwable $exception) {
            if ($validated['file']) Storage::disk($validated['file']['disk'])->delete($validated['file']['path']);
            throw $exception;
        }
    }
}
