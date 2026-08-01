<?php

namespace App\Services\StaticDelivery\Contracts;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Data\StaticDeliveryResult;

interface StaticDeliveryStatusProbeInterface
{
    public function probe(StaticDeliveryBatch $batch): ?StaticDeliveryResult;
}
