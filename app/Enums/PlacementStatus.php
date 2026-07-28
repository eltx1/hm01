<?php

namespace App\Enums;

enum PlacementStatus: string
{
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Disabled = 'DISABLED';
}
