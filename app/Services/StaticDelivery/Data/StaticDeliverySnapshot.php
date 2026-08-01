<?php

namespace App\Services\StaticDelivery\Data;

final readonly class StaticDeliverySnapshot
{
    /** @param array<string, string> $files */
    public function __construct(
        public array $files,
        public string $manifestHash,
        public int $totalBytes,
        public bool $nearFileBudget,
    ) {
    }
}
