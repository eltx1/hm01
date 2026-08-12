<?php

namespace App\Services\Serving;

use App\Enums\PrebidConfiguredMode;
use App\Enums\PrebidDeliveryMode;
use App\Models\GamConnection;

final readonly class ResolvedSiteEngineState
{
    public function __construct(
        public bool $masterServingEnabled,
        public bool $paused,
        public ?GamConnection $gamConnection,
        public bool $gamRequired,
        public bool $gamEnabled,
        public string $gamReason,
        public bool $prebidEnabled,
        public PrebidConfiguredMode $prebidConfiguredMode,
        public PrebidDeliveryMode $prebidDeliveryMode,
        public string $prebidReason,
        public bool $directJsEnabled,
        public string $directJsReason,
    ) {}

    /** @return array<string, mixed> */
    public function publicEngineState(?string $networkCode = null): array
    {
        return [
            'gam' => [
                'enabled' => $this->gamEnabled,
                'mode' => $this->gamRequired ? 'GAM' : 'NONE',
                'networkCode' => $this->gamEnabled ? $networkCode : null,
                'reason' => $this->gamReason,
            ],
            'prebid' => [
                'enabled' => $this->prebidEnabled,
                'configuredMode' => $this->prebidConfiguredMode->value,
                'deliveryMode' => $this->prebidDeliveryMode->value,
                'reason' => $this->prebidReason,
            ],
            'directJs' => [
                'enabled' => $this->directJsEnabled,
                'reason' => $this->directJsReason,
            ],
        ];
    }
}
