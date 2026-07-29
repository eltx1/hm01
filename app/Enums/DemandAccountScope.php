<?php

namespace App\Enums;

enum DemandAccountScope: string
{
    case HorusMedia = 'HORUS_MEDIA';
    case Publisher = 'PUBLISHER';
    case McmPartner = 'MCM_PARTNER';
}
