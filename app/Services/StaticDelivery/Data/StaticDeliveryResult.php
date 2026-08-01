<?php

namespace App\Services\StaticDelivery\Data;

final readonly class StaticDeliveryResult
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $remoteId,
        public ?string $remoteUrl,
        public bool $confirmedDeployed,
        public array $metadata = [],
    ) {
    }
}
