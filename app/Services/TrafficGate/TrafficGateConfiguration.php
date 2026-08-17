<?php

namespace App\Services\TrafficGate;

use App\Enums\TrafficGatePolicy;
use App\Enums\TrafficGateReadiness;

final readonly class TrafficGateConfiguration
{
    public function __construct(
        public bool $enabled,
        public string $provider,
        public string $gateOrigin,
        public ?string $siteKey,
        public TrafficGatePolicy $policy,
        public int $initialWaitMs,
        public int $maxWaitMs,
        public int $retryIntervalMs,
        public bool $activityRecoveryEnabled,
        public TrafficGateReadiness $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'provider' => $this->provider,
            'gateOrigin' => $this->gateOrigin,
            'siteKey' => $this->siteKey,
            'policy' => $this->policy->value,
            'timings' => [
                'initialWaitMs' => $this->initialWaitMs,
                'maxWaitMs' => $this->maxWaitMs,
                'retryIntervalMs' => $this->retryIntervalMs,
            ],
            'activityRecoveryEnabled' => $this->activityRecoveryEnabled,
            'readiness' => $this->readiness->value,
        ];
    }
}
