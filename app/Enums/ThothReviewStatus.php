<?php

namespace App\Enums;

enum ThothReviewStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case TimedOut = 'TIMED_OUT';
    case Unavailable = 'UNAVAILABLE';
}
