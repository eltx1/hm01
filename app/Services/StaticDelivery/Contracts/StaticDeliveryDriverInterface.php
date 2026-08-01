<?php

namespace App\Services\StaticDelivery\Contracts;

use App\Models\StaticDeliveryBatch;
use App\Services\StaticDelivery\Data\StaticDeliveryResult;
use App\Services\StaticDelivery\Data\StaticDeliverySnapshot;

interface StaticDeliveryDriverInterface
{
    public function name(): string;

    public function deliver(StaticDeliverySnapshot $snapshot, StaticDeliveryBatch $batch): StaticDeliveryResult;
}
