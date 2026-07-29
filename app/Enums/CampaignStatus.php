<?php

namespace App\Enums;

enum CampaignStatus: string
{
    case Draft = 'DRAFT';
    case PendingReview = 'PENDING_REVIEW';
    case Approved = 'APPROVED';
    case Scheduled = 'SCHEDULED';
    case Active = 'ACTIVE';
    case Paused = 'PAUSED';
    case Completed = 'COMPLETED';
    case Rejected = 'REJECTED';
    case Archived = 'ARCHIVED';
}
