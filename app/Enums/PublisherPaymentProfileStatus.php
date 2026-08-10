<?php

namespace App\Enums;

enum PublisherPaymentProfileStatus: string
{
    case Incomplete = 'INCOMPLETE';
    case PendingVerification = 'PENDING_VERIFICATION';
    case Verified = 'VERIFIED';
    case Rejected = 'REJECTED';
    case NeedsUpdate = 'NEEDS_UPDATE';
}
