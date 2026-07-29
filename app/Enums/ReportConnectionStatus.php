<?php

namespace App\Enums;

enum ReportConnectionStatus: string
{
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Error = 'ERROR';
    case Disabled = 'DISABLED';
}
