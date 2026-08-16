<?php

namespace App\Enums;

enum SellerIdentitySource: string
{
    case HorusManaged = 'HORUS_MANAGED';
    case Manual = 'MANUAL';
}
