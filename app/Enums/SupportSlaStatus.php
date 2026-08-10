<?php

namespace App\Enums;

enum SupportSlaStatus: string
{
    case OnTrack = 'ON_TRACK';
    case Approaching = 'APPROACHING';
    case Breached = 'BREACHED';
    case Met = 'MET';
    case Paused = 'PAUSED';
    case NotApplicable = 'NOT_APPLICABLE';
}
