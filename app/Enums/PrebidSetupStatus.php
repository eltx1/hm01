<?php

namespace App\Enums;

enum PrebidSetupStatus: string
{
    case Preview = 'PREVIEW';
    case Confirmed = 'CONFIRMED';
    case Running = 'RUNNING';
    case PartiallySucceeded = 'PARTIALLY_SUCCEEDED';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Cancelled = 'CANCELLED';
}
