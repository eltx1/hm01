<?php

namespace App\Enums;

enum StaticDeliveryManualOutcome: string
{
    case Processed = 'PROCESSED';
    case NoPending = 'NO_PENDING';
    case Busy = 'BUSY';
}
