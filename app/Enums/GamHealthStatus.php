<?php

namespace App\Enums;

enum GamHealthStatus: string
{
    case Unknown = 'UNKNOWN';
    case Healthy = 'HEALTHY';
    case Degraded = 'DEGRADED';
    case Failed = 'FAILED';
    case Disabled = 'DISABLED';
}
