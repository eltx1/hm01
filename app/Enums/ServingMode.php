<?php

namespace App\Enums;

enum ServingMode: string
{
    case HorusGam = 'HORUS_GAM';
    case HorusDirect = 'HORUS_DIRECT';
    case McmPartnerGam = 'MCM_PARTNER_GAM';
    case PublisherGam = 'PUBLISHER_GAM';
    case DirectNativeOnly = 'DIRECT_NATIVE_ONLY';
    case Paused = 'PAUSED';
}
