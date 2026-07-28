<?php

namespace App\Enums;

enum GamSyncStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case PartiallySucceeded = 'PARTIALLY_SUCCEEDED';
    case Failed = 'FAILED';
    case DryRun = 'DRY_RUN';
}
