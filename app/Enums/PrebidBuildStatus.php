<?php

namespace App\Enums;

enum PrebidBuildStatus: string
{
    case Planned = 'PLANNED';
    case Building = 'BUILDING';
    case Ready = 'READY';
    case Failed = 'FAILED';
    case Retired = 'RETIRED';
}
