<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignDeliveryCapabilityStatus;

final readonly class CampaignDeliveryCapabilityResult
{
    /**
     * @param  array<int, array{code: string, message: string, site_id?: string|null, connection_id?: string|null}>  $reasons
     * @param  array<int, array<string, mixed>>  $networks
     */
    public function __construct(
        public CampaignDeliveryCapabilityStatus $status,
        public array $reasons = [],
        public array $networks = [],
        public string $backend = 'GAM',
    ) {
    }

    public function available(): bool
    {
        return $this->status === CampaignDeliveryCapabilityStatus::Available;
    }

    public function draftAllowed(): bool
    {
        return $this->status !== CampaignDeliveryCapabilityStatus::CampaignFeatureDisabled;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'backend' => $this->backend,
            'available' => $this->available(),
            'draft_allowed' => $this->draftAllowed(),
            'reasons' => $this->reasons,
            'networks' => $this->networks,
        ];
    }

    /** @return array<string, mixed> */
    public function forCustomer(): array
    {
        return [
            'status' => $this->available() ? 'READY' : ($this->draftAllowed() ? 'DRAFT_ONLY' : 'UNAVAILABLE'),
            'available' => $this->available(),
            'draft_allowed' => $this->draftAllowed(),
            'message' => $this->available()
                ? 'Campaign delivery is ready.'
                : ($this->draftAllowed()
                    ? 'Campaign delivery is temporarily unavailable. You may save this campaign as a draft.'
                    : 'Campaign delivery is currently unavailable.'),
        ];
    }
}
