<?php

namespace App\Enums;

enum DemandSyncStatus: string
{
    case Pending = 'PENDING';
    case DryRun = 'DRY_RUN';
    case InSync = 'IN_SYNC';
    case Paused = 'PAUSED';
    case Failed = 'FAILED';
    case Drifted = 'DRIFTED';
}
