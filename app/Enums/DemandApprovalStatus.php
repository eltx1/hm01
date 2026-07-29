<?php

namespace App\Enums;

enum DemandApprovalStatus: string
{
    case NotSubmitted = 'NOT_SUBMITTED';
    case Pending = 'PENDING';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Suspended = 'SUSPENDED';
}
