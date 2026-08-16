<?php

namespace App\Enums;

enum BidderSellersJsonStatus: string
{
    case Unverified = 'UNVERIFIED';
    case Verified = 'VERIFIED';
    case Stale = 'STALE';
    case Unreachable = 'UNREACHABLE';
    case Conflict = 'CONFLICT';
}
