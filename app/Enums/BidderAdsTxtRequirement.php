<?php

namespace App\Enums;

enum BidderAdsTxtRequirement: string
{
    case Unknown = 'UNKNOWN';
    case Required = 'REQUIRED';
    case NotRequired = 'NOT_REQUIRED';
}
