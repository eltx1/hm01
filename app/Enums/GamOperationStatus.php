<?php

namespace App\Enums;

enum GamOperationStatus: string
{
    case Pending = 'PENDING';
    case DryRun = 'DRY_RUN';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Duplicate = 'DUPLICATE';
}
