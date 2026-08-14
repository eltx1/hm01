<?php

namespace App\Services\StaticDelivery\Data;

use App\Enums\StaticDeliveryManualOutcome;
use App\Models\StaticDeliveryBatch;

final readonly class StaticDeliveryManualResult
{
    public function __construct(
        public StaticDeliveryManualOutcome $outcome,
        public ?StaticDeliveryBatch $batch = null,
        public int $acceleratedItems = 0,
    ) {}
}
