<?php

namespace App\Enums;

enum TrafficGateSitePolicy: string
{
    case Inherit = 'INHERIT';
    case Strict = 'STRICT';
    case Balanced = 'BALANCED';
    case Permissive = 'PERMISSIVE';
}
