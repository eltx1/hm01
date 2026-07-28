<?php

namespace App\Enums;

enum GamErrorCategory: string
{
    case Authentication = 'AUTHENTICATION';
    case Permission = 'PERMISSION';
    case Validation = 'VALIDATION';
    case Quota = 'QUOTA';
    case RateLimit = 'RATE_LIMIT';
    case Network = 'NETWORK';
    case Upstream = 'UPSTREAM';
    case Configuration = 'CONFIGURATION';
    case Unknown = 'UNKNOWN';
}
