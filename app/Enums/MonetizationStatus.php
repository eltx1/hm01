<?php

namespace App\Enums;

enum MonetizationStatus: string
{
    case Active = 'ACTIVE';
    case Ready = 'READY';
    case ActionRequired = 'ACTION_REQUIRED';
    case Pending = 'PENDING';
    case Paused = 'PAUSED';
    case Degraded = 'DEGRADED';
    case NotConfigured = 'NOT_CONFIGURED';
}
