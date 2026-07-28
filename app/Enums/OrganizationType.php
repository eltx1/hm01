<?php

namespace App\Enums;

enum OrganizationType: string
{
    case HorusMedia = 'HORUS_MEDIA';
    case Publisher = 'PUBLISHER';
    case Advertiser = 'ADVERTISER';
    case Partner = 'PARTNER';
}
