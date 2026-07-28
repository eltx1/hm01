<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Draft = 'DRAFT';
    case PendingVerification = 'PENDING_VERIFICATION';
    case PendingReview = 'PENDING_REVIEW';
    case Approved = 'APPROVED';
    case Active = 'ACTIVE';
    case Rejected = 'REJECTED';
    case Suspended = 'SUSPENDED';
    case Archived = 'ARCHIVED';
}
