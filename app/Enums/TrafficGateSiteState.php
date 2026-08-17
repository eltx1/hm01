<?php

namespace App\Enums;

enum TrafficGateSiteState: string
{
    case Inherit = 'INHERIT';
    case Enabled = 'ENABLED';
    case Disabled = 'DISABLED';
}
