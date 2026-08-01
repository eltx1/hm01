<?php

namespace App\Enums;

enum StaticDeliveryStatus: string
{
    case Pending = 'PENDING';
    case Batching = 'BATCHING';
    case Uploading = 'UPLOADING';
    case Deployed = 'DEPLOYED';
    case Failed = 'FAILED';
    case RetryScheduled = 'RETRY_SCHEDULED';
    case Superseded = 'SUPERSEDED';
}
