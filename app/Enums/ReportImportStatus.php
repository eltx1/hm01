<?php

namespace App\Enums;

enum ReportImportStatus: string
{
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Duplicate = 'DUPLICATE';
    case BlockedClosedPeriod = 'BLOCKED_CLOSED_PERIOD';
}
