<?php

namespace App\Enums;

enum ConfigVersionStatus: string
{
    case Draft = 'DRAFT';
    case PendingDelivery = 'PENDING_DELIVERY';
    case Deployed = 'DEPLOYED';
    case DeliveryFailed = 'DELIVERY_FAILED';
    case Superseded = 'SUPERSEDED';
    case Published = 'PUBLISHED';
    case RolledBack = 'ROLLED_BACK';
}
