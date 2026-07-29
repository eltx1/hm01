<?php

namespace App\Enums;

enum ReconciliationStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Matched = 'MATCHED';
    case Warning = 'WARNING';
    case Failed = 'FAILED';
}
