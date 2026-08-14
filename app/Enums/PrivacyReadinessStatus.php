<?php

namespace App\Enums;

enum PrivacyReadinessStatus: string
{
    case Ready = 'READY';
    case Warning = 'WARNING';
    case Blocked = 'BLOCKED';
    case Unknown = 'UNKNOWN';
    case Stale = 'STALE';
    case NotApplicable = 'NOT_APPLICABLE';
}
