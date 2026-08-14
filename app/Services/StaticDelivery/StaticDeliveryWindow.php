<?php

namespace App\Services\StaticDelivery;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class StaticDeliveryWindow
{
    public function nextNormalBoundary(?CarbonInterface $at = null): CarbonImmutable
    {
        $instant = CarbonImmutable::instance($at ?? now())->utc();
        $minutes = (int) config('static-delivery.normal_batch_interval_minutes', 30);
        if ($minutes <= 0) {
            return $instant;
        }

        $seconds = $minutes * 60;
        $nextTimestamp = (intdiv($instant->getTimestamp(), $seconds) + 1) * $seconds;

        return CarbonImmutable::createFromTimestampUTC($nextTimestamp);
    }
}
