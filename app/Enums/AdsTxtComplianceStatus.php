<?php

namespace App\Enums;

enum AdsTxtComplianceStatus: string
{
    case Compliant = 'COMPLIANT';
    case Partial = 'PARTIAL';
    case Missing = 'MISSING';
    case Invalid = 'INVALID';
    case Conflict = 'CONFLICT';
    case Stale = 'STALE';
    case Unreachable = 'UNREACHABLE';
    case NotConfigured = 'NOT_CONFIGURED';
}
