<?php

namespace App\Enums;

enum GamConnectionType: string
{
    case HorusGam = 'HORUS_GAM';
    case McmPartnerGam = 'MCM_PARTNER_GAM';
    case PublisherGam = 'PUBLISHER_GAM';
}
