<?php

namespace App\Enums;

enum PlacementDevice: string
{
    case All = 'ALL';
    case Desktop = 'DESKTOP';
    case Tablet = 'TABLET';
    case Mobile = 'MOBILE';
}
