<?php

namespace App\Enums;

enum SupplyChainReviewStatus: string
{
    case ReviewRequired = 'REVIEW_REQUIRED';
    case Verified = 'VERIFIED';
    case Rejected = 'REJECTED';
}
