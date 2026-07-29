<?php

namespace App\Enums;

enum CampaignCreativeStatus: string
{
    case Draft = 'DRAFT';
    case PendingReview = 'PENDING_REVIEW';
    case Approved = 'APPROVED';
    case Rejected = 'REJECTED';
    case Replaced = 'REPLACED';
}
