<?php

namespace App\Enums;

enum TrafficGateReadiness: string
{
    case Ready = 'READY';
    case Disabled = 'DISABLED';
    case ConfigurationRequired = 'CONFIGURATION_REQUIRED';
    case InvalidConfiguration = 'INVALID_CONFIGURATION';
}
