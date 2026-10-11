<?php

namespace App\Enums;

enum SellerIdentityScope: string
{
    case Publisher = 'PUBLISHER';
    case Website = 'WEBSITE';
    case MobileApp = 'MOBILE_APP';
}
